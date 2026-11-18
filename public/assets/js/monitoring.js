let chart = null;
let currentRange = '1h';
let autoRefreshTimer = null;
let autoRefreshPaused = false;

// Metadata port terpilih dari /api/monitoring_interfaces (oper_status + last_seen).
// Tanpa ini halaman Monitoring tidak tahu port sedang DOWN: grafik hanya berhenti di
// sampel terakhir yang masih UP dan port tampak "nyala" (temuan 18 Nov 2026).
let ifaceMeta = {};
let metaTimer = null;
let lastSampleAt = null;

const deviceSelect = document.getElementById('deviceSelect');
const interfaceSelect = document.getElementById('interfaceSelect');
const rangeSelect = document.getElementById('rangeSelect');

/* ======================================================
   INIT
====================================================== */
document.addEventListener('DOMContentLoaded', () => {
    initChart();
    loadDevices();

    if (rangeSelect) {
        rangeSelect.value = currentRange;
        rangeSelect.addEventListener('change', () => {
            setRangeUI(rangeSelect.value);
        });
    }
});

/* ======================================================
   AUTO REFRESH
====================================================== */
function startAutoRefresh() {
    stopAutoRefresh();

    const refreshIntervals = {
        '1h': 10000,
        '1d': 30000,
        '3d': 60000,
        '7d': 120000,
        '30d': 300000,
        '1y': 600000
    };

    autoRefreshTimer = setInterval(
        loadChart,
        refreshIntervals[currentRange] || 10000
    );
}

function stopAutoRefresh() {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
}

/* ======================================================
   METADATA PORT (status link)
===================================================== */
function fetchIfaceList() {
    const extra = showUnmonitoredEl && showUnmonitoredEl.checked ? '&include_unmonitored=1' : '';
    return fetch(`api/monitoring_interfaces?device_id=${encodeURIComponent(deviceSelect.value)}${extra}`)
        .then(r => r.json());
}

function startMetaRefresh() {
    stopMetaRefresh();
    metaTimer = setInterval(refreshIfaceMeta, 60000);
}

function stopMetaRefresh() {
    if (metaTimer) {
        clearInterval(metaTimer);
        metaTimer = null;
    }
}

// Status link berubah tanpa kita memilih ulang port: ambil ulang metadata tiap menit
// dan perbarui banner tanpa menyentuh daftar pilihan (pilihan user tidak hilang).
function refreshIfaceMeta() {
    if (!deviceSelect.value) return;

    fetchIfaceList()
        .then(ifs => {
            ifs.forEach(i => { ifaceMeta[String(i.if_index)] = i; });
            renderLinkStatus();
        })
        .catch(() => { /* biarkan status terakhir */ });
}

function metaForSelected() {
    const idx = interfaceSelect.value;
    return idx ? (ifaceMeta[String(idx)] || null) : null;
}

function isSelectedDown() {
    const meta = metaForSelected();
    return !!meta && meta.oper_status !== null && meta.oper_status !== undefined
        && Number(meta.oper_status) !== 1;
}

/* ======================================================
   LOAD DEVICES & INTERFACES
====================================================== */
function loadDevices() {
    fetch('api/monitoring_devices')
        .then(r => r.json())
        .then(devices => {
            deviceSelect.innerHTML =
                '<option value="">-- Pilih Device --</option>';

            devices.forEach(d => {
                deviceSelect.innerHTML += `
                    <option value="${escHtml(d.id)}">
                        ${escHtml(d.device_name)}
                    </option>`;
            });
        });
}

// Port "tidak dipakai" disembunyikan dari pilihan kecuali kotak centang dinyalakan.
const showUnmonitoredEl = document.getElementById('showUnmonitored');

function loadInterfaceOptions() {
    stopAutoRefresh();
    stopMetaRefresh();
    ifaceMeta = {};
    lastSampleAt = null;
    interfaceSelect.innerHTML =
        '<option value="">-- Pilih Interface --</option>';

    if (!deviceSelect.value) return;

    fetchIfaceList()
        .then(ifs => {
            ifs.forEach(i => {
                ifaceMeta[String(i.if_index)] = i;
                const off = i.is_monitored === false ? ' (tidak dipakai)' : '';
                // Penanda status langsung di daftar: port mati tidak lagi tampak setara
                // dengan port yang hidup saat memilih.
                const down = i.oper_status !== null && i.oper_status !== undefined && Number(i.oper_status) !== 1;
                interfaceSelect.innerHTML += `
                    <option value="${escHtml(i.if_index)}"
                            data-name="${escHtml(i.if_name)}"
                            data-alias="${escHtml(i.if_alias || '')}">
                        ${escHtml(i.if_name)}${i.if_alias ? ' — ' + escHtml(i.if_alias) : ''}${escHtml(off)}${down ? ' ⛔ DOWN' : ''}
                    </option>`;
            });
        });
}

deviceSelect.addEventListener('change', loadInterfaceOptions);
if (showUnmonitoredEl) showUnmonitoredEl.addEventListener('change', loadInterfaceOptions);

interfaceSelect.addEventListener('change', () => {
    const opt = interfaceSelect.selectedOptions[0];
    if (!opt) return;

    renderLinkStatus();

    loadChart();
    startAutoRefresh();
    startMetaRefresh();
});

// Pause polling when tab is hidden (reduce load)
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        if (autoRefreshTimer) {
            autoRefreshPaused = true;
            stopAutoRefresh();
            stopMetaRefresh();
        }
        return;
    }

    if (autoRefreshPaused && interfaceSelect.value) {
        autoRefreshPaused = false;
        startAutoRefresh();
        startMetaRefresh();
    }
});

/* ======================================================
   RANGE
====================================================== */
function setRange(r) {
    currentRange = r;
    loadChart();
    startAutoRefresh();
}

function setRangeUI(r) {
    currentRange = r;

    if (rangeSelect) rangeSelect.value = r;

    loadChart();
    startAutoRefresh();
}

/* ======================================================
   INIT CHART (TIME SCALE, 24 JAM WIB)
====================================================== */
function initChart() {
    const ctx = document.getElementById('opticalChart').getContext('2d');

    chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                data: [],
                borderColor: '#ffe14a',
                backgroundColor: 'rgba(255,255,255,0.06)',
                borderWidth: 2,
                tension: 0.25,
                pointRadius: 0,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.parsed.y.toFixed(2)} dBm`
                    }
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        tooltipFormat: 'dd MMM yyyy HH:mm' // 24 JAM
                    },
                    ticks: {
                        maxTicksLimit: 10,
                        callback: (value) => {
                            const d = new Date(value);
                            return new Intl.DateTimeFormat('id-ID', {
                                hour: '2-digit',
                                minute: '2-digit',
                                hour12: false 
                            }).format(d);
                        }

                    },
                    grid: {
                        color: 'rgba(255,255,255,0.05)'
                    }
                },
                y: {
                    ticks: {
                        callback: v => v.toFixed(2) + ' dBm'
                    }
                }
            }
        }
    });
}


/* ======================================================
   LOAD CHART DATA
====================================================== */
function loadChart() {
    if (!chart || !deviceSelect.value || !interfaceSelect.value) return;

    fetch(
        `api/interface_chart?device_id=${deviceSelect.value}` +
        `&if_index=${interfaceSelect.value}&range=${currentRange}`
    )
        .then(r => r.json())
        .then(data => {
            if (!data.length) {
                // Tampilkan pesan atau chart kosong
                lastSampleAt = null;
                updateStats([-40]); // Default -40 dBm
                renderLinkStatus();
                return;
            }

            // Waktu sampel terakhir dipakai untuk menandai data yang sudah basi.
            lastSampleAt = data[data.length - 1].created_at;

            // Filter dan map data
            const rx = data.map(d => {
                let value = Number(d.rx_power);
                // Jika null/NaN, set ke -40 (interface down)
                return isNaN(value) ? -40 : value;
            });

            let labels = data.map(d =>
                new Date(d.created_at.replace(' ', 'T'))
            );
            let rxData = rx;

            if (data.length > 100) {
                const step = Math.ceil(data.length / 120);
                labels = [];
                rxData = [];

                for (let i = 0; i < data.length; i += step) {
                    const chunk = data.slice(i, i + step);
                    if (!chunk.length) continue;

                    let min = null, max = null;
                    let minT = null, maxT = null;

                    chunk.forEach(row => {
                        const v = isNaN(Number(row.rx_power)) ? -40 : Number(row.rx_power);
                        const t = new Date(row.created_at.replace(' ', 'T'));
                        if (min === null || v < min) { min = v; minT = t; }
                        if (max === null || v > max) { max = v; maxT = t; }
                    });

                    const points = [];
                    if (minT && maxT) {
                        if (minT <= maxT) {
                            points.push([minT, min], [maxT, max]);
                        } else {
                            points.push([maxT, max], [minT, min]);
                        }
                    } else if (minT) {
                        points.push([minT, min]);
                    }

                    points.forEach(([t, v]) => {
                        labels.push(t);
                        rxData.push(v);
                    });
                }

                // Pastikan titik terakhir selalu ikut
                const lastIdx = data.length - 1;
                const lastLabel = new Date(data[lastIdx].created_at.replace(' ', 'T'));
                if (!labels.length || labels[labels.length - 1].getTime() !== lastLabel.getTime()) {
                    labels.push(lastLabel);
                    const lastValue = Number(data[lastIdx].rx_power);
                    rxData.push(isNaN(lastValue) ? -40 : lastValue);
                }
            }

            // ⏱️ UNIT WAKTU PER RANGE
            const timeUnits = {
                '1h': 'minute',
                '1d': 'hour',
                '3d': 'hour',
                '7d': 'day',
                '30d': 'day',
                '1y': 'month'
            };

            chart.options.scales.x.time.unit = timeUnits[currentRange];

            // 🔒 PAKSA FORMAT 24 JAM (TANPA AM/PM)
            chart.options.scales.x.ticks.callback = (value) => {
                const d = new Date(value);

                const formats = {
                    '1h': { hour: '2-digit', minute: '2-digit' },
                    '1d': { hour: '2-digit', minute: '2-digit' },
                    '3d': { weekday: 'short', hour: '2-digit' },
                    '7d': { weekday: 'short', day: '2-digit' },
                    '30d': { day: '2-digit', month: 'short' },
                    '1y': { month: 'short', year: '2-digit' }
                };

                return new Intl.DateTimeFormat(
                    'id-ID',
                    { ...formats[currentRange], hour12: false }
                ).format(d);
            };

            const primaryColor = getComputedStyle(document.documentElement)
                .getPropertyValue('--primary')
                .trim() || '#ffe14a';

            chart.data.labels = labels;
            chart.data.datasets[0].data = rxData;
            chart.data.datasets[0].borderColor = primaryColor;

            // Update chart warna untuk nilai -40 (interface down)
            chart.data.datasets[0].segment = {
                borderColor: ctx => {
                    return ctx.p1.parsed.y === -40 ?
                        'rgba(255, 99, 132, 0.5)' : 
                        primaryColor; 
                }
            };

            chart.update();
            updateStats(rx);
            renderLinkStatus();
        })
        .catch(err => {
            console.error('Error loading chart:', err);
            lastSampleAt = null;
            updateStats([-40]); // Fallback ke -40 dBm
            renderLinkStatus();
        });
}

/* ======================================================
   BANNER STATUS LINK
===================================================== */
// Ambang "data basi" per range (menit). Range panjang memang berbucket jam/hari,
// jadi toleransinya lebih lebar.
const STALE_MINUTES = { '1h': 5, '1d': 20, '3d': 30, '7d': 120 };

function fmtClock(value) {
    const d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(value);

    const pad = n => String(n).padStart(2, '0');
    const sameDay = d.toDateString() === new Date().toDateString();
    const hm = `${pad(d.getHours())}:${pad(d.getMinutes())}`;

    return sameDay ? hm : `${pad(d.getDate())}/${pad(d.getMonth() + 1)} ${hm}`;
}

function ageSuffix() {
    const limit = STALE_MINUTES[currentRange];
    if (!lastSampleAt || !limit) return '';

    const d = new Date(String(lastSampleAt).replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';

    const mins = Math.round((Date.now() - d.getTime()) / 60000);
    if (mins <= limit) return '';
    if (mins < 60) return ` — ${mins} menit lalu (basi)`;

    const hours = Math.floor(mins / 60);
    if (hours < 48) return ` — ${hours} jam lalu (basi)`;

    return ` — ${Math.floor(hours / 24)} hari lalu (basi)`;
}

function renderLinkStatus() {
    const el = document.getElementById('ifaceInfo');
    const opt = interfaceSelect.selectedOptions[0];
    if (!el || !opt || !opt.value) return;

    const name = opt.dataset.name || '';
    const alias = opt.dataset.alias || '';
    const meta = metaForSelected() || {};
    const down = isSelectedDown();
    const parts = [];

    parts.push(`Interface: <b>${escHtml(name)}</b>`);
    if (alias) parts.push(`<span class="mon-iface-alias">(${escHtml(alias)})</span>`);

    if (meta.oper_status !== null && meta.oper_status !== undefined) {
        parts.push(down
            ? '<span class="mon-link-chip mon-link-chip--down">⛔ PORT DOWN</span>'
            : '<span class="mon-link-chip mon-link-chip--up">● LINK UP</span>');
    }

    if (lastSampleAt) {
        parts.push(`<span class="mon-iface-note">data optik terakhir ${escHtml(fmtClock(lastSampleAt))}${escHtml(ageSuffix())}</span>`);
    }

    el.classList.toggle('is-down', down);
    el.innerHTML = '<i class="fas fa-circle-nodes"></i>'
        + `<span class="mon-iface-text">${parts.join(' ')}</span>`;
}

/* ======================================================
   STATS
====================================================== */
function updateStats(rx) {
    const setCard = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    };
    const unitEl = document.querySelector('.mon-stat--now .mon-stat__unit');
    const badgeEl = document.getElementById('statNowBadge');

    const setBadge = (cls, text) => {
        if (!badgeEl) return;
        badgeEl.className = 'mon-rx-badge ' + cls;
        badgeEl.textContent = text;
        badgeEl.style.display = 'inline-block';
    };

    if (!rx || rx.length === 0) {
        setCard('statNow', '—');
        setCard('statAvg', '—');
        setCard('statMin', '—');
        setCard('statMax', '—');
        if (badgeEl) badgeEl.style.display = 'none';
        return;
    }

    const now = rx.at(-1);

    // Filter nilai -40 untuk perhitungan avg, min, max
    const validValues = rx.filter(v => v > -40);

    let avg, min, max;

    if (validValues.length > 0) {
        avg = validValues.reduce((a, b) => a + b, 0) / validValues.length;
        min = Math.min(...validValues);
        max = Math.max(...validValues);
    } else {
        avg = -40;
        min = -40;
        max = -40;
    }

    // Port yang benar-benar DOWN (oper_status ≠ 1): jangan tampilkan angka optik
    // terakhir sebelum link putus. Angka itu yang dulu membuat port mati terlihat
    // "masih nyala" dan seolah tidak pernah update.
    if (isSelectedDown()) {
        setCard('statNow', 'LOS');
        if (unitEl) unitEl.style.display = 'none';
        setBadge('mon-rx-down', 'DOWN');
    } else {
        if (unitEl) unitEl.style.display = '';
        setCard('statNow', now.toFixed(2));
        setBadgeByValue(now, setBadge);
    }

    setCard('statAvg', avg.toFixed(2));
    setCard('statMin', min.toFixed(2));
    setCard('statMax', max.toFixed(2));
}

// Level RX memakai ambang dari pengaturan alert (dikirim /api/monitoring_interfaces),
// bukan angka hardcode, supaya badge sama dengan yang dipakai pengirim notifikasi.
function setBadgeByValue(value, setBadge) {
    const meta = metaForSelected() || {};
    const down = isFinite(Number(meta.rx_down_threshold)) ? Number(meta.rx_down_threshold) : -40;
    const warnLow = isFinite(Number(meta.rx_warn_low)) ? Number(meta.rx_warn_low) : -25;
    const warnHigh = isFinite(Number(meta.rx_warn_high)) ? Number(meta.rx_warn_high) : -18;

    if (value <= down) {
        setBadge('mon-rx-down', 'LOS');
    } else if (value < warnLow) {
        setBadge('mon-rx-crit', 'CRITICAL');
    } else if (value <= warnHigh) {
        setBadge('mon-rx-warn', 'WARN');
    } else {
        setBadge('mon-rx-ok', 'NORMAL');
    }
}
