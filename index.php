<?php
/**
 * Solar Charge Controller - Web Dashboard
 *
 * Displays live status, historical charts, and a mode toggle
 * for the solar charge controller.
 *
 * Place this file in the same directory as solar_charge.php
 * and serve via PHP's built-in server or Apache/Nginx.
 *
 * Quick start: php -S localhost:8080
 */

$baseDir = __DIR__;

// Handle API requests
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    switch ($_GET['api']) {
        case 'status':
            $state = [];
            if (file_exists("$baseDir/charge_state.json")) {
                $state = json_decode(file_get_contents("$baseDir/charge_state.json"), true) ?? [];
            }
            $mode = ['mode' => 'solar'];
            if (file_exists("$baseDir/charge_mode.json")) {
                $mode = json_decode(file_get_contents("$baseDir/charge_mode.json"), true) ?? $mode;
            }
            $config = [];
            if (file_exists("$baseDir/config.json")) {
                $config = json_decode(file_get_contents("$baseDir/config.json"), true) ?? [];
            }
            echo json_encode([
                'state'  => $state,
                'mode'   => $mode,
                'tou'    => $config['tou_schedule'] ?? [],
                'config' => [
                    'min_solar_watts'            => $config['charging']['min_solar_watts'] ?? 600,
                    'max_amps'                   => $config['charging']['max_amps'] ?? 48,
                    'powerwall_min_battery_pct'  => $config['charging']['powerwall_min_battery_pct'] ?? 20,
                    'rivian_min_battery_pct'     => $config['charging']['rivian_min_battery_pct'] ?? 20,
                    'rivian_full_charge_limit_pct' => $config['charging']['rivian_full_charge_limit_pct'] ?? 85,
                ],
            ]);
            break;

        case 'history':
            $history = [];
            if (file_exists("$baseDir/charge_history.json")) {
                $history = json_decode(file_get_contents("$baseDir/charge_history.json"), true) ?? [];
            }
            echo json_encode($history);
            break;

        case 'set_mode':
            $newMode = ($_GET['mode'] ?? '') === 'schedule' ? 'schedule' : 'solar';
            file_put_contents("$baseDir/charge_mode.json", json_encode([
                'mode'       => $newMode,
                'changed_at' => time(),
            ], JSON_PRETTY_PRINT));
            echo json_encode(['success' => true, 'mode' => $newMode]);
            break;

        case 'run_once':
            // Execute the solar charge script inline via CLI
            $phpBin = '/usr/local/bin/php';
            $script = escapeshellarg("$baseDir/solar_charge.php");
            $output = [];
            $exitCode = 0;
            exec("$phpBin $script 2>&1", $output, $exitCode);
            echo json_encode([
                'success'   => $exitCode === 0,
                'exit_code' => $exitCode,
                'output'    => implode("\n", array_slice($output, -10)),
            ]);
            break;

        case 'mfa_status':
            $mfaPending = false;
            if (file_exists("$baseDir/rivian_mfa_pending.json")) {
                $mfa = json_decode(file_get_contents("$baseDir/rivian_mfa_pending.json"), true);
                // Show MFA panel for up to 10 minutes (gives time to check phone and enter code)
                if ($mfa && (time() - ($mfa['created_at'] ?? 0)) < 600) {
                    $mfaPending = true;
                }
            }
            // Also check if session is missing/expired
            $sessionValid = false;
            if (file_exists("$baseDir/rivian_session.json")) {
                $sess = json_decode(file_get_contents("$baseDir/rivian_session.json"), true);
                if ($sess && !empty($sess['u_sess'])) {
                    $age = time() - ($sess['created_at'] ?? 0);
                    $sessionValid = $age < 86400 * 6;
                }
            }
            echo json_encode([
                'mfa_pending'   => $mfaPending,
                'session_valid' => $sessionValid,
            ]);
            break;

        case 'submit_otp':
            $input = json_decode(file_get_contents('php://input'), true);
            $otpCode = $input['otp_code'] ?? $_POST['otp_code'] ?? $_GET['otp_code'] ?? '';

            if (empty($otpCode) || !file_exists("$baseDir/rivian_mfa_pending.json")) {
                echo json_encode(['success' => false, 'error' => 'No pending MFA challenge or missing OTP code']);
                break;
            }

            $mfa = json_decode(file_get_contents("$baseDir/rivian_mfa_pending.json"), true);
            if (!$mfa) {
                echo json_encode(['success' => false, 'error' => 'Could not read MFA challenge file.']);
                break;
            }

            // Complete MFA inline (no require_once needed)
            $otpPayload = json_encode([
                'operationName' => 'LoginWithOTP',
                'variables'     => [
                    'email'    => $mfa['email'],
                    'otpCode'  => $otpCode,
                    'otpToken' => $mfa['otp_token'],
                ],
                'query' => 'mutation LoginWithOTP($email: String!, $otpCode: String!, $otpToken: String!) { loginWithOTP(email: $email, otpCode: $otpCode, otpToken: $otpToken) { __typename accessToken refreshToken userSessionToken } }',
            ]);

            $ch = curl_init('https://rivian.com/api/gql/gateway/graphql');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $otpPayload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'a-sess: ' . $mfa['a_sess'],
                    'csrf-token: ' . $mfa['csrf_token'],
                    'apollographql-client-name: com.rivian.android.consumer',
                ],
                CURLOPT_TIMEOUT => 30,
            ]);
            $otpResp = curl_exec($ch);
            $otpData = json_decode($otpResp, true);

            $loginResult = $otpData['data']['loginWithOTP'] ?? null;

            if ($loginResult && !empty($loginResult['userSessionToken'])) {
                $session = [
                    'a_sess'        => $mfa['a_sess'],
                    'u_sess'        => $loginResult['userSessionToken'],
                    'csrf_token'    => $mfa['csrf_token'],
                    'access_token'  => $loginResult['accessToken'] ?? '',
                    'refresh_token' => $loginResult['refreshToken'] ?? '',
                    'created_at'    => time(),
                ];
                file_put_contents("$baseDir/rivian_session.json", json_encode($session, JSON_PRETTY_PRINT));
                chmod("$baseDir/rivian_session.json", 0600);
                unlink("$baseDir/rivian_mfa_pending.json");
                echo json_encode(['success' => true, 'message' => 'Rivian session restored!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid OTP code. Check the code and try again.']);
            }
            break;

        case 'resend_otp':
            // Immediately trigger a fresh Rivian login to send a new OTP
            if (file_exists("$baseDir/rivian_mfa_pending.json")) {
                unlink("$baseDir/rivian_mfa_pending.json");
            }
            if (file_exists("$baseDir/rivian_session.json")) {
                unlink("$baseDir/rivian_session.json");
            }

            $config = json_decode(file_get_contents("$baseDir/config.json"), true) ?? [];
            $email = $config['rivian']['email'] ?? '';
            $password = $config['rivian']['password'] ?? '';

            if (empty($email) || empty($password)) {
                echo json_encode(['success' => false, 'error' => 'Rivian credentials not found in config.']);
                break;
            }

            // Step 1: Get CSRF token
            $ch = curl_init('https://rivian.com/api/gql/gateway/graphql');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'operationName' => 'CreateCSRFToken',
                    'variables' => [],
                    'query' => 'mutation CreateCSRFToken { createCsrfToken { __typename csrfToken appSessionToken } }',
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 30,
            ]);
            $csrfResp = json_decode(curl_exec($ch), true);
            $csrfToken = $csrfResp['data']['createCsrfToken']['csrfToken'] ?? null;
            $aSess = $csrfResp['data']['createCsrfToken']['appSessionToken'] ?? null;

            if (!$csrfToken || !$aSess) {
                echo json_encode(['success' => false, 'error' => 'Failed to get CSRF token from Rivian.']);
                break;
            }

            // Step 2: Login (triggers MFA SMS)
            $ch = curl_init('https://rivian.com/api/gql/gateway/graphql');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'operationName' => 'Login',
                    'variables' => ['email' => $email, 'password' => $password],
                    'query' => 'mutation Login($email: String!, $password: String!) { login(email: $email, password: $password) { __typename ... on MobileLoginResponse { accessToken refreshToken userSessionToken } ... on MobileMFALoginResponse { otpToken } } }',
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    "a-sess: $aSess",
                    "csrf-token: $csrfToken",
                    'apollographql-client-name: com.rivian.android.consumer',
                ],
                CURLOPT_TIMEOUT => 30,
            ]);
            $loginResp = json_decode(curl_exec($ch), true);
            $loginResult = $loginResp['data']['login'] ?? null;

            if (!$loginResult) {
                echo json_encode(['success' => false, 'error' => 'Rivian login failed.']);
                break;
            }

            if (($loginResult['__typename'] ?? '') === 'MobileMFALoginResponse') {
                // Save MFA challenge for the OTP input
                file_put_contents("$baseDir/rivian_mfa_pending.json", json_encode([
                    'otp_token' => $loginResult['otpToken'],
                    'a_sess' => $aSess,
                    'csrf_token' => $csrfToken,
                    'email' => $email,
                    'created_at' => time(),
                ], JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'message' => 'OTP code sent! Check your phone.', 'mfa_pending' => true]);
            } elseif (!empty($loginResult['userSessionToken'])) {
                // No MFA needed, session restored directly
                $session = [
                    'a_sess' => $aSess,
                    'u_sess' => $loginResult['userSessionToken'],
                    'csrf_token' => $csrfToken,
                    'access_token' => $loginResult['accessToken'] ?? '',
                    'refresh_token' => $loginResult['refreshToken'] ?? '',
                    'created_at' => time(),
                ];
                file_put_contents("$baseDir/rivian_session.json", json_encode($session, JSON_PRETTY_PRINT));
                chmod("$baseDir/rivian_session.json", 0600);
                echo json_encode(['success' => true, 'message' => 'Rivian session restored without MFA!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Unexpected login response.']);
            }
            break;

        default:
            echo json_encode(['error' => 'unknown endpoint']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="assets/solar-logo.svg">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Rivian Solar">
<meta name="theme-color" content="#0c0f14">
<title>Rivian Solar</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #0c0f14;
    --surface: #151921;
    --surface-2: #1c2230;
    --border: #2a3142;
    --text: #e2e8f0;
    --text-dim: #8892a4;
    --solar: #f6ad35;
    --solar-glow: rgba(246, 173, 53, 0.15);
    --grid: #e53e3e;
    --battery-pw: #48bb78;
    --battery-rv: #4299e1;
    --home: #9f7aea;
    --charging: #48bb78;
    --blocked: #e53e3e;
    --accent: #f6ad35;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px 20px;
}

header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 16px;
}

h1 {
    font-size: 1.5rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

h1 .logo {
    height: 100px;
    width: auto;
}

.mode-toggle {
    display: flex;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
}

.mode-btn {
    padding: 10px 20px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.9rem;
    font-weight: 500;
    border: none;
    background: transparent;
    color: var(--text-dim);
    cursor: pointer;
    transition: all 0.25s;
}

.mode-btn.active {
    background: var(--solar);
    color: var(--bg);
}

.mode-btn:hover:not(.active) {
    color: var(--text);
    background: var(--surface-2);
}

.grid-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
}

.card-label {
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-dim);
    margin-bottom: 8px;
}

.card-value {
    font-family: 'JetBrains Mono', monospace;
    font-size: 1.6rem;
    font-weight: 500;
}

.card-sub {
    font-size: 0.8rem;
    color: var(--text-dim);
    margin-top: 4px;
}

.card.status-charging .card-value { color: var(--charging); }
.card.status-blocked .card-value { color: var(--solar); }
.card.status-unplugged .card-value { color: var(--text-dim); }
.card.status-error .card-value { color: var(--grid); }
.card.solar .card-value { color: var(--solar); }
.card.grid-card .card-value { color: var(--grid); }
.card.pw .card-value { color: var(--battery-pw); }
.card.rv .card-value { color: var(--battery-rv); }
.card.home-card .card-value { color: var(--home); }

.chart-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}

.chart-section h2 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 16px;
    color: var(--text-dim);
}

canvas {
    width: 100% !important;
    height: 300px !important;
}

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 12px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8rem;
    color: var(--text-dim);
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.time-range-bar {
    display: flex;
    justify-content: center;
    gap: 4px;
    margin-bottom: 20px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 4px;
    width: fit-content;
    margin-left: auto;
    margin-right: auto;
}

.range-btn {
    padding: 6px 14px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.8rem;
    font-weight: 500;
    border: none;
    background: transparent;
    color: var(--text-dim);
    cursor: pointer;
    border-radius: 7px;
    transition: all 0.2s;
}

.range-btn.active {
    background: var(--surface-2);
    color: var(--text);
}

.range-btn:hover:not(.active) {
    color: var(--text);
}

.tou-info {
    font-size: 0.85rem;
    color: var(--text-dim);
    padding: 12px 16px;
    background: var(--surface-2);
    border-radius: 8px;
    margin-top: 8px;
    display: none;
}

.tou-info.visible { display: block; }

.last-update {
    text-align: center;
    font-size: 0.8rem;
    color: var(--text-dim);
    margin-top: 16px;
    font-family: 'JetBrains Mono', monospace;
}

.daemon-alert {
    text-align: center;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 500;
    margin-bottom: 24px;
    display: none;
}

.daemon-alert.warning {
    display: block;
    background: rgba(246, 173, 53, 0.1);
    border: 1px solid rgba(246, 173, 53, 0.3);
    color: var(--solar);
}

.daemon-alert.error {
    display: block;
    background: rgba(229, 62, 62, 0.1);
    border: 1px solid rgba(229, 62, 62, 0.3);
    color: var(--grid);
}

.mfa-panel {
    display: none;
    background: var(--surface);
    border: 1px solid var(--solar);
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 24px;
}

.mfa-panel.visible { display: block; }

.mfa-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 6px;
}

.mfa-desc {
    font-size: 0.85rem;
    color: var(--text-dim);
    margin-bottom: 14px;
}

.mfa-input-row {
    display: flex;
    gap: 10px;
}

.mfa-input {
    flex: 1;
    max-width: 200px;
    padding: 10px 14px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 1.1rem;
    letter-spacing: 0.15em;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    outline: none;
}

.mfa-input:focus { border-color: var(--solar); }

.mfa-btn {
    padding: 10px 20px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.9rem;
    font-weight: 600;
    background: var(--solar);
    color: var(--bg);
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: opacity 0.2s;
}

.mfa-btn:hover { opacity: 0.85; }

.mfa-status {
    font-size: 0.85rem;
    margin-top: 10px;
}

.mfa-status.success { color: var(--charging); }
.mfa-status.error { color: var(--grid); }

@media (max-width: 600px) {
    .grid-cards { grid-template-columns: repeat(2, 1fr); }
    .card-value { font-size: 1.3rem; }
    header { flex-direction: column; align-items: flex-start; }
}
</style>
</head>
<body>
<div class="container">
    <header>
        <h1><img src="assets/solar-logo.svg" alt="Rivian Solar" class="logo"> Rivian Solar</h1>
        <div>
            <div class="mode-toggle">
                <button class="mode-btn" data-mode="solar" onclick="setMode('solar')">Solar Mode</button>
                <button class="mode-btn" data-mode="schedule" onclick="setMode('schedule')">Schedule Mode</button>
            </div>
            <div class="tou-info" id="touInfo"></div>
        </div>
    </header>

    <div class="daemon-alert" id="daemonAlert"></div>

    <div class="mfa-panel" id="mfaPanel">
        <div class="mfa-title">🔐 Rivian Session Expired</div>
        <div class="mfa-desc" id="mfaDesc">An OTP code has been sent to your phone/email. Enter it below to restore the connection.</div>
        <div class="mfa-input-row" id="mfaInputRow">
            <input type="text" id="otpInput" class="mfa-input" placeholder="Enter OTP code" maxlength="10" inputmode="numeric" autocomplete="one-time-code">
            <button class="mfa-btn" onclick="submitOtp()">Submit</button>
        </div>
        <div class="mfa-resend" id="mfaResend" style="display:none;">
            <button class="mfa-btn" onclick="resendOtp()" style="background:var(--surface-2); color:var(--text); border:1px solid var(--border);">Resend OTP Code</button>
        </div>
        <div class="mfa-status" id="mfaStatus"></div>
    </div>

    <div class="grid-cards">
        <div class="card solar">
            <div class="card-label">Solar Production</div>
            <div class="card-value" id="solarW">--</div>
            <div class="card-sub">watts</div>
        </div>
        <div class="card home-card">
            <div class="card-label">Home Usage</div>
            <div class="card-value" id="homeW">--</div>
            <div class="card-sub">watts</div>
        </div>
        <div class="card grid-card">
            <div class="card-label">Grid</div>
            <div class="card-value" id="gridW">--</div>
            <div class="card-sub" id="gridDir">watts</div>
        </div>
        <div class="card pw">
            <div class="card-label">Powerwall</div>
            <div class="card-value" id="pwPct">--</div>
            <div class="card-sub" id="pwFlow">--</div>
        </div>
        <div class="card rv">
            <div class="card-label">Rivian Battery</div>
            <div class="card-value" id="rvPct">--</div>
            <div class="card-sub" id="rvLimit">--</div>
        </div>
        <div class="card" id="statusCard">
            <div class="card-label">Charge Status</div>
            <div class="card-value" id="chargeStatus">--</div>
            <div class="card-sub" id="chargeAmps">--</div>
        </div>
    </div>

    <div class="time-range-bar">
        <button class="range-btn" data-hours="1" onclick="setTimeRange(1)">1h</button>
        <button class="range-btn active" data-hours="3" onclick="setTimeRange(3)">3h</button>
        <button class="range-btn" data-hours="6" onclick="setTimeRange(6)">6h</button>
        <button class="range-btn" data-hours="12" onclick="setTimeRange(12)">12h</button>
        <button class="range-btn" data-hours="24" onclick="setTimeRange(24)">24h</button>
        <button class="range-btn" data-hours="168" onclick="setTimeRange(168)">1wk</button>
    </div>

    <div class="chart-section">
        <h2>Power Flow</h2>
        <canvas id="powerChart"></canvas>
        <div class="legend">
            <div class="legend-item"><div class="legend-dot" style="background:var(--solar)"></div> Solar</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--home)"></div> Home</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--battery-rv)"></div>  Rivian</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--grid)"></div> Grid</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--battery-pw)"></div> Powerwall</div>
        </div>
    </div>

    <div class="chart-section">
        <h2>Charging Rate &amp; Battery Levels</h2>
        <canvas id="chargeChart"></canvas>
        <div class="legend">
            <div class="legend-item"><div class="legend-dot" style="background:var(--accent)"></div> Charge Rate (A)</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--battery-rv)"></div> Rivian %</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--battery-pw)"></div> Powerwall %</div>
        </div>
    </div>

    <div class="last-update" id="lastUpdate">Loading...</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
let powerChart, chargeChart;
let timeRangeHours = 3;
let lastHistory = [];

const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: '#1c2230',
            titleColor: '#e2e8f0',
            bodyColor: '#8892a4',
            borderColor: '#2a3142',
            borderWidth: 1,
            cornerRadius: 8,
            titleFont: { family: 'DM Sans' },
            bodyFont: { family: 'JetBrains Mono', size: 12 },
        }
    },
    scales: {
        x: {
            grid: { color: 'rgba(42,49,66,0.5)' },
            ticks: { color: '#8892a4', font: { family: 'JetBrains Mono', size: 10 }, maxTicksLimit: 12 }
        },
        y: {
            grid: { color: 'rgba(42,49,66,0.5)' },
            ticks: { color: '#8892a4', font: { family: 'JetBrains Mono', size: 10 } }
        }
    }
};

function formatTime(ts) {
    return new Date(ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatW(w) {
    if (w === null || w === undefined) return '--';
    const abs = Math.abs(w);
    if (abs >= 1000) return (w / 1000).toFixed(1) + ' kW';
    return Math.round(w) + ' W';
}

async function fetchStatus() {
    try {
        const resp = await fetch('?api=status');
        const data = await resp.json();

        const history = (await (await fetch('?api=history')).json()) || [];
        const latest = history.length > 0 ? history[history.length - 1] : {};

        // Update cards
        document.getElementById('solarW').textContent = formatW(latest.solar_w);
        document.getElementById('homeW').textContent = formatW(latest.load_w);

        const gridW = latest.grid_w ?? 0;
        document.getElementById('gridW').textContent = formatW(Math.abs(gridW));
        document.getElementById('gridDir').textContent = gridW < 0 ? 'exporting' : gridW > 0 ? 'importing' : 'balanced';

        const pwPct = latest.powerwall_pct;
        document.getElementById('pwPct').textContent = pwPct !== null && pwPct !== undefined ? pwPct.toFixed(0) + '%' : '--';
        const battW = latest.battery_w ?? 0;
        document.getElementById('pwFlow').textContent = battW < 0 ? 'Charging ' + formatW(Math.abs(battW)) : battW > 0 ? 'Discharging ' + formatW(battW) : 'Idle';

        const rvPct = latest.rivian_pct;
        document.getElementById('rvPct').textContent = rvPct !== null && rvPct !== undefined ? rvPct.toFixed(0) + '%' : '--';
        document.getElementById('rvLimit').textContent = latest.rivian_limit ? 'Limit: ' + latest.rivian_limit + '%' : '--';

        const state = data.state;

        // Use the latest history point for current status since charge_state.json
        // only updates when a change is made, not on "no change needed" cycles
        const isCharging = latest.charging ?? state.charging_enabled ?? false;
        const currentAmps = latest.target_amps ?? state.last_amps ?? 0;
        const displayStatus = latest.status ?? (isCharging ? 'Solar Charging' : 'Waiting for the Sun');

        const statusCard = document.getElementById('statusCard');
        const isActiveCharge = ['Solar Charging', 'Override Charging', 'Scheduled Charging'].includes(displayStatus);
        const isWaiting = ['Waiting for the Sun', 'Scheduled'].includes(displayStatus);
        const isComplete = displayStatus === 'Charge Complete';
        const isUnplugged = displayStatus === 'Unplugged';
        const isError = displayStatus === 'Error';
        const isAway = displayStatus === 'Away';

        statusCard.className = 'card'
            + (isActiveCharge ? ' status-charging' : '')
            + (isWaiting ? ' status-blocked' : '')
            + (isComplete ? ' pw' : '')
            + (isError ? ' status-error' : '')
            + (isAway ? ' rv' : '');

        document.getElementById('chargeStatus').textContent = displayStatus;

        if (isError) {
            document.getElementById('chargeAmps').textContent = 'Cannot reach Rivian API';
        } else if (isAway) {
            document.getElementById('chargeAmps').textContent = 'Truck is away from home';
        } else if (isActiveCharge) {
            document.getElementById('chargeAmps').textContent = currentAmps + 'A / ' + (currentAmps * 240) + 'W';
        } else if (isUnplugged) {
            document.getElementById('chargeAmps').textContent = 'Rivian disconnected';
        } else if (isComplete) {
            document.getElementById('chargeAmps').textContent = 'Reached charge limit';
        } else if (displayStatus === 'Scheduled') {
            document.getElementById('chargeAmps').textContent = 'Waiting for off-peak window';
        } else {
            document.getElementById('chargeAmps').textContent = 'Monitoring solar production';
        }

        // Mode toggle
        const mode = data.mode?.mode || 'solar';
        document.querySelectorAll('.mode-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });

        const touInfo = document.getElementById('touInfo');
        if (mode === 'schedule' && data.tou) {
            touInfo.textContent = 'Off-peak: ' + (data.tou.start_time || '00:00') + ' to ' + (data.tou.end_time || '06:00') + ' at ' + (data.tou.amps || 48) + 'A';
            touInfo.classList.add('visible');
        } else {
            touInfo.classList.remove('visible');
        }

        // Use latest history timestamp for "last update" if more recent than state
        const latestTimestamp = latest.timestamp ?? state.last_update;
        document.getElementById('lastUpdate').textContent = latestTimestamp
            ? 'Last update: ' + new Date(latestTimestamp * 1000).toLocaleString()
            : 'No data yet';

        // Daemon health check (use history timestamp, which updates every cycle)
        const daemonAlert = document.getElementById('daemonAlert');
        const lastActivityTimestamp = latest.timestamp ?? state.last_update;
        if (lastActivityTimestamp) {
            const ageSeconds = Math.floor(Date.now() / 1000) - lastActivityTimestamp;
            const ageMinutes = Math.floor(ageSeconds / 60);

            if (ageSeconds > 1800) {
                daemonAlert.className = 'daemon-alert error';
                daemonAlert.textContent = '⚠ Daemon appears to have stopped (' + ageMinutes + ' min since last update). Check the terminal or restart with: php solar_charge.php --daemon';
            } else if (ageSeconds > 600) {
                daemonAlert.className = 'daemon-alert warning';
                daemonAlert.textContent = '⚠ Daemon may be stalled (' + ageMinutes + ' min since last update). Expected every 5 minutes.';
            } else {
                daemonAlert.className = 'daemon-alert';
            }
        } else {
            daemonAlert.className = 'daemon-alert error';
            daemonAlert.textContent = '⚠ No data found. Start the daemon with: php solar_charge.php --daemon';
        }

        // Update charts
        lastHistory = history;
        updateCharts(history);
    } catch (e) {
        console.error('Fetch error:', e);
    }
}

function updateCharts(history) {
    const cutoff = Date.now() / 1000 - timeRangeHours * 3600;
    const data = history.filter(h => h.timestamp >= cutoff);

    // Adjust time format based on range
    const useDate = timeRangeHours > 24;
    const labels = data.map(h => {
        const d = new Date(h.timestamp * 1000);
        if (useDate) {
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    });

    // Adjust tick density based on range
    const maxTicks = timeRangeHours <= 6 ? 12 : timeRangeHours <= 24 ? 12 : 14;

    // Power chart
    const powerData = {
        labels,
        datasets: [
            { label: 'Solar', data: data.map(h => (h.solar_w ?? 0) / 1000), borderColor: '#f6ad35', backgroundColor: 'rgba(246,173,53,0.1)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 },
            { label: 'Home', data: data.map(h => {
                const load = h.load_w ?? 0;
                const actuallyCharging = h.charger_state === 'charging_active';
                const vehicleW = actuallyCharging ? (h.target_amps ?? 0) * 240 : 0;
                return Math.max(0, load - vehicleW) / 1000;
            }), borderColor: '#9f7aea', backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 2 },
            { label: 'Rivian', data: data.map(h => {
                const actuallyCharging = h.charger_state === 'charging_active';
                return actuallyCharging ? ((h.target_amps ?? 0) * 240) / 1000 : 0;
            }), borderColor: '#4299e1', backgroundColor: 'rgba(66,153,225,0.08)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 },
            { label: 'Grid', data: data.map(h => (h.grid_w ?? 0) / 1000), borderColor: '#e53e3e', backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 1.5, borderDash: [4,4] },
            { label: 'Powerwall', data: data.map(h => (h.battery_w ?? 0) / 1000), borderColor: '#48bb78', backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 1.5 },
        ]
    };

    const powerOpts = JSON.parse(JSON.stringify(chartDefaults));
    powerOpts.scales.x.ticks.maxTicksLimit = maxTicks;
    powerOpts.scales.y.title = { display: true, text: 'kW', color: '#8892a4', font: { family: 'JetBrains Mono', size: 11 } };

    if (powerChart) {
        powerChart.destroy();
    }
    powerChart = new Chart(document.getElementById('powerChart'), { type: 'line', data: powerData, options: powerOpts });

    // Charge chart (dual Y axis)
    const chargeData = {
        labels,
        datasets: [
            { label: 'Charge Rate', data: data.map(h => h.target_amps ?? 0), borderColor: '#f6ad35', backgroundColor: 'rgba(246,173,53,0.08)', fill: true, tension: 0.1, pointRadius: 0, borderWidth: 2, yAxisID: 'y' },
            { label: 'Rivian %', data: data.map(h => h.rivian_pct ?? null), borderColor: '#4299e1', backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 2, yAxisID: 'y1' },
            { label: 'Powerwall %', data: data.map(h => h.powerwall_pct ?? null), borderColor: '#48bb78', backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 1.5, borderDash: [4,4], yAxisID: 'y1' },
        ]
    };

    const chargeOpts = JSON.parse(JSON.stringify(chartDefaults));
    chargeOpts.scales.x.ticks.maxTicksLimit = maxTicks;
    chargeOpts.scales.y = {
        ...chargeOpts.scales.y,
        position: 'left',
        title: { display: true, text: 'Amps', color: '#8892a4', font: { family: 'JetBrains Mono', size: 11 } },
        min: 0
    };
    chargeOpts.scales.y1 = {
        position: 'right',
        grid: { drawOnChartArea: false },
        ticks: { color: '#8892a4', font: { family: 'JetBrains Mono', size: 10 } },
        title: { display: true, text: 'Battery %', color: '#8892a4', font: { family: 'JetBrains Mono', size: 11 } },
        min: 0,
        max: 100
    };

    if (chargeChart) {
        chargeChart.destroy();
    }
    chargeChart = new Chart(document.getElementById('chargeChart'), { type: 'line', data: chargeData, options: chargeOpts });
}

function setTimeRange(hours) {
    timeRangeHours = hours;
    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.classList.toggle('active', parseInt(btn.dataset.hours) === hours);
    });
    updateCharts(lastHistory);
}

async function setMode(mode) {
    // Disable buttons while processing
    document.querySelectorAll('.mode-btn').forEach(btn => btn.disabled = true);
    document.getElementById('chargeStatus').textContent = 'Updating...';

    try {
        // Step 1: Set the mode
        await fetch('?api=set_mode&mode=' + encodeURIComponent(mode));

        // Step 2: Run the script (waits for it to complete)
        await fetch('?api=run_once');

        // Step 3: Refresh the dashboard with new data
        await fetchStatus();
    } catch (e) {
        console.error('Mode set error:', e);
    }

    document.querySelectorAll('.mode-btn').forEach(btn => btn.disabled = false);
}

async function checkMfa() {
    try {
        const resp = await fetch('?api=mfa_status');
        const data = await resp.json();
        const panel = document.getElementById('mfaPanel');
        const inputRow = document.getElementById('mfaInputRow');
        const resendRow = document.getElementById('mfaResend');
        const desc = document.getElementById('mfaDesc');

        if (data.mfa_pending) {
            panel.classList.add('visible');
            // Show input field for fresh challenges, resend button for expired ones
            inputRow.style.display = 'flex';
            resendRow.style.display = 'none';
            desc.textContent = 'An OTP code has been sent to your phone/email. Enter it below to restore the connection.';
        } else if (!data.session_valid) {
            // Session expired but no pending MFA (challenge expired or not yet triggered)
            panel.classList.add('visible');
            inputRow.style.display = 'none';
            resendRow.style.display = 'block';
            desc.textContent = 'Rivian session has expired. Click below to request a new OTP code.';
        } else {
            panel.classList.remove('visible');
            document.getElementById('mfaStatus').textContent = '';
            document.getElementById('otpInput').value = '';
        }
    } catch (e) {
        console.error('MFA check error:', e);
    }
}

async function resendOtp() {
    const statusEl = document.getElementById('mfaStatus');
    statusEl.className = 'mfa-status';
    statusEl.textContent = 'Requesting new OTP...';

    try {
        const resp = await fetch('?api=resend_otp');
        const data = await resp.json();

        if (data.success) {
            statusEl.className = 'mfa-status success';
            statusEl.textContent = data.message;

            if (data.mfa_pending) {
                // SMS sent, show the OTP input immediately
                document.getElementById('mfaInputRow').style.display = 'flex';
                document.getElementById('mfaResend').style.display = 'none';
                document.getElementById('mfaDesc').textContent = 'An OTP code has been sent to your phone/email. Enter it below to restore the connection.';
                document.getElementById('otpInput').value = '';
                document.getElementById('otpInput').focus();
            } else {
                // Session restored without MFA
                setTimeout(() => {
                    document.getElementById('mfaPanel').classList.remove('visible');
                    fetchStatus();
                }, 2000);
            }
        } else {
            statusEl.className = 'mfa-status error';
            statusEl.textContent = data.error || 'Failed to request new OTP.';
        }
    } catch (e) {
        statusEl.className = 'mfa-status error';
        statusEl.textContent = 'Network error: ' + e.message;
    }
}

async function submitOtp() {
    const otpCode = document.getElementById('otpInput').value.trim();
    const statusEl = document.getElementById('mfaStatus');

    if (!otpCode) {
        statusEl.className = 'mfa-status error';
        statusEl.textContent = 'Please enter the OTP code.';
        return;
    }

    statusEl.className = 'mfa-status';
    statusEl.textContent = 'Submitting...';

    try {
        const resp = await fetch('?api=submit_otp&otp_code=' + encodeURIComponent(otpCode));
        const text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            statusEl.className = 'mfa-status error';
            statusEl.textContent = 'Server error: ' + text.substring(0, 200);
            return;
        }

        if (data.success) {
            statusEl.className = 'mfa-status success';
            statusEl.textContent = data.message;
            setTimeout(() => {
                document.getElementById('mfaPanel').classList.remove('visible');
                fetchStatus();
            }, 2000);
        } else {
            statusEl.className = 'mfa-status error';
            statusEl.textContent = data.error;
        }
    } catch (e) {
        statusEl.className = 'mfa-status error';
        statusEl.textContent = 'Network error: ' + e.message;
    }
}

// Allow Enter key to submit OTP
document.getElementById('otpInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') submitOtp();
});

// Initial load and auto-refresh
fetchStatus();
checkMfa();
setInterval(fetchStatus, 30000);
setInterval(checkMfa, 30000);
</script>
</body>
</html>