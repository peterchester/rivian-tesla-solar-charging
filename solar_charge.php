#!/usr/bin/env php
<?php
/**
 * Solar-Only Rivian Charging Controller
 *
 * Monitors your Tesla Powerwall 3's solar production and surplus energy
 * via the Tesla cloud API, then dynamically adjusts your Rivian's
 * charging amperage through the Rivian Wall Charger so the truck only
 * charges when the sun is producing adequate power.
 *
 * How it works:
 *   1. Polls the Tesla cloud API for solar production, grid export, battery state
 *   2. Calculates surplus solar (energy going to grid that could charge the truck)
 *   3. Converts surplus watts to amps and updates the Rivian charging schedule
 *   4. If surplus is below the minimum threshold, disables charging
 *   5. Prioritizes home battery: won't divert solar until Powerwall is above threshold
 *
 * Requirements:
 *   - PHP 8.0+ with curl extension
 *   - Tesla account (for Powerwall 3 cloud API access)
 *   - Rivian account credentials
 *   - Rivian Wall Charger registered to your account
 *
 * Initial Setup:
 *   1. Generate config:       php solar_charge.php
 *      (creates config.json template, edit it to add your Rivian email and password)
 *
 *   2. Tesla setup:           php solar_charge.php --tesla-setup
 *      (opens browser for Tesla OAuth login, auto-writes refresh token and site_id)
 *
 *   3. Rivian setup:          php solar_charge.php --rivian-setup
 *      (authenticates with Rivian via MFA, auto-writes vehicle_id and
 *       home coordinates; optionally detects Wall Charger for status monitoring)
 *
 *   4. Verify:                php solar_charge.php --status
 *      (shows live solar data, charging decision, and Wall Charger status)
 *
 * Running:
 *   - Single run:             php solar_charge.php
 *   - Daemon mode:            php solar_charge.php --daemon
 *   - Recommended: run via cron every 5 minutes, or use --daemon mode.
 *     On macOS, use a launchd plist for automatic scheduling.
 *
 * Notes:
 *   - Tesla tokens auto-refresh (~8 hour lifespan, handled transparently)
 *   - Rivian sessions last ~7 days; regular polling keeps them alive
 *   - The Rivian API is unofficial and may change with app updates
 *
 * Authors:
 *   Peter Chester (@peterchester)
 *   Claude (Anthropic) - AI pair programmer
 *
 * License: MIT
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const CONFIG_FILE    = __DIR__ . '/config.json';
const SESSION_FILE   = __DIR__ . '/rivian_session.json';
const TESLA_TOKEN_FILE = __DIR__ . '/tesla_token.json';
const LOG_FILE       = __DIR__ . '/solar_charge.log';
const STATE_FILE     = __DIR__ . '/charge_state.json';

const RIVIAN_GQL_URL      = 'https://rivian.com/api/gql/gateway/graphql';
const RIVIAN_CHRG_GQL_URL = 'https://rivian.com/api/gql/chrg/user/graphql';
const TESLA_API_BASE      = 'https://owner-api.teslamotors.com';
const TESLA_AUTH_URL      = 'https://auth.tesla.com';

// Voltage for amp calculation (240V for Level 2 in the US)
const CHARGER_VOLTAGE = 240;

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------

function logMsg(string $level, string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$level] $message\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);

    if (in_array($level, ['ERROR', 'INFO'])) {
        echo $line;
    }
}

// ---------------------------------------------------------------------------
// Config loader
// ---------------------------------------------------------------------------

function loadConfig(): array
{
    if (!file_exists(CONFIG_FILE)) {
        // Create example config and exit
        $example = [
            'tesla' => [
                'refresh_token' => 'YOUR_TESLA_REFRESH_TOKEN',
                'site_id'       => 'YOUR_ENERGY_SITE_ID',
            ],
            'rivian' => [
                'email'    => 'your-rivian-email@example.com',
                'password' => 'your-rivian-password',
                'vehicle_id' => 'YOUR_VEHICLE_ID',
            ],
            'charging' => [
                'home_latitude'      => 37.0000,
                'home_longitude'     => -122.0000,
                'min_solar_watts'    => 1200,
                'min_amps'           => 8,
                'max_amps'           => 48,
                'battery_soe_threshold' => 90,
                'poll_interval_seconds' => 300,
                'week_days' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
            ],
        ];
        file_put_contents(CONFIG_FILE, json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        logMsg('ERROR', "No config.json found. A template has been created at " . CONFIG_FILE);
        logMsg('ERROR', "Please edit it with your credentials and settings, then re-run.");
        exit(1);
    }

    $config = json_decode(file_get_contents(CONFIG_FILE), true);
    if (!$config) {
        logMsg('ERROR', "Failed to parse config.json");
        exit(1);
    }
    return $config;
}

// ---------------------------------------------------------------------------
// HTTP helper
// ---------------------------------------------------------------------------

function httpRequest(string $url, string $method = 'GET', ?string $body = null, array $headers = [], bool $verifySsl = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER         => true,
    ]);

    if (!$verifySsl) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error      = curl_error($ch);
    // curl_close() is a no-op since PHP 8.0 and deprecated since 8.5;
    // the handle is freed automatically when it goes out of scope.

    if ($response === false) {
        return ['code' => 0, 'headers' => '', 'body' => '', 'error' => $error];
    }

    $respHeaders = substr($response, 0, $headerSize);
    $respBody    = substr($response, $headerSize);

    return ['code' => $httpCode, 'headers' => $respHeaders, 'body' => $respBody, 'error' => $error];
}

// ===========================================================================
// TESLA CLOUD API FUNCTIONS (for Powerwall 3)
// ===========================================================================

/**
 * Load saved Tesla access token from disk.
 */
function teslaLoadToken(): ?array
{
    if (!file_exists(TESLA_TOKEN_FILE)) {
        return null;
    }
    $data = json_decode(file_get_contents(TESLA_TOKEN_FILE), true);
    if (!$data || empty($data['access_token'])) {
        return null;
    }
    // Check if token is expired (tokens last ~8 hours)
    if (isset($data['expires_at']) && time() >= $data['expires_at']) {
        logMsg('INFO', "Tesla access token expired, refreshing...");
        return teslaRefreshToken($data['refresh_token'] ?? '');
    }
    return $data;
}

/**
 * Save Tesla tokens to disk.
 */
function teslaSaveToken(array $tokenData): void
{
    file_put_contents(TESLA_TOKEN_FILE, json_encode($tokenData, JSON_PRETTY_PRINT));
    chmod(TESLA_TOKEN_FILE, 0600);
}

/**
 * Refresh the Tesla access token using a refresh token.
 * This uses the Tesla OAuth2 endpoint.
 */
function teslaRefreshToken(string $refreshToken): ?array
{
    if (empty($refreshToken)) {
        logMsg('ERROR', "No Tesla refresh token available");
        return null;
    }

    $body = json_encode([
        'grant_type'    => 'refresh_token',
        'client_id'     => 'ownerapi',
        'refresh_token' => $refreshToken,
        'scope'         => 'openid email offline_access',
    ]);

    $resp = httpRequest(
        TESLA_AUTH_URL . '/oauth2/v3/token',
        'POST',
        $body,
        ['Content-Type: application/json']
    );

    if ($resp['code'] !== 200) {
        logMsg('ERROR', "Tesla token refresh failed (HTTP {$resp['code']}): {$resp['body']}");
        return null;
    }

    $data = json_decode($resp['body'], true);
    if (empty($data['access_token'])) {
        logMsg('ERROR', "Tesla token refresh returned no access token");
        return null;
    }

    $tokenData = [
        'access_token'  => $data['access_token'],
        'refresh_token' => $data['refresh_token'] ?? $refreshToken,
        'expires_at'    => time() + ($data['expires_in'] ?? 28800),
    ];

    teslaSaveToken($tokenData);
    logMsg('INFO', "Tesla access token refreshed successfully");
    return $tokenData;
}

/**
 * Make an authenticated request to the Tesla Owners API.
 */
function teslaApiRequest(string $accessToken, string $endpoint): ?array
{
    $url = TESLA_API_BASE . $endpoint;
    $resp = httpRequest($url, 'GET', null, [
        "Authorization: Bearer $accessToken",
        'Content-Type: application/json',
    ]);

    if ($resp['code'] === 401) {
        logMsg('ERROR', "Tesla API returned 401. Token may be invalid. Try running --tesla-setup.");
        return null;
    }

    if ($resp['code'] !== 200) {
        logMsg('ERROR', "Tesla API request failed (HTTP {$resp['code']}): {$resp['body']}");
        return null;
    }

    return json_decode($resp['body'], true);
}

/**
 * Get live status from Tesla Energy site (solar, grid, battery, load).
 * Works with Powerwall 3 via cloud API.
 */
function teslaGetLiveStatus(string $accessToken, string $siteId): ?array
{
    $data = teslaApiRequest($accessToken, "/api/1/energy_sites/{$siteId}/live_status");
    $response = $data['response'] ?? null;

    if (!$response) {
        logMsg('ERROR', "Failed to get Tesla live status");
        return null;
    }

    return [
        'solar_w'   => $response['solar_power'] ?? 0,
        'grid_w'    => $response['grid_power'] ?? 0,        // negative = exporting
        'battery_w' => $response['battery_power'] ?? 0,     // negative = charging
        'load_w'    => $response['load_power'] ?? 0,
        'battery_pct' => $response['percentage_charged'] ?? 0,
    ];
}

/**
 * Get Tesla energy site list to find your site_id.
 */
function teslaGetProducts(string $accessToken): ?array
{
    return teslaApiRequest($accessToken, '/api/1/products');
}

/**
 * Interactive OAuth login flow for Tesla.
 * Generates an auth URL, opens the browser, and exchanges the callback code for tokens.
 */
function teslaOAuthLogin(): ?array
{
    // Generate PKCE code verifier and challenge
    $codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    $state = bin2hex(random_bytes(16));

    $params = http_build_query([
        'client_id'             => 'ownerapi',
        'code_challenge'        => $codeChallenge,
        'code_challenge_method' => 'S256',
        'redirect_uri'          => 'https://auth.tesla.com/void/callback',
        'response_type'         => 'code',
        'scope'                 => 'openid email offline_access',
        'state'                 => $state,
    ]);

    $authUrl = TESLA_AUTH_URL . '/oauth2/v3/authorize?' . $params;

    echo "Opening your browser to Tesla's login page...\n\n";

    // Try to open browser (macOS)
    $opened = false;
    if (PHP_OS_FAMILY === 'Darwin') {
        exec('open ' . escapeshellarg($authUrl) . ' 2>/dev/null', $output, $exitCode);
        $opened = ($exitCode === 0);
    } elseif (PHP_OS_FAMILY === 'Linux') {
        exec('xdg-open ' . escapeshellarg($authUrl) . ' 2>/dev/null', $output, $exitCode);
        $opened = ($exitCode === 0);
    }

    if (!$opened) {
        echo "Could not open browser automatically. Please open this URL manually:\n\n";
        echo $authUrl . "\n\n";
    }

    echo "Steps:\n";
    echo "  1. Log in with your Tesla account in the browser\n";
    echo "  2. Complete MFA if prompted\n";
    echo "  3. You'll be redirected to a \"Page Not Found\" page\n";
    echo "  4. Copy the ENTIRE URL from your browser's address bar\n";
    echo "     (it will start with https://auth.tesla.com/void/callback?code=...)\n\n";
    echo "Paste the callback URL here: ";

    $callbackUrl = trim(fgets(STDIN));

    if (empty($callbackUrl)) {
        logMsg('ERROR', "No URL provided.");
        return null;
    }

    // Extract the code from the callback URL
    $urlParts = parse_url($callbackUrl);
    parse_str($urlParts['query'] ?? '', $queryParams);
    $authCode = $queryParams['code'] ?? null;

    if (empty($authCode)) {
        logMsg('ERROR', "Could not extract authorization code from URL.");
        logMsg('ERROR', "Make sure you copied the full URL including the ?code= parameter.");
        return null;
    }

    // Verify state matches
    $returnedState = $queryParams['state'] ?? '';
    if ($returnedState !== $state) {
        logMsg('ERROR', "OAuth state mismatch. Possible CSRF attack or stale URL. Please try again.");
        return null;
    }

    logMsg('INFO', "Authorization code received, exchanging for tokens...");

    // Exchange the code for tokens
    $body = json_encode([
        'grant_type'    => 'authorization_code',
        'client_id'     => 'ownerapi',
        'code'          => $authCode,
        'code_verifier' => $codeVerifier,
        'redirect_uri'  => 'https://auth.tesla.com/void/callback',
    ]);

    $resp = httpRequest(
        TESLA_AUTH_URL . '/oauth2/v3/token',
        'POST',
        $body,
        ['Content-Type: application/json']
    );

    if ($resp['code'] !== 200) {
        logMsg('ERROR', "Token exchange failed (HTTP {$resp['code']}): {$resp['body']}");
        return null;
    }

    $data = json_decode($resp['body'], true);
    if (empty($data['access_token'])) {
        logMsg('ERROR', "Token exchange returned no access token.");
        return null;
    }

    $tokenData = [
        'access_token'  => $data['access_token'],
        'refresh_token' => $data['refresh_token'] ?? '',
        'expires_at'    => time() + ($data['expires_in'] ?? 28800),
    ];

    teslaSaveToken($tokenData);
    logMsg('INFO', "Tesla tokens obtained and saved successfully!");

    return $tokenData;
}

// ===========================================================================
// RIVIAN API FUNCTIONS
// ===========================================================================

function rivianLoadSession(): ?array
{
    if (!file_exists(SESSION_FILE)) {
        return null;
    }
    $data = json_decode(file_get_contents(SESSION_FILE), true);
    if (!$data || empty($data['u_sess'])) {
        return null;
    }
    // Check if session is expired (sessions last about 7 days)
    if (isset($data['created_at']) && (time() - $data['created_at']) > 86400 * 6) {
        logMsg('INFO', "Rivian session expired, re-authentication needed");
        return null;
    }
    return $data;
}

function rivianSaveSession(array $session): void
{
    $session['created_at'] = time();
    file_put_contents(SESSION_FILE, json_encode($session, JSON_PRETTY_PRINT));
    chmod(SESSION_FILE, 0600);
}

function rivianGraphQL(array $session, array $payload): ?array
{
    $headers = [
        'Content-Type: application/json',
        "a-sess: {$session['a_sess']}",
        "u-sess: {$session['u_sess']}",
        "csrf-token: {$session['csrf_token']}",
        'apollographql-client-name: com.rivian.android.consumer',
    ];

    $resp = httpRequest(RIVIAN_GQL_URL, 'POST', json_encode($payload), $headers);

    if ($resp['code'] !== 200) {
        logMsg('ERROR', "Rivian GraphQL request failed (HTTP {$resp['code']}): {$resp['body']}");
        return null;
    }

    return json_decode($resp['body'], true);
}

/**
 * Authenticate with Rivian. Returns session tokens.
 * Step 1: Get CSRF token
 * Step 2: Login (may trigger MFA)
 */
function rivianAuthenticate(array $rivConfig): ?array
{
    // Step 1: CSRF token
    $csrfPayload = [
        'operationName' => 'CreateCSRFToken',
        'variables'     => [],
        'query'         => 'mutation CreateCSRFToken { createCsrfToken { __typename csrfToken appSessionToken } }',
    ];

    $resp = httpRequest(RIVIAN_GQL_URL, 'POST', json_encode($csrfPayload), ['Content-Type: application/json']);

    if ($resp['code'] !== 200) {
        logMsg('ERROR', "Rivian CSRF request failed (HTTP {$resp['code']})");
        return null;
    }

    $csrfData   = json_decode($resp['body'], true);
    $csrfToken  = $csrfData['data']['createCsrfToken']['csrfToken'] ?? null;
    $aSess      = $csrfData['data']['createCsrfToken']['appSessionToken'] ?? null;

    if (!$csrfToken || !$aSess) {
        logMsg('ERROR', "Failed to obtain Rivian CSRF token");
        return null;
    }

    // Step 2: Login
    $loginPayload = [
        'operationName' => 'Login',
        'variables'     => [
            'email'    => $rivConfig['email'],
            'password' => $rivConfig['password'],
        ],
        'query' => 'mutation Login($email: String!, $password: String!) { login(email: $email, password: $password) { __typename ... on MobileLoginResponse { accessToken refreshToken userSessionToken } ... on MobileMFALoginResponse { otpToken } } }',
    ];

    $loginHeaders = [
        'Content-Type: application/json',
        "a-sess: $aSess",
        "csrf-token: $csrfToken",
        'apollographql-client-name: com.rivian.android.consumer',
    ];

    $loginResp = httpRequest(RIVIAN_GQL_URL, 'POST', json_encode($loginPayload), $loginHeaders);
    $loginData = json_decode($loginResp['body'], true);

    $loginResult = $loginData['data']['login'] ?? null;

    if (!$loginResult) {
        logMsg('ERROR', "Rivian login failed: " . ($loginResp['body'] ?? 'unknown'));
        return null;
    }

    // Check if MFA is required
    if ($loginResult['__typename'] === 'MobileMFALoginResponse') {
        $otpToken = $loginResult['otpToken'];
        logMsg('INFO', "Rivian MFA required. Check your phone/email for the OTP code.");

        echo "Enter the OTP code sent to your phone/email: ";
        $otpCode = trim(fgets(STDIN));

        $otpPayload = [
            'operationName' => 'LoginWithOTP',
            'variables'     => [
                'email'    => $rivConfig['email'],
                'otpCode'  => $otpCode,
                'otpToken' => $otpToken,
            ],
            'query' => 'mutation LoginWithOTP($email: String!, $otpCode: String!, $otpToken: String!) { loginWithOTP(email: $email, otpCode: $otpCode, otpToken: $otpToken) { __typename accessToken refreshToken userSessionToken } }',
        ];

        $otpResp = httpRequest(RIVIAN_GQL_URL, 'POST', json_encode($otpPayload), $loginHeaders);
        $otpData = json_decode($otpResp['body'], true);

        $loginResult = $otpData['data']['loginWithOTP'] ?? null;

        if (!$loginResult || empty($loginResult['userSessionToken'])) {
            logMsg('ERROR', "Rivian OTP login failed");
            return null;
        }
    }

    $session = [
        'a_sess'     => $aSess,
        'u_sess'     => $loginResult['userSessionToken'],
        'csrf_token' => $csrfToken,
        'access_token' => $loginResult['accessToken'] ?? '',
        'refresh_token' => $loginResult['refreshToken'] ?? '',
    ];

    rivianSaveSession($session);
    logMsg('INFO', "Rivian authentication successful, session saved");

    return $session;
}

/**
 * Get the current Rivian charging schedule.
 */
function rivianGetChargingSchedule(array $session, string $vehicleId): ?array
{
    $payload = [
        'operationName' => 'GetChargingSchedule',
        'variables'     => ['vehicleId' => $vehicleId],
        'query'         => 'query GetChargingSchedule($vehicleId: String!) { getVehicle(id: $vehicleId) { chargingSchedules { startTime duration location { latitude longitude } amperage enabled weekDays } } }',
    ];

    $result = rivianGraphQL($session, $payload);
    return $result['data']['getVehicle']['chargingSchedules'] ?? null;
}

/**
 * Set the Rivian charging schedule.
 */
function rivianSetChargingSchedule(
    array  $session,
    string $vehicleId,
    int    $amperage,
    bool   $enabled,
    float  $lat,
    float  $lng,
    array  $weekDays
): bool {
    // Start immediately (current time of day as minutes after midnight)
    $now        = new DateTime();
    $startTime  = (int) $now->format('G') * 60 + (int) $now->format('i');
    $duration   = 1440; // 24 hours, effectively "always on when conditions met"

    $payload = [
        'operationName' => 'SetChargingSchedule',
        'variables'     => [
            'vehicleId'         => $vehicleId,
            'chargingSchedules' => [[
                'weekDays'  => $weekDays,
                'startTime' => $startTime,
                'duration'  => $duration,
                'location'  => [
                    'latitude'  => $lat,
                    'longitude' => $lng,
                ],
                'amperage' => $amperage,
                'enabled'  => $enabled,
            ]],
        ],
        'query' => 'mutation SetChargingSchedule($vehicleId: String!, $chargingSchedules: [InputChargingSchedule!]!) { setChargingSchedules(vehicleId: $vehicleId, chargingSchedules: $chargingSchedules) { success } }',
    ];

    $result = rivianGraphQL($session, $payload);
    $success = $result['data']['setChargingSchedules']['success'] ?? false;

    if ($success) {
        logMsg('INFO', "Charging schedule updated: {$amperage}A, enabled=" . ($enabled ? 'true' : 'false'));
    } else {
        logMsg('ERROR', "Failed to update charging schedule: " . json_encode($result));
    }

    return (bool) $success;
}

// ===========================================================================
// RIVIAN WALL CHARGER FUNCTIONS
// ===========================================================================

/**
 * Query the Rivian Wall Charger status via the charging GraphQL endpoint.
 * Returns charger info including chargingStatus, power, voltage, amps.
 */
function rivianGetWallboxStatus(array $session, string $wallboxId): ?array
{
    $headers = [
        'Content-Type: application/json',
        "a-sess: {$session['a_sess']}",
        "u-sess: {$session['u_sess']}",
        "csrf-token: {$session['csrf_token']}",
        'apollographql-client-name: com.rivian.android.consumer',
    ];

    $payload = [
        'operationName' => 'getWallboxStatus',
        'variables'     => ['wallboxId' => $wallboxId],
        'query'         => 'query getWallboxStatus($wallboxId: String!) { getWallboxStatus(wallboxId: $wallboxId) { __typename wallboxId userId wifiId name linked latitude longitude chargingStatus power currentVoltage currentAmps softwareVersion model serialNumber maxPower maxVoltage maxAmps } }',
    ];

    $resp = httpRequest(RIVIAN_CHRG_GQL_URL, 'POST', json_encode($payload), $headers);

    if ($resp['code'] !== 200) {
        logMsg('ERROR', "Wallbox status request failed (HTTP {$resp['code']})");
        return null;
    }

    $data = json_decode($resp['body'], true);
    return $data['data']['getWallboxStatus'] ?? null;
}

/**
 * Get live charging session data from the Wall Charger.
 */
function rivianGetLiveSession(array $session, string $vehicleId): ?array
{
    $headers = [
        'Content-Type: application/json',
        "a-sess: {$session['a_sess']}",
        "u-sess: {$session['u_sess']}",
        "csrf-token: {$session['csrf_token']}",
        'apollographql-client-name: com.rivian.android.consumer',
    ];

    $payload = [
        'operationName' => 'getLiveSessionData',
        'variables'     => ['vehicleId' => $vehicleId],
        'query'         => 'query getLiveSessionData($vehicleId: String!) { getLiveSessionData(vehicleId: $vehicleId) { isRivianCharger vehicleChargerState { value updatedAt } soc { value updatedAt } power { value updatedAt } timeRemaining { value updatedAt } kilometersChargedPerHour { value updatedAt } } }',
    ];

    $resp = httpRequest(RIVIAN_CHRG_GQL_URL, 'POST', json_encode($payload), $headers);

    if ($resp['code'] !== 200) {
        // 400 is normal when the vehicle isn't actively charging
        if ($resp['code'] !== 400) {
            logMsg('ERROR', "Live session request failed (HTTP {$resp['code']})");
        }
        return null;
    }

    $data = json_decode($resp['body'], true);
    return $data['data']['getLiveSessionData'] ?? null;
}

// ===========================================================================
// CHARGE STATE PERSISTENCE
// ===========================================================================

function loadChargeState(): array
{
    if (file_exists(STATE_FILE)) {
        $data = json_decode(file_get_contents(STATE_FILE), true);
        if ($data) return $data;
    }
    return ['last_amps' => 0, 'charging_enabled' => false, 'last_update' => 0];
}

function saveChargeState(array $state): void
{
    $state['last_update'] = time();
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

// ===========================================================================
// CORE LOGIC
// ===========================================================================

function calculateTargetAmps(array $meters, float $batterySoe, array $chargingConfig): int
{
    $solarW    = $meters['solar_w'];
    $gridW     = $meters['grid_w'];   // negative = exporting to grid
    $batteryW  = $meters['battery_w'];
    $loadW     = $meters['load_w'];

    logMsg('INFO', sprintf(
        "Solar: %.0fW | Grid: %.0fW | Battery: %.0fW (%.1f%%) | Home: %.0fW",
        $solarW, $gridW, $batteryW, $batterySoe, $loadW
    ));

    // If battery is below threshold, let solar charge the battery first
    if ($batterySoe < $chargingConfig['battery_soe_threshold']) {
        logMsg('INFO', sprintf(
            "Battery at %.1f%% (threshold: %d%%), prioritizing battery charging",
            $batterySoe, $chargingConfig['battery_soe_threshold']
        ));
        return 0;
    }

    // Surplus is the energy being exported to the grid (negative grid_w means export).
    // We also consider any solar going to the battery once it's above threshold.
    $surplusW = 0;

    if ($gridW < 0) {
        // We're exporting to grid, this is available surplus
        $surplusW += abs($gridW);
    }

    // If battery is full and still absorbing power, that's also available
    if ($batterySoe >= 99 && $batteryW < 0) {
        $surplusW += abs($batteryW);
    }

    logMsg('INFO', "Available surplus: {$surplusW}W");

    // Not enough surplus solar
    if ($surplusW < $chargingConfig['min_solar_watts']) {
        logMsg('INFO', "Surplus below minimum threshold ({$chargingConfig['min_solar_watts']}W)");
        return 0;
    }

    // Convert watts to amps: P = V * I, so I = P / V
    $targetAmps = (int) floor($surplusW / CHARGER_VOLTAGE);

    // Clamp to valid range
    $targetAmps = max($chargingConfig['min_amps'], min($chargingConfig['max_amps'], $targetAmps));

    logMsg('INFO', "Target charge rate: {$targetAmps}A ({$surplusW}W / " . CHARGER_VOLTAGE . "V)");

    return $targetAmps;
}

function runOnce(array $config): void
{
    $teslaConfig = $config['tesla'];
    $rivConfig   = $config['rivian'];
    $chgConfig   = $config['charging'];

    // ---- Tesla Cloud Data ----
    $teslaToken = teslaLoadToken();
    if (!$teslaToken) {
        // Try refreshing with the config's refresh token
        $teslaToken = teslaRefreshToken($teslaConfig['refresh_token']);
        if (!$teslaToken) {
            logMsg('ERROR', "Cannot authenticate with Tesla API. Run --tesla-setup.");
            return;
        }
    }

    $liveData = teslaGetLiveStatus($teslaToken['access_token'], $teslaConfig['site_id']);
    if (!$liveData) {
        logMsg('ERROR', "Cannot read Tesla energy data, skipping this cycle");
        return;
    }

    $meters     = $liveData;
    $batterySoe = $liveData['battery_pct'];

    // ---- Calculate Target ----
    $targetAmps = calculateTargetAmps($meters, $batterySoe, $chgConfig);
    $shouldCharge = $targetAmps > 0;

    // ---- Check if update is needed ----
    $state = loadChargeState();

    if ($state['last_amps'] === $targetAmps && $state['charging_enabled'] === $shouldCharge) {
        logMsg('INFO', "No change needed (currently {$targetAmps}A, " . ($shouldCharge ? 'enabled' : 'disabled') . ")");
        return;
    }

    // ---- Rivian Session ----
    $session = rivianLoadSession();
    if (!$session) {
        logMsg('INFO', "No valid Rivian session found, authenticating...");
        $session = rivianAuthenticate($rivConfig);
        if (!$session) {
            logMsg('ERROR', "Rivian authentication failed. Run with --rivian-setup for interactive login.");
            return;
        }
    }

    // ---- Update Charging Schedule ----
    $amps = $shouldCharge ? $targetAmps : $chgConfig['min_amps'];

    $success = rivianSetChargingSchedule(
        $session,
        $rivConfig['vehicle_id'],
        $amps,
        $shouldCharge,
        $chgConfig['home_latitude'],
        $chgConfig['home_longitude'],
        $chgConfig['week_days']
    );

    if ($success) {
        saveChargeState([
            'last_amps'        => $targetAmps,
            'charging_enabled' => $shouldCharge,
        ]);

        if ($shouldCharge) {
            logMsg('INFO', "CHARGING ENABLED at {$targetAmps}A (" . ($targetAmps * CHARGER_VOLTAGE) . "W)");
        } else {
            logMsg('INFO', "CHARGING DISABLED (insufficient solar surplus)");
        }

        // Log Wall Charger status for verification
        if (!empty($rivConfig['wallbox_id'])) {
            $wallbox = rivianGetWallboxStatus($session, $rivConfig['wallbox_id']);
            if ($wallbox) {
                logMsg('INFO', sprintf(
                    "Wall Charger: status=%s, power=%s W, amps=%s A, voltage=%s V",
                    $wallbox['chargingStatus'] ?? '?',
                    $wallbox['power'] ?? '?',
                    $wallbox['currentAmps'] ?? '?',
                    $wallbox['currentVoltage'] ?? '?'
                ));
            }
        }
    }
}

// ===========================================================================
// CLI ENTRY POINT
// ===========================================================================

function main(): void
{
    $config = loadConfig();

    // Handle --rivian-setup flag: Rivian authentication + auto-populate vehicle/wallbox/location
    if (in_array('--rivian-setup', $GLOBALS['argv'] ?? [])) {
        logMsg('INFO', "Starting Rivian setup...");

        // Step 1: Authenticate (or reuse existing session)
        $session = rivianLoadSession();
        if ($session) {
            logMsg('INFO', "Existing Rivian session found, reusing it.");
        } else {
            logMsg('INFO', "No active session, authenticating...");
            $session = rivianAuthenticate($config['rivian']);
            if (!$session) {
                logMsg('ERROR', "Rivian authentication failed.");
                exit(1);
            }
            logMsg('INFO', "Rivian authentication successful!");
        }

        // Step 2: Fetch vehicle and wallbox info, auto-populate config
        $userPayload = [
            'operationName' => 'getUserInfo',
            'variables'     => [],
            'query'         => 'query getUserInfo { currentUser { __typename id firstName lastName email address { __typename country } vehicles { __typename id name owner roles vin vas { __typename vasVehicleId vehiclePublicKey } vehicle { __typename model mobileConfiguration { __typename trimOption { __typename optionId optionName } } vehicleState { __typename supportedFeatures { __typename name status } } otaEarlyAccessStatus } settings { __typename name { __typename value } } } enrolledPhones { __typename vas { __typename vasPhoneId publicKey } enrolled { __typename deviceType deviceName vehicleId identityId shortName } } pendingInvites { __typename id invitedByFirstName role status vehicleId vehicleModel email } } }',
        ];

        $userResult = rivianGraphQL($session, $userPayload);
        $user = $userResult['data']['currentUser'] ?? null;
        $configChanged = false;

        if ($user) {
            echo "\n=== Rivian Account ===\n";
            echo sprintf("  Name:  %s %s\n", $user['firstName'] ?? '', $user['lastName'] ?? '');
            echo sprintf("  Email: %s\n", $user['email'] ?? '');

            if (!empty($user['vehicles'])) {
                echo "\n=== Vehicles ===\n";
                foreach ($user['vehicles'] as $v) {
                    $vInfo = $v['vehicle'] ?? [];
                    echo sprintf("  Vehicle ID:  %s\n", $v['id'] ?? 'N/A');
                    echo sprintf("  Name:        %s\n", $v['name'] ?? 'N/A');
                    echo sprintf("  VIN:         %s\n", $v['vin'] ?? 'N/A');
                    echo sprintf("  Model:       %s\n", $vInfo['model'] ?? 'N/A');
                    echo sprintf("  Owner:       %s\n", $v['owner'] ?? 'N/A');
                    echo "  ---\n";
                }

                // Auto-set vehicle_id if not already configured
                $currentVehicleId = $config['rivian']['vehicle_id'] ?? 'YOUR_VEHICLE_ID';
                if ($currentVehicleId === 'YOUR_VEHICLE_ID' || empty($currentVehicleId)) {
                    if (count($user['vehicles']) === 1) {
                        $config['rivian']['vehicle_id'] = $user['vehicles'][0]['id'];
                        $configChanged = true;
                        echo sprintf("Auto-configured vehicle_id: %s\n\n", $config['rivian']['vehicle_id']);
                    } else {
                        echo "Multiple vehicles found. Enter the Vehicle ID to use: ";
                        $chosen = trim(fgets(STDIN));
                        if (!empty($chosen)) {
                            $config['rivian']['vehicle_id'] = $chosen;
                            $configChanged = true;
                            echo sprintf("Configured vehicle_id: %s\n\n", $chosen);
                        }
                    }
                }
            } else {
                echo "\n  No vehicles found.\n";
            }
        } else {
            logMsg('ERROR', "Failed to fetch vehicle info. Will continue with wallbox lookup.");
        }

        // Fetch registered wallboxes (optional, for status monitoring and home location)
        $wallboxPayload = [
            'operationName' => 'getRegisteredWallboxes',
            'variables'     => [],
            'query'         => 'query getRegisteredWallboxes { getRegisteredWallboxes { __typename wallboxId userId wifiId name linked latitude longitude chargingStatus power currentVoltage currentAmps softwareVersion model serialNumber maxPower maxVoltage maxAmps } }',
        ];

        $wbHeaders = [
            'Content-Type: application/json',
            "a-sess: {$session['a_sess']}",
            "u-sess: {$session['u_sess']}",
            "csrf-token: {$session['csrf_token']}",
            'apollographql-client-name: com.rivian.android.consumer',
        ];

        $wbResp = httpRequest(RIVIAN_CHRG_GQL_URL, 'POST', json_encode($wallboxPayload), $wbHeaders);
        $wbData = json_decode($wbResp['body'] ?? '', true);
        $wallboxes = $wbData['data']['getRegisteredWallboxes'] ?? [];

        if (!empty($wallboxes)) {
            echo "\n=== Registered Wall Chargers (optional, for status monitoring) ===\n";
            foreach ($wallboxes as $wb) {
                echo sprintf("  Wallbox ID:    %s\n", $wb['wallboxId'] ?? 'N/A');
                echo sprintf("  Name:          %s\n", $wb['name'] ?? 'N/A');
                echo sprintf("  Model:         %s\n", $wb['model'] ?? 'N/A');
                echo sprintf("  Serial:        %s\n", $wb['serialNumber'] ?? 'N/A');
                echo sprintf("  Firmware:      %s\n", $wb['softwareVersion'] ?? 'N/A');
                echo sprintf("  Status:        %s\n", $wb['chargingStatus'] ?? 'N/A');
                echo sprintf("  Max Power:     %s W\n", $wb['maxPower'] ?? 'N/A');
                echo sprintf("  Location:      %s, %s\n", $wb['latitude'] ?? '?', $wb['longitude'] ?? '?');
                echo "  ---\n";
            }

            // Auto-set wallbox_id for optional status monitoring
            if (empty($config['rivian']['wallbox_id'] ?? '')) {
                if (count($wallboxes) === 1) {
                    $config['rivian']['wallbox_id'] = $wallboxes[0]['wallboxId'];
                    $configChanged = true;
                    echo sprintf("Auto-configured wallbox_id: %s (for status monitoring)\n", $config['rivian']['wallbox_id']);
                }
            }

            // Auto-set home location from wallbox coordinates if not already set
            $wbLat = $wallboxes[0]['latitude'] ?? null;
            $wbLng = $wallboxes[0]['longitude'] ?? null;
            $currentLat = $config['charging']['home_latitude'] ?? 37.0000;
            if ($wbLat && $wbLng && $currentLat == 37.0000) {
                $config['charging']['home_latitude'] = (float) $wbLat;
                $config['charging']['home_longitude'] = (float) $wbLng;
                $configChanged = true;
                echo sprintf("Auto-configured home location from Wall Charger: %s, %s\n", $wbLat, $wbLng);
            }
            echo "\n";
        }

        if ($configChanged) {
            file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            logMsg('INFO', "config.json updated with Rivian vehicle and charger details.");
        }

        echo "\nRivian setup complete!\n\n";
        return;
    }

    // Handle --tesla-setup flag: full OAuth flow or refresh existing token
    if (in_array('--tesla-setup', $GLOBALS['argv'] ?? [])) {
        echo "\n=== Tesla API Setup ===\n\n";

        $refreshToken = $config['tesla']['refresh_token'] ?? '';
        $hasToken = !empty($refreshToken) && $refreshToken !== 'YOUR_TESLA_REFRESH_TOKEN';

        if ($hasToken) {
            echo "Found existing refresh token in config.json, validating...\n";
            $tokenData = teslaRefreshToken($refreshToken);
        } else {
            echo "No refresh token found. Starting Tesla OAuth login flow.\n\n";
            $tokenData = teslaOAuthLogin();
        }

        if (!$tokenData) {
            logMsg('ERROR', "Tesla authentication failed.");
            exit(1);
        }

        logMsg('INFO', "Tesla token validated and saved!");

        // Save refresh token to config
        $configChanged = false;
        if (!empty($tokenData['refresh_token'])) {
            $config['tesla']['refresh_token'] = $tokenData['refresh_token'];
            $configChanged = true;
        }

        // Fetch products to find site_id
        $products = teslaGetProducts($tokenData['access_token']);
        $energySites = [];
        if ($products && !empty($products['response'])) {
            echo "\n=== All Tesla Products ===\n";
            foreach ($products['response'] as $product) {
                if (isset($product['energy_site_id'])) {
                    $energySites[] = $product;
                } elseif (isset($product['vin'])) {
                    echo sprintf("  Vehicle: %s (VIN: %s)\n", $product['display_name'] ?? 'N/A', $product['vin']);
                }
            }
        }

        if (!empty($energySites)) {
            echo "\n=== Energy Sites ===\n";
            foreach ($energySites as $site) {
                echo sprintf("  Site ID:       %s\n", $site['energy_site_id'] ?? 'N/A');
                echo sprintf("  Site Name:     %s\n", $site['site_name'] ?? 'N/A');
                echo sprintf("  Resource Type: %s\n", $site['resource_type'] ?? 'N/A');
                echo sprintf("  Battery Type:  %s\n", $site['battery_type'] ?? 'none');
                echo sprintf("  Gateway ID:    %s\n", $site['gateway_id'] ?? 'N/A');
                echo sprintf("  Energy Left:   %s Wh\n", $site['energy_left'] ?? 'N/A');
                echo sprintf("  Charged:       %s%%\n", $site['percentage_charged'] ?? 'N/A');
                echo "  ---\n";
            }

            // Auto-set site_id if there's exactly one energy site, or if not already set
            $currentSiteId = $config['tesla']['site_id'] ?? 'YOUR_ENERGY_SITE_ID';
            if (count($energySites) === 1 || $currentSiteId === 'YOUR_ENERGY_SITE_ID') {
                $config['tesla']['site_id'] = (string) $energySites[0]['energy_site_id'];
                $configChanged = true;
                echo sprintf("Auto-configured site_id: %s\n\n", $config['tesla']['site_id']);
            }
        } else {
            echo "\nNo energy sites found. Full API response:\n";
            echo json_encode($products, JSON_PRETTY_PRINT) . "\n\n";
        }

        if ($configChanged) {
            file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            logMsg('INFO', "config.json updated with Tesla credentials.");
        }

        echo "Tesla setup complete!\n\n";
        return;
    }

    // Handle --status flag
    if (in_array('--status', $GLOBALS['argv'] ?? [])) {
        $teslaToken = teslaLoadToken();
        if (!$teslaToken) {
            $teslaToken = teslaRefreshToken($config['tesla']['refresh_token'] ?? '');
        }

        if ($teslaToken) {
            $liveData = teslaGetLiveStatus($teslaToken['access_token'], $config['tesla']['site_id'] ?? '');
            if ($liveData) {
                $soe = $liveData['battery_pct'];
                echo "\n=== Powerwall 3 Status (via Tesla Cloud) ===\n";
                echo sprintf("  Solar Production:  %6.0f W\n", $liveData['solar_w']);
                echo sprintf("  Home Consumption:  %6.0f W\n", $liveData['load_w']);
                echo sprintf("  Grid (neg=export): %6.0f W\n", $liveData['grid_w']);
                echo sprintf("  Battery (neg=chg): %6.0f W\n", $liveData['battery_w']);
                echo sprintf("  Battery SOE:       %5.1f%%\n", $soe);

                $targetAmps = calculateTargetAmps($liveData, $soe, $config['charging']);
                echo "\n=== Charging Decision ===\n";
                echo sprintf("  Target Amps: %d A\n", $targetAmps);
                echo sprintf("  Would Charge: %s\n", $targetAmps > 0 ? 'YES' : 'NO');
            }
        } else {
            echo "\n  Tesla API not configured. Run --tesla-setup first.\n";
        }

        $state = loadChargeState();
        echo "\n=== Current Charge State ===\n";
        echo sprintf("  Last Amps Set:    %d A\n", $state['last_amps']);
        echo sprintf("  Charging Enabled: %s\n", $state['charging_enabled'] ? 'YES' : 'NO');
        echo sprintf("  Last Update:      %s\n", $state['last_update'] ? date('Y-m-d H:i:s', $state['last_update']) : 'Never');

        // Wall Charger status (optional)
        $session = rivianLoadSession();
        if ($session) {
            if (!empty($config['rivian']['wallbox_id'])) {
                $wallbox = rivianGetWallboxStatus($session, $config['rivian']['wallbox_id']);
                if ($wallbox) {
                    echo "\n=== Rivian Wall Charger ===\n";
                    echo sprintf("  Name:            %s\n", $wallbox['name'] ?? 'N/A');
                    echo sprintf("  Model:           %s\n", $wallbox['model'] ?? 'N/A');
                    echo sprintf("  Firmware:        %s\n", $wallbox['softwareVersion'] ?? 'N/A');
                    echo sprintf("  Status:          %s\n", $wallbox['chargingStatus'] ?? 'N/A');
                    echo sprintf("  Power:           %s W\n", $wallbox['power'] ?? 'N/A');
                    echo sprintf("  Voltage:         %s V\n", $wallbox['currentVoltage'] ?? 'N/A');
                    echo sprintf("  Current:         %s A\n", $wallbox['currentAmps'] ?? 'N/A');
                    echo sprintf("  Max Power:       %s W\n", $wallbox['maxPower'] ?? 'N/A');
                    echo sprintf("  Max Amps:        %s A\n", $wallbox['maxAmps'] ?? 'N/A');
                }
            }

            $liveSession = rivianGetLiveSession($session, $config['rivian']['vehicle_id']);
            if ($liveSession) {
                echo "\n=== Live Charging Session ===\n";
                echo sprintf("  Rivian Charger:  %s\n", ($liveSession['isRivianCharger'] ?? false) ? 'YES' : 'NO');
                echo sprintf("  Charger State:   %s\n", $liveSession['vehicleChargerState']['value'] ?? 'N/A');
                echo sprintf("  SOC:             %s%%\n", $liveSession['soc']['value'] ?? 'N/A');
                echo sprintf("  Power:           %s kW\n", $liveSession['power']['value'] ?? 'N/A');
                echo sprintf("  Time Remaining:  %s min\n", $liveSession['timeRemaining']['value'] ?? 'N/A');
                echo sprintf("  Charge Rate:     %s km/hr\n", $liveSession['kilometersChargedPerHour']['value'] ?? 'N/A');
            } else {
                echo "\n=== Live Charging Session ===\n";
                echo "  Not currently charging.\n";
            }
        } else {
            echo "\n  (Run --rivian-setup for Rivian status details)\n";
        }

        echo "\n";
        return;
    }

    // Handle --daemon flag
    if (in_array('--daemon', $GLOBALS['argv'] ?? [])) {
        $interval = $config['charging']['poll_interval_seconds'] ?? 300;
        logMsg('INFO', "Starting daemon mode, polling every {$interval} seconds");
        logMsg('INFO', "Press Ctrl+C to stop");

        while (true) {
            try {
                runOnce($config);
            } catch (Throwable $e) {
                logMsg('ERROR', "Exception: " . $e->getMessage());
            }
            sleep($interval);
        }
    }

    // Single run (default)
    logMsg('INFO', "=== Solar Charge Controller: Single Run ===");
    runOnce($config);
}

main();
