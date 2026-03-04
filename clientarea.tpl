{* Proxmox Custom Client Area — Dark Theme with Gradient Charts & Console *}

<style>
    .pve-panel {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 16px;
        color: #e0e0e0;
    }
    .pve-panel-title {
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: #818cf8;
        margin: 0 0 14px 0;
        font-weight: 600;
    }
    .pve-stat-row {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }
    .pve-stat-card {
        flex: 1;
        min-width: 180px;
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 10px;
        padding: 16px;
        text-align: center;
    }
    .pve-stat-label {
        font-size: 12px;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-bottom: 6px;
    }
    .pve-stat-value {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    .pve-stat-value.cpu { color: #2dd4bf; }
    .pve-stat-value.ram { color: #a78bfa; }
    .pve-stat-value.status-running { color: #34d399; }
    .pve-stat-value.status-stopped { color: #f87171; }
    .pve-stat-value.ip { color: #60a5fa; font-size: 18px; }
    .pve-gauge-bar {
        height: 6px;
        background: rgba(255,255,255,0.08);
        border-radius: 3px;
        overflow: hidden;
    }
    .pve-gauge-fill {
        height: 100%;
        border-radius: 3px;
        transition: width 0.6s ease;
    }
    .pve-gauge-fill.cpu { background: linear-gradient(90deg, #2dd4bf, #14b8a6); }
    .pve-gauge-fill.ram { background: linear-gradient(90deg, #a78bfa, #8b5cf6); }
    .pve-chart-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
        gap: 16px;
    }
    .pve-chart-card {
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 10px;
        padding: 16px;
        height: 240px;
        position: relative;
    }
    .pve-chart-card h4 {
        margin: 0 0 12px 0;
        font-size: 14px;
        font-weight: 500;
        color: #cbd5e1;
    }
    .pve-btn-row {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 8px;
    }
    .pve-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        transition: transform 0.15s, box-shadow 0.15s;
        color: #fff;
    }
    .pve-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        color: #fff;
        text-decoration: none;
    }
    .pve-btn-console {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
    }
    .pve-btn-start {
        background: linear-gradient(135deg, #22c55e, #16a34a);
    }
    .pve-btn-stop {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }
    .pve-btn-reboot {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }
    .pve-btn-panel {
        background: linear-gradient(135deg, #0ea5e9, #2563eb);
    }
    @media (max-width: 600px) {
        .pve-stat-row { flex-direction: column; }
        .pve-chart-grid { grid-template-columns: 1fr; }
    }
</style>

{if $errorMessage}
    <div class="alert alert-danger">
        <strong>Error:</strong> {$errorMessage}
    </div>
{else}

    {* ── Server Info & Live Stats ── *}
    <div class="pve-panel">
        <div class="pve-panel-title">Server Overview</div>
        <div class="pve-stat-row">
            <div class="pve-stat-card">
                <div class="pve-stat-label">Status</div>
                <div class="pve-stat-value {if $vmStatus == 'Running'}status-running{else}status-stopped{/if}">
                    {if $vmStatus == 'Running'}● Running{else}● Stopped{/if}
                </div>
            </div>
            <div class="pve-stat-card">
                <div class="pve-stat-label">CPU Usage</div>
                <div class="pve-stat-value cpu">{$cpuUsage}%</div>
                <div class="pve-gauge-bar"><div class="pve-gauge-fill cpu" style="width:{$cpuUsage}%"></div></div>
            </div>
            <div class="pve-stat-card">
                <div class="pve-stat-label">RAM Usage</div>
                <div class="pve-stat-value ram">{$ramUsage} <span style="font-size:14px;opacity:0.6">/ {$ramTotal} MB</span></div>
                {if $ramTotal > 0}
                    <div class="pve-gauge-bar"><div class="pve-gauge-fill ram" style="width:{$ramUsage / $ramTotal * 100}%"></div></div>
                {/if}
            </div>
            <div class="pve-stat-card">
                <div class="pve-stat-label">Primary IP</div>
                <div class="pve-stat-value ip">{$publicIP}</div>
            </div>
        </div>
    </div>

    {* ── Performance Graphs ── *}
    <div class="pve-panel">
        <div class="pve-panel-title">Performance — Last Hour</div>
        <div class="pve-chart-grid">
            <div class="pve-chart-card">
                <h4>🖥 CPU Usage</h4>
                <canvas id="cpuChart" height="160"></canvas>
            </div>
            <div class="pve-chart-card">
                <h4>🧠 Memory Usage</h4>
                <canvas id="memChart" height="160"></canvas>
            </div>
            <div class="pve-chart-card">
                <h4>🌐 Network I/O</h4>
                <canvas id="netChart" height="160"></canvas>
            </div>
            <div class="pve-chart-card">
                <h4>💾 Disk I/O</h4>
                <canvas id="diskChart" height="160"></canvas>
            </div>
        </div>
    </div>

    {* ── Action Buttons ── *}
    <div class="pve-panel">
        <div class="pve-panel-title">Quick Actions</div>
        <div class="pve-btn-row">
            <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=Start"
               class="pve-btn pve-btn-start" onclick="return confirm('Start this VM?');">
                <i class="fas fa-play"></i> Start
            </a>
            <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=Stop"
               class="pve-btn pve-btn-stop" onclick="return confirm('Stop this VM?');">
                <i class="fas fa-stop"></i> Stop
            </a>
            <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=Reboot"
               class="pve-btn pve-btn-reboot" onclick="return confirm('Reboot this VM?');">
                <i class="fas fa-sync-alt"></i> Reboot
            </a>
            {if $consoleEnabled}
            <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=Console"
               class="pve-btn pve-btn-console"
               onclick="window.open(this.href, 'pve_console', 'width=1024,height=768,resizable=yes,scrollbars=no'); return false;">
                <i class="fas fa-desktop"></i> Console
            </a>
            {/if}
        </div>
    </div>

    {* ── Chart.js ── *}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        try {
            const rrdData = {$rrdData};
            if (!rrdData || rrdData.length === 0) return;

            const labels = rrdData.map(d => {
                const t = new Date(d.time * 1000);
                return t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            });

            // Shared chart defaults
            const baseOpts = (max, unit) => ({
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, labels: { color: '#94a3b8', boxWidth: 12, padding: 12 } },
                    tooltip: {
                        backgroundColor: 'rgba(15,15,35,0.95)',
                        titleColor: '#e0e0e0',
                        bodyColor: '#cbd5e1',
                        borderColor: 'rgba(99,102,241,0.3)',
                        borderWidth: 1,
                        callbacks: { label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(1) + ' ' + unit }
                    }
                },
                scales: {
                    x: { ticks: { color: '#64748b', maxTicksLimit: 8, font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' } },
                    y: { beginAtZero: true, max: max, ticks: { color: '#64748b', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' } }
                },
                elements: { point: { radius: 0, hoverRadius: 4 }, line: { tension: 0.4, borderWidth: 2 } }
            });

            const makeGradient = (ctx, r, g, b) => {
                const grad = ctx.createLinearGradient(0, 0, 0, 160);
                grad.addColorStop(0, `rgba(${ r},${ g},${ b},0.35)`);
                grad.addColorStop(1, `rgba(${ r},${ g},${ b},0.02)`);
                return grad;
            };

            // —— CPU ——
            const cpuCtx = document.getElementById('cpuChart').getContext('2d');
            new Chart(cpuCtx, {
                type: 'line',
                data: { labels,
                    datasets: [{
                        label: 'CPU %',
                        data: rrdData.map(d => (d.cpu || 0) * 100),
                        borderColor: '#2dd4bf',
                        backgroundColor: makeGradient(cpuCtx, 45, 212, 191),
                        fill: true
                    }]
                },
                options: baseOpts(100, '%')
            });

            // —— Memory ——
            const memCtx = document.getElementById('memChart').getContext('2d');
            const memTotal = {$ramTotal};
            new Chart(memCtx, {
                type: 'line',
                data: { labels,
                    datasets: [{
                        label: 'RAM (MB)',
                        data: rrdData.map(d => (d.mem || 0) / (1024 * 1024)),
                        borderColor: '#a78bfa',
                        backgroundColor: makeGradient(memCtx, 167, 139, 250),
                        fill: true
                    }]
                },
                options: baseOpts(memTotal > 0 ? memTotal : 1024, 'MB')
            });

            // —— Network ——
            const netCtx = document.getElementById('netChart').getContext('2d');
            const netInData  = rrdData.map(d => (d.netin  || 0) / 1024);
            const netOutData = rrdData.map(d => (d.netout || 0) / 1024);
            const netMax = Math.max(50, ...netInData, ...netOutData) * 1.2;
            new Chart(netCtx, {
                type: 'line',
                data: { labels,
                    datasets: [
                        { label: 'In (KB/s)',  data: netInData,  borderColor: '#fb923c', backgroundColor: makeGradient(netCtx, 251, 146, 60), fill: true },
                        { label: 'Out (KB/s)', data: netOutData, borderColor: '#38bdf8', backgroundColor: 'transparent', fill: false }
                    ]
                },
                options: baseOpts(netMax, 'KB/s')
            });

            // —— Disk ——
            const diskCtx = document.getElementById('diskChart').getContext('2d');
            const diskRData = rrdData.map(d => (d.diskread  || 0) / 1024);
            const diskWData = rrdData.map(d => (d.diskwrite || 0) / 1024);
            const diskMax = Math.max(50, ...diskRData, ...diskWData) * 1.2;
            new Chart(diskCtx, {
                type: 'line',
                data: { labels,
                    datasets: [
                        { label: 'Read (KB/s)',  data: diskRData, borderColor: '#f472b6', backgroundColor: makeGradient(diskCtx, 244, 114, 182), fill: true },
                        { label: 'Write (KB/s)', data: diskWData, borderColor: '#a3e635', backgroundColor: 'transparent', fill: false }
                    ]
                },
                options: baseOpts(diskMax, 'KB/s')
            });

        } catch (e) {
            console.error("Failed to render charts:", e);
        }
    });
    </script>
{/if}