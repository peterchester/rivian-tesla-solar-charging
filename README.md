# Solar Charge Controller for Rivian R1T + Tesla Powerwall 3

A PHP script created using claude.ai that monitors your Tesla Powerwall 3's solar production via the Tesla cloud API and dynamically adjusts your Rivian R1T's charging amperage so the truck only charges when you have surplus solar energy. No grid power wasted on charging.

## How It Works

1. Polls the Tesla cloud API every 5 minutes for real-time solar production, grid import/export, battery charge level, and home consumption
2. Calculates how much surplus solar is available (energy being exported to the grid)
3. Prioritizes your Powerwall: the truck won't charge until the battery is above a configurable threshold (default 90%)
4. Converts surplus watts to amps and updates the Rivian's charging schedule via the unofficial Rivian GraphQL API
5. If surplus drops below the minimum threshold, charging is disabled
6. Scales amperage dynamically: more sun = faster charging, less sun = slower or no charging

## Requirements

- PHP 8.0+ with the `curl` extension
- Tesla account with a Powerwall 3 (or any Tesla solar/energy product accessible via the Owners API)
- Rivian account with an R1T or R1S
- macOS, Linux, or any system that can run PHP CLI

## Quick Start

### 1. Generate the config file

```bash
php solar_charge.php
```

This creates a `config.json` template. Edit it to add your Rivian email and password.

### 2. Set up Tesla

```bash
php solar_charge.php --tesla-setup
```

Opens your browser to Tesla's OAuth login page. Log in, complete MFA, and paste the callback URL back into the terminal. The script automatically writes your refresh token and energy site ID into `config.json`.

### 3. Set up Rivian

```bash
php solar_charge.php --rivian-setup
```

Authenticates with Rivian (you'll enter an MFA code sent to your phone/email), then automatically discovers and writes your vehicle ID, Wall Charger ID, and home coordinates into `config.json`.

### 4. Verify everything works

```bash
php solar_charge.php --status
```

Shows live solar production, surplus calculation, charging decision, Wall Charger status, and current charging session info.

### 5. Run it

Single run:
```bash
php solar_charge.php
```

Daemon mode (polls continuously):
```bash
php solar_charge.php --daemon
```

## Automating with launchd (macOS)

Create `~/Library/LaunchAgents/com.solar.charge.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.solar.charge</string>
    <key>ProgramArguments</key>
    <array>
        <string>/opt/homebrew/bin/php</string>
        <string>/path/to/solar_charge.php</string>
    </array>
    <key>StartInterval</key>
    <integer>300</integer>
    <key>RunAtLoad</key>
    <true/>
    <key>StandardOutPath</key>
    <string>/path/to/solar_charge_stdout.log</string>
    <key>StandardErrorPath</key>
    <string>/path/to/solar_charge_stderr.log</string>
</dict>
</plist>
```

Update the paths, then load it:

```bash
launchctl load ~/Library/LaunchAgents/com.solar.charge.plist
```

## Automating with cron (Linux)

```bash
*/5 * * * * /usr/bin/php /path/to/solar_charge.php >> /path/to/solar_charge_cron.log 2>&1
```

## Configuration

After running the setup commands, `config.json` will look something like this:

```json
{
    "tesla": {
        "refresh_token": "eyJ...(auto-populated)...",
        "site_id": "1234567890"
    },
    "rivian": {
        "email": "you@example.com",
        "password": "your-password",
        "vehicle_id": "DEADBEEF-1234-...(auto-populated)..."
    },
    "charging": {
        "home_latitude": 37.1234,
        "home_longitude": -122.5678,
        "min_solar_watts": 1200,
        "min_amps": 8,
        "max_amps": 48,
        "battery_soe_threshold": 90,
        "poll_interval_seconds": 300,
        "week_days": ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"]
    }
}
```

### Charging parameters

| Parameter | Default | Description |
|---|---|---|
| `min_solar_watts` | 1200 | Minimum surplus watts before charging starts (~5A at 240V) |
| `min_amps` | 8 | Minimum amperage to send to the vehicle |
| `max_amps` | 48 | Maximum amperage (should match your Wall Charger's dip switch setting) |
| `battery_soe_threshold` | 90 | Powerwall must be above this % before truck gets any solar |
| `poll_interval_seconds` | 300 | How often to check solar production (daemon mode) |

## Commands

| Command | Description |
|---|---|
| `php solar_charge.php` | Single run: check solar and adjust charging |
| `php solar_charge.php --tesla-setup` | Tesla OAuth login, auto-configure token and site ID |
| `php solar_charge.php --rivian-setup` | Rivian MFA login, auto-configure vehicle and charger |
| `php solar_charge.php --status` | Show live solar data, charging decision, and charger status |
| `php solar_charge.php --daemon` | Run continuously, polling at the configured interval |

## Files Created

| File | Purpose |
|---|---|
| `config.json` | All configuration (credentials, IDs, charging parameters) |
| `tesla_token.json` | Cached Tesla access token (auto-refreshes) |
| `rivian_session.json` | Cached Rivian session tokens (~7 day lifespan) |
| `charge_state.json` | Last known charging state (prevents redundant API calls) |
| `solar_charge.log` | Activity log |

## How Charging Control Works

The script doesn't directly talk to the Rivian Wall Charger. Instead, it uses Rivian's `SetChargingSchedule` GraphQL mutation to set the amperage on the vehicle itself. The truck's onboard charger then draws up to that amount from whatever EVSE it's plugged into. This works with the Rivian Wall Charger, a third-party J1772 EVSE, or the portable charger.

The Wall Charger's dip switches set the hardware ceiling (e.g., 48A on a 60A circuit), and the vehicle software throttles below that based on the schedule.

## Session Management

**Tesla**: Tokens auto-refresh transparently. The access token lasts about 8 hours, and the refresh token is exchanged automatically. Tesla rotates refresh tokens on each use, and the script updates `config.json` accordingly.

**Rivian**: Sessions last approximately 7 days. Running the script every 5 minutes keeps the session alive indefinitely through regular API activity. If the session does expire (e.g., your machine was off for a week), re-run `--rivian-setup` and enter a new MFA code.

## Important Notes

- The Rivian API is unofficial and may change with app updates
- The Tesla Owners API is also unofficial but has been stable for years
- The Powerwall 3 has no local LAN API, so all Tesla data comes through the cloud
- The script does not wake the Rivian vehicle; charging schedules are managed server-side
- Credentials are stored in plain text in `config.json`; protect this file appropriately

## License

MIT
