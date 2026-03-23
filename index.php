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
            $input = json_decode(file_get_contents('php://input'), true);
            $newMode = ($input['mode'] ?? '') === 'schedule' ? 'schedule' : 'solar';
            file_put_contents("$baseDir/charge_mode.json", json_encode([
                'mode'       => $newMode,
                'changed_at' => time(),
            ], JSON_PRETTY_PRINT));
            echo json_encode(['success' => true, 'mode' => $newMode]);
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
<title>Solar Charge Controller</title>
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

h1 .sun {
    font-size: 1.8rem;
    filter: drop-shadow(0 0 8px var(--solar));
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
        <h1><span class="sun">☀️</span> Solar Charge Controller</h1>
        <div>
            <div class="mode-toggle">
                <button class="mode-btn" data-mode="solar" onclick="setMode('solar')">Solar Mode</button>
                <button class="mode-btn" data-mode="schedule" onclick="setMode('schedule')">Schedule Mode</button>
            </div>
            <div class="tou-info" id="touInfo"></div>
        </div>
    </header>

    <div class="daemon-alert" id="daemonAlert"></div>

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

    <div class="chart-section">
        <h2>Power Flow (last 24h)</h2>
        <canvas id="powerChart"></canvas>
        <div class="legend">
            <div class="legend-item"><div class="legend-dot" style="background:var(--solar)"></div> Solar</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--home)"></div> Home</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--grid)"></div> Grid</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--battery-pw)"></div> Powerwall</div>
        </div>
    </div>

    <div class="chart-section">
        <h2>Charging Rate &amp; Battery Levels (last 24h)</h2>
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

const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 400 },
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
        const isActiveCharge = ['Solar Charging', 'Override Charging'].includes(displayStatus);
        const isWaiting = ['Waiting for the Sun', 'Scheduled'].includes(displayStatus);
        const isComplete = displayStatus === 'Charge Complete';
        const isUnplugged = displayStatus === 'Unplugged';

        statusCard.className = 'card'
            + (isActiveCharge ? ' status-charging' : '')
            + (isWaiting ? ' status-blocked' : '')
            + (isComplete ? ' pw' : '')
            + (isUnplugged ? '' : '');

        document.getElementById('chargeStatus').textContent = displayStatus;

        if (isActiveCharge) {
            document.getElementById('chargeAmps').textContent = currentAmps + 'A / ' + (currentAmps * 240) + 'W';
        } else if (isUnplugged) {
            document.getElementById('chargeAmps').textContent = 'Vehicle disconnected';
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

        // Daemon health check
        const daemonAlert = document.getElementById('daemonAlert');
        if (state.last_update) {
            const ageSeconds = Math.floor(Date.now() / 1000) - state.last_update;
            const ageMinutes = Math.floor(ageSeconds / 60);

            if (ageSeconds > 1800) {
                // Over 30 minutes: likely crashed
                daemonAlert.className = 'daemon-alert error';
                daemonAlert.textContent = '⚠ Daemon appears to have stopped (' + ageMinutes + ' min since last update). Check the terminal or restart with: php solar_charge.php --daemon';
            } else if (ageSeconds > 600) {
                // Over 10 minutes: might be stalled
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
        updateCharts(history);
    } catch (e) {
        console.error('Fetch error:', e);
    }
}

function updateCharts(history) {
    // Filter to last 24h
    const cutoff = Date.now() / 1000 - 24 * 3600;
    const data = history.filter(h => h.timestamp >= cutoff);

    const labels = data.map(h => formatTime(h.timestamp));

    // Power chart
    const powerData = {
        labels,
        datasets: [
            { label: 'Solar', data: data.map(h => (h.solar_w ?? 0) / 1000), borderColor: '#f6ad35', backgroundColor: 'rgba(246,173,53,0.1)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 },
            { label: 'Home', data: data.map(h => (h.load_w ?? 0) / 1000), borderColor: '#9f7aea', backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 2 },
            { label: 'Grid', data: data.map(h => (h.grid_w ?? 0) / 1000), borderColor: '#e53e3e', backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 1.5, borderDash: [4,4] },
            { label: 'Powerwall', data: data.map(h => (h.battery_w ?? 0) / 1000), borderColor: '#48bb78', backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 1.5 },
        ]
    };

    const powerOpts = JSON.parse(JSON.stringify(chartDefaults));
    powerOpts.scales.y.title = { display: true, text: 'kW', color: '#8892a4', font: { family: 'JetBrains Mono', size: 11 } };

    if (powerChart) {
        powerChart.data = powerData;
        powerChart.update('none');
    } else {
        powerChart = new Chart(document.getElementById('powerChart'), { type: 'line', data: powerData, options: powerOpts });
    }

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
        chargeChart.data = chargeData;
        chargeChart.update('none');
    } else {
        chargeChart = new Chart(document.getElementById('chargeChart'), { type: 'line', data: chargeData, options: chargeOpts });
    }
}

async function setMode(mode) {
    try {
        await fetch('?api=set_mode', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode })
        });
        fetchStatus();
    } catch (e) {
        console.error('Mode set error:', e);
    }
}

// Initial load and auto-refresh
fetchStatus();
setInterval(fetchStatus, 30000);
</script>
</body>
</html>