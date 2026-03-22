# Solar Charge Controller for Rivian + Tesla Powerwall 3

A PHP script that monitors your Tesla Powerwall 3's solar production via the Tesla cloud API and dynamically adjusts your Rivian's charging amperage so the vehicle only charges when you have surplus solar energy. No grid power wasted on charging.

## How It Works

1. Checks the Rivian vehicle's battery level and charge limit for override conditions (low battery or trip mode)
2. Polls the Tesla cloud API every 5 minutes for real-time solar production, grid import/export, Powerwall charge level, and home consumption
3. Calculates available surplus from two sources: energy being exported to the grid, plus energy flowing into the Powerwall (once above the configurable threshold, the Powerwall's charge rate is shared with the vehicle)
4. Converts surplus watts to amps and updates the Rivian's charging schedule via the unofficial Rivian GraphQL API
5. If surplus drops below the minimum threshold, blocks charging by setting an expired schedule window on the vehicle
6. Scales amperage dynamically: more sun = faster charging, less sun = slower or no charging
7. Overrides solar-only mode when the Rivian's battery is critically low, or when you signal a trip by raising the charge limit in the Rivian app

## Requirements

- PHP 8.0+ with the `curl` extension
- Tesla account with a Powerwall 3 (or any Tesla solar/energy product accessible via the Owners API)
- Rivian account with a registered vehicle
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
        "min_solar_watts": 600,
        "min_amps": 8,
        "max_amps": 48,
        "powerwall_min_battery_pct": 20,
        "rivian_min_battery_pct": 20,
        "rivian_full_charge_limit_pct": 85,
        "poll_interval_seconds": 300,
        "week_days": ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"]
    }
}
```

### Charging parameters

| Parameter | Default | Description |
|---|---|---|
| `min_solar_watts` | 600 | Minimum surplus watts before charging starts |
| `min_amps` | 8 | Minimum amperage to send to the vehicle |
| `max_amps` | 48 | Maximum amperage (should match your charger's hardware limit) |
| `powerwall_min_battery_pct` | 20 | Powerwall must be above this % before sharing its charge rate with the vehicle |
| `rivian_min_battery_pct` | 20 | If the Rivian battery is below this %, charge at full power regardless of solar |
| `rivian_full_charge_limit_pct` | 85 | If the Rivian's charge limit is set at or above this %, charge at full power (trip mode) |
| `poll_interval_seconds` | 300 | How often to check solar production (daemon mode) |

### Override behaviors

The script supports two overrides that bypass solar-only charging:

**Low battery protection**: If your Rivian's battery drops below `rivian_min_battery_pct` (default 20%), the script charges at full power until the battery is above the threshold. This prevents your vehicle from sitting dead in the driveway on a cloudy week.

**Trip mode**: If you set your Rivian's charge limit to a value at or above `rivian_full_charge_limit_pct` (default 85%), the script charges at full power regardless of solar conditions. This gives you a simple way to signal "I need a full charge for a trip" just by adjusting the charge limit in the Rivian app. When you're back to normal daily driving, set your charge limit back below the threshold (e.g. 70%) and the script returns to solar-only mode.

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

The script controls charging by manipulating the Rivian's charging schedule via the GraphQL API. To enable charging, it sets a schedule that spans the full day at the calculated amperage. To block charging, it sets an enabled schedule with an expired 1-minute window, which puts the vehicle into "outside scheduled window" state and prevents it from drawing power.

The vehicle's onboard charger controls the actual draw, so this works with the Rivian Wall Charger, a third-party J1772 EVSE, or the portable charger. The Wall Charger's dip switches (or EVSE rating) set the hardware ceiling, and the vehicle software throttles below that based on the schedule.

Surplus solar is calculated from two sources: energy being exported to the grid (wasted energy), plus energy flowing into the Powerwall when it's above the configured threshold. This means the Powerwall and vehicle share solar production rather than the Powerwall monopolizing it. The Powerwall's own charge controller naturally absorbs whatever the vehicle doesn't use.

## Session Management

**Tesla**: Tokens auto-refresh transparently. The access token lasts about 8 hours, and the refresh token is exchanged automatically. Tesla rotates refresh tokens on each use, and the script updates `config.json` accordingly.

**Rivian**: Sessions last approximately 7 days. Running the script every 5 minutes keeps the session alive indefinitely through regular API activity. If the session does expire (e.g., your machine was off for a week), re-run `--rivian-setup` and enter a new MFA code.

## Important Notes

- The Rivian API is unofficial and may change with app updates
- The Tesla Owners API is also unofficial but has been stable for years
- The Powerwall 3 has no local LAN API, so all Tesla data comes through the cloud
- The script does not wake the Rivian vehicle; charging schedules are managed server-side
- Credentials are stored in plain text in `config.json`; protect this file appropriately

## Authors

- **Peter Chester** ([@peterchester](https://github.com/peterchester))
- **Claude** ([Anthropic](https://www.anthropic.com)) - AI pair programmer

## License

MIT - See [LICENSE](LICENSE) for details.