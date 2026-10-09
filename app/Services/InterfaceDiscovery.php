<?php

namespace App\Services;

use App\Services\Optical\OpticalDriverResolver;
use App\Services\Optical\SnmpSession;
use App\Support\Secret;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\FcmService;
use Illuminate\Support\Facades\Log;

class InterfaceDiscovery
{
    /** Ports not reported by the device for this long are auto-retired (see reconcileVanishedPorts). */
    private const VANISHED_AFTER_HOURS = 24;

    /**
     * Peran yang menerima push alert produksi. Viewer adalah akun demo (semua layarnya
     * berisi data dummy), jadi tidak boleh menerima nama perangkat/port/alias asli lewat push.
     */
    public const PUSH_ROLES = ['admin', 'technician'];

    private static bool $alertLogTableChecked = false;

    public function discover(int $deviceId, bool $isCli = false): array
    {
        if (!function_exists('snmp2_walk') || !function_exists('snmp2_get')) {
            return [
                'success' => false,
                'error' => 'SNMP extension not installed',
            ];
        }

        $device = DB::table('snmp_devices')->where('id', $deviceId)->first();
        if (!$device) {
            return [
                'success' => false,
                'error' => 'Device not found',
            ];
        }

        $ip = $device->ip_address;
        $community = Secret::reveal($device->community); // NP-4: terenkripsi at-rest, fallback plaintext
        if (!$community) {
            return [
                'success' => false,
                'error' => 'SNMP community not configured',
            ];
        }

        $alertSettings = $this->loadAlertSettings();
        // Per-device alert state file so parallel per-device polling can't race
        // on a shared file (each worker only touches its own device's state).
        $alertStateFile = storage_path('app/alert_state/' . $deviceId . '.json');
        $alertState = $isCli ? $this->loadAlertState($alertStateFile, $deviceId) : [];
        $alertStateDirty = false;

        // 2s timeout + 2 retries (tolerance up to ~6s for lossy/wireless links).
        // If the initial probe fails completely, skip remaining walks immediately
        // so offline devices don't stall the cycle for 60+ seconds.
        $snmpTimeout = 2000000; // 2s
        $snmpRetries = 2;

        $ifIndex = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.2.2.1.1', $snmpTimeout, $snmpRetries);

        if (!$ifIndex) {
            $this->markDeviceStatus($deviceId, false, 'SNMP tidak menjawab (IF-MIB ifIndex) saat polling');
            if ($isCli) {
                $deviceLabel = trim(($device->device_name ?? '') . ' (' . $ip . ')');
                $timeLabel = date('Y-m-d H:i:s');
                $devKey = "dev:{$deviceId}";
                $prevDev = $alertState[$devKey] ?? null;
                $prevKnown = is_array($prevDev);
                $prevUp = $prevKnown ? (bool) ($prevDev['device_up'] ?? false) : true;

                $alertState[$devKey] = [
                    'device_up' => false,
                    'last_check' => $timeLabel,
                ];
                $this->saveAlertState($alertStateFile, $alertState);

                if ($prevKnown && $prevUp && ($alertSettings['device_down'] ?? true)) {
                    $msg = "🔴 DEVICE DOWN\n📟 Device: {$deviceLabel}\n🕒 Time: {$timeLabel}";
                    $this->emitAlert(
                        $alertSettings,
                        [
                            'device_id' => $deviceId,
                            'device_name' => (string) ($device->device_name ?? ''),
                            'device_ip' => (string) $ip,
                        ],
                        null,
                        'device_down',
                        'critical',
                        "Device down: {$deviceLabel}",
                        $msg
                    );
                }

                return [
                    'success' => false,
                    'error' => "SKIP device ID: {$deviceId} (IF-MIB unreachable)",
                ];
            }

            return [
                'success' => false,
                'error' => 'Cannot read IF-MIB',
            ];
        }

        $ifName = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.31.1.1.1.1', $snmpTimeout, $snmpRetries);
        $ifDescr = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.2.2.1.2', $snmpTimeout, $snmpRetries);
        $ifAlias = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.31.1.1.1.18', $snmpTimeout, $snmpRetries);
        $ifOper = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.2.2.1.8', $snmpTimeout, $snmpRetries);

        // Speed (Mbps) and 64-bit traffic counters. Fallback to 32-bit if HC unavailable.
        $ifHighSpeed = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.31.1.1.1.15', $snmpTimeout, $snmpRetries);
        $ifSpeed = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.2.2.1.5', $snmpTimeout, $snmpRetries);
        $ifHCIn = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.31.1.1.1.6', $snmpTimeout, $snmpRetries);
        $ifHCOut = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.31.1.1.1.10', $snmpTimeout, $snmpRetries);
        $ifIn = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.2.2.1.10', $snmpTimeout, $snmpRetries);
        $ifOut = @\snmp2_walk($ip, $community, '1.3.6.1.2.1.2.2.1.16', $snmpTimeout, $snmpRetries);

        // Device up/down alert (CLI only, transition based)
        if ($isCli) {
            $deviceLabel = trim(($device->device_name ?? '') . ' (' . $ip . ')');
            $timeLabel = date('Y-m-d H:i:s');
            $devKey = "dev:{$deviceId}";
            $prevDev = $alertState[$devKey] ?? null;
            $prevKnown = is_array($prevDev);
            $prevUp = $prevKnown ? (bool) ($prevDev['device_up'] ?? false) : true;
            $nowUp = (bool) ($ifIndex && $ifName);

            $alertState[$devKey] = [
                'device_up' => $nowUp,
                'last_check' => $timeLabel,
            ];
            $alertStateDirty = true;

            if ($prevKnown && $prevUp && !$nowUp && ($alertSettings['device_down'] ?? true)) {
                $msg = "🔴 DEVICE DOWN\n📟 Device: {$deviceLabel}\n🕒 Time: {$timeLabel}";
                $this->emitAlert(
                    $alertSettings,
                    [
                        'device_id' => $deviceId,
                        'device_name' => (string) ($device->device_name ?? ''),
                        'device_ip' => (string) $ip,
                    ],
                    null,
                    'device_down',
                    'critical',
                    "Device down: {$deviceLabel}",
                    $msg
                );
            } elseif ($prevKnown && !$prevUp && $nowUp && ($alertSettings['device_up'] ?? true)) {
                $msg = "🟢 DEVICE UP\n📟 Device: {$deviceLabel}\n🕒 Time: {$timeLabel}";
                $this->emitAlert(
                    $alertSettings,
                    [
                        'device_id' => $deviceId,
                        'device_name' => (string) ($device->device_name ?? ''),
                        'device_ip' => (string) $ip,
                    ],
                    null,
                    'device_up',
                    'info',
                    "Device up: {$deviceLabel}",
                    $msg
                );
            }
        }

        if (!$ifName) {
            $this->markDeviceStatus($deviceId, false, 'SNMP tidak menjawab (IF-MIB ifName) saat polling');
            if ($isCli && $alertStateDirty) {
                $this->saveAlertState($alertStateFile, $alertState);
            }
            if ($isCli) {
                return [
                    'success' => false,
                    'error' => "SKIP device ID: {$deviceId} (IF-MIB unreachable)",
                ];
            }
            return [
                'success' => false,
                'error' => 'Cannot read IF-MIB',
            ];
        }

        // Probe berhasil: perangkat dianggap up di daftar perangkat, peta, dan API mobile.
        $this->markDeviceStatus($deviceId, true);

        $ifNameMap = [];
        foreach ($ifIndex as $i => $raw) {
            $idx = (int) filter_var($raw, FILTER_SANITIZE_NUMBER_INT);
            $name = trim(str_replace(['STRING:', '"'], '', $ifName[$i] ?? ''));
            if ($name !== '') {
                $ifNameMap[$name] = $idx;
            }
        }

        // Daya optik (DDM) lewat lapisan driver multi-vendor (app/Services/Optical):
        // MikroTik & Huawei dengan driver bawaan terverifikasi, vendor lain lewat
        // ENTITY-SENSOR-MIB atau profil OID admin. Vendor yang tak terdeteksi memakai
        // perilaku lama persis (lihat OpticalDriverResolver). Hasil berbentuk sama:
        // [ifName => ['rx' => ?float, 'tx' => ?float]].
        $opticalMap = app(OpticalDriverResolver::class)
            ->read($device, new SnmpSession((string) $ip, (string) $community), $ifNameMap)['optics'];

        // Snapshot of previous counters for delta-based rate calc (only need for SFP).
        $prevCounters = [];
        $prevRows = DB::table('interfaces')
            ->select(['if_index', 'in_octets', 'out_octets', 'counters_polled_at', 'is_monitored', 'last_seen'])
            ->where('device_id', $deviceId)
            ->get();
        // Port yang ditandai "tidak dipakai" (is_monitored = 0): status & RX tetap
        // diperbarui di tabel interfaces, tetapi tanpa alert, kejadian SLA, dan sampel
        // statistik (rollup & deteksi degradasi ikut bersih karena bersumber dari sini).
        $unmonitored = [];
        foreach ($prevRows as $row) {
            if ($row->is_monitored !== null && (int) $row->is_monitored === 0) {
                $unmonitored[(int) $row->if_index] = true;
            }
        }
        foreach ($prevRows as $row) {
            $prevCounters[(int) $row->if_index] = [
                'in_octets' => $row->in_octets !== null ? (int) $row->in_octets : null,
                'out_octets' => $row->out_octets !== null ? (int) $row->out_octets : null,
                'polled_at' => $row->counters_polled_at,
            ];
        }
        $nowTs = time();

        // Per-interface RX threshold overrides (CLI alerting only). Empty when
        // the table/feature is absent.
        $thresholdOverrides = $isCli ? $this->loadThresholdOverrides($deviceId) : [];

        // ifIndex => true for every SLA down event of this device that is still open.
        // Used to close events whose link is up again even when the down->up transition
        // was missed (e.g. alert state lost while the link was down). Without this an
        // event stays open forever and the SLA report keeps counting the port as down.
        $openDownEvents = $isCli ? $this->loadOpenDownEvents($deviceId) : [];

        $inserted = 0;
        $seenIdx = [];
        $sfpCount = 0;
        $downSfpCount = 0;

        foreach ($ifIndex as $i => $raw) {
            $ifIdx = (int) filter_var($raw, FILTER_SANITIZE_NUMBER_INT);
            $seenIdx[$ifIdx] = true;
            $name = trim(str_replace(['STRING:', '"'], '', $ifName[$i] ?? ''));
            $alias = trim(str_replace(['STRING:', '"'], '', $ifAlias[$i] ?? $name));
            $desc = trim(str_replace(['STRING:', '"'], '', $ifDescr[$i] ?? $name));

            $oper = 2;
            if (isset($ifOper[$i])) {
                $oper = (int) filter_var($ifOper[$i], FILTER_SANITIZE_NUMBER_INT);
            }

            $isSfp = 0;
            $type = 'other';
            $tx = null;
            $rx = null;

            // Speed (bps). Prefer ifHighSpeed (Mbps) since ifSpeed overflows >4Gbps.
            // SNMP walk values come as "Type: value" (e.g. "Gauge32: 1000") so use
            // preg_match to extract the trailing integer instead of filter_var, which
            // would leak digits from the type prefix.
            $speedBps = null;
            $hs = $this->snmpIntVal($ifHighSpeed[$i] ?? null);
            if ($hs !== null && $hs > 0) {
                $speedBps = $hs * 1000000;
            }
            if ($speedBps === null) {
                $sp = $this->snmpIntVal($ifSpeed[$i] ?? null);
                if ($sp !== null && $sp > 0) {
                    $speedBps = $sp;
                }
            }

            // Traffic counters. Use HC (64-bit) when available, fallback to 32-bit.
            $inOct = $this->snmpIntVal($ifHCIn[$i] ?? null);
            if ($inOct === null) {
                $inOct = $this->snmpIntVal($ifIn[$i] ?? null);
            }
            $outOct = $this->snmpIntVal($ifHCOut[$i] ?? null);
            if ($outOct === null) {
                $outOct = $this->snmpIntVal($ifOut[$i] ?? null);
            }

            // Rate calculation from previous sample.
            $inRate = null;
            $outRate = null;
            if (isset($prevCounters[$ifIdx])) {
                $prev = $prevCounters[$ifIdx];
                if ($prev['polled_at']) {
                    $prevTs = strtotime((string) $prev['polled_at']);
                    $delta = $nowTs - $prevTs;
                    if ($delta > 0 && $delta < 3600) {
                        if ($inOct !== null && $prev['in_octets'] !== null && $inOct >= $prev['in_octets']) {
                            $inRate = (int) (($inOct - $prev['in_octets']) * 8 / $delta);
                        }
                        if ($outOct !== null && $prev['out_octets'] !== null && $outOct >= $prev['out_octets']) {
                            $outRate = (int) (($outOct - $prev['out_octets']) * 8 / $delta);
                        }
                    }
                }
            }

            if (
                stripos($name, 'sfp') !== false ||
                stripos($name, 'xgigabit') !== false ||
                stripos($name, '10ge') !== false ||
                stripos($name, '25ge') !== false ||
                stripos($name, '40ge') !== false ||
                stripos($name, '100ge') !== false ||
                stripos($name, '400ge') !== false ||
                stripos($name, 'gpon') !== false ||
                stripos($name, 'xpon') !== false
            ) {
                $isSfp = 1;

                if (
                    stripos($name, '400ge') !== false
                ) {
                    $type = 'QSFP-DD';
                } elseif (
                    stripos($name, '100ge') !== false ||
                    stripos($name, '40ge') !== false
                ) {
                    $type = 'QSFP+';
                } elseif (stripos($name, 'gpon') !== false || stripos($name, 'xpon') !== false) {
                    $type = 'PON';
                } else {
                    $type = 'SFP+';
                }
            }

            if ($isSfp) {
                if ($oper === 1) {
                    if (isset($opticalMap[$name])) {
                        $tx = $opticalMap[$name]['tx'];
                        $rx = $opticalMap[$name]['rx'];
                    }
                } else {
                    $rx = -40.00;
                    $tx = null;
                    $downSfpCount++;
                }
            }

            $monitored = !isset($unmonitored[$ifIdx]);
            if (!$monitored && $isCli && isset($alertState[$deviceId . ':' . $ifIdx])) {
                // Lupakan state alert port yang tidak dipakai: saat dipantau lagi ia mulai
                // bersih, bukan memicu alert "UP/DOWN" dari keadaan berminggu-minggu lalu.
                unset($alertState[$deviceId . ':' . $ifIdx]);
                $alertStateDirty = true;
            }

            if ($isCli && $isSfp && $monitored) {
                $deviceLabel = trim(($device->device_name ?? '') . ' (' . $ip . ')');
                $ifaceComment = $alias !== '' ? $alias : ($desc !== '' ? $desc : '');
                $ifaceLabel = $ifaceComment !== '' ? "{$name} ({$ifaceComment})" : $name;
                $timeLabel = date('Y-m-d H:i:s');

                $stateKey = $deviceId . ':' . $ifIdx;
                $prevState = $alertState[$stateKey] ?? null;
                $prevKnown = is_array($prevState);
                $prevLinkUp = is_array($prevState) ? (bool) ($prevState['link_up'] ?? false) : false;
                $prevWarnLevel = 'none';
                if (is_array($prevState)) {
                    if (isset($prevState['warn_level'])) {
                        $prevWarnLevel = (string) $prevState['warn_level'];
                    } elseif (!empty($prevState['warn'])) {
                        $prevWarnLevel = 'warning';
                    }
                }
                $prevHadOptic = is_array($prevState) ? (bool) ($prevState['had_optic'] ?? false) : false;

                $hasOpticUp = ($oper == 1) && ($rx !== null || $tx !== null);
                $downThreshold = is_numeric($alertSettings['rx_down_threshold'] ?? null)
                    ? (float) $alertSettings['rx_down_threshold']
                    : -40.0;
                $warnHigh = is_numeric($alertSettings['rx_warn_high'] ?? null)
                    ? (float) $alertSettings['rx_warn_high']
                    : -18.0;
                $warnLow = is_numeric($alertSettings['rx_warn_low'] ?? null)
                    ? (float) $alertSettings['rx_warn_low']
                    : -25.0;

                // Per-interface overrides take precedence over the globals.
                $ovr = $thresholdOverrides[$ifIdx] ?? null;
                if ($ovr) {
                    if ($ovr['rx_down_threshold'] !== null) {
                        $downThreshold = (float) $ovr['rx_down_threshold'];
                    }
                    if ($ovr['rx_warn_high'] !== null) {
                        $warnHigh = (float) $ovr['rx_warn_high'];
                    }
                    if ($ovr['rx_warn_low'] !== null) {
                        $warnLow = (float) $ovr['rx_warn_low'];
                    }
                }

                if ($warnLow > $warnHigh) {
                    [$warnLow, $warnHigh] = [$warnHigh, $warnLow];
                }

                $isDownByRx = ($rx === null || (is_numeric($rx) && (float) $rx <= $downThreshold));
                $linkUp = ($oper == 1) && !$isDownByRx;
                $nowWarnLevel = 'none';
                if ($linkUp && is_numeric($rx)) {
                    $rxF = (float) $rx;
                    if ($rxF <= $warnHigh && $rxF > $downThreshold) {
                        $nowWarnLevel = ($rxF < $warnLow) ? 'critical' : 'warning';
                    }
                }
                $warnNow = $nowWarnLevel !== 'none';

                $alertState[$stateKey] = [
                    'link_up' => $linkUp,
                    'warn' => $warnNow, // legacy
                    'warn_level' => $nowWarnLevel,
                    'had_optic' => ($hasOpticUp || $prevHadOptic),
                ];
                $alertStateDirty = true;

                $deviceMeta = [
                    'device_id' => $deviceId,
                    'device_name' => (string) ($device->device_name ?? ''),
                    'device_ip' => (string) $ip,
                ];
                $ifaceMeta = [
                    'if_index' => $ifIdx,
                    'if_name' => $name,
                    'if_alias' => $alias,
                    'rx_power' => $rx,
                    'tx_power' => $tx,
                ];

                $linkWentDown = $prevKnown && $prevLinkUp && !$linkUp && ($prevHadOptic || $hasOpticUp);
                $linkCameUp = $prevKnown && !$prevLinkUp && $linkUp && ($prevHadOptic || $hasOpticUp);

                // Record the down/up transition for SLA/uptime reporting. Done
                // here (not in emitAlert) so it stays accurate even when alerts
                // are throttled by flap-cooldown or silenced by maintenance.
                if ($linkWentDown) {
                    $this->recordDownEvent($deviceId, $ifIdx, $name, $alias, (string) ($device->device_name ?? ''), true);
                } elseif ($linkCameUp || ($linkUp && isset($openDownEvents[$ifIdx]))) {
                    // Second condition = reconciliation: the link is up but an event is still
                    // open (the transition that should have closed it was never observed).
                    $this->recordDownEvent($deviceId, $ifIdx, $name, $alias, (string) ($device->device_name ?? ''), false);
                }

                if (
                    $linkWentDown
                    && ($alertSettings['interface_down'] ?? true)
                ) {
                    $tg = "🔴 LINK DOWN\n📟 Device: {$deviceLabel}\n🔌 Interface: {$ifaceLabel}\n🕒 Time: {$timeLabel}";
                    $this->emitAlert(
                        $alertSettings,
                        $deviceMeta,
                        $ifaceMeta,
                        'interface_down',
                        'critical',
                        "Interface down: {$deviceLabel} / {$ifaceLabel}",
                        $tg
                    );
                } elseif (
                    $linkCameUp
                    && ($alertSettings['interface_up'] ?? true)
                ) {
                    $tg = "🟢 LINK UP\n📟 Device: {$deviceLabel}\n🔌 Interface: {$ifaceLabel}\n📡 RX: " . ($rx !== null ? "{$rx} dBm" : 'N/A') . "\n🕒 Time: {$timeLabel}";
                    $this->emitAlert(
                        $alertSettings,
                        $deviceMeta,
                        $ifaceMeta,
                        'interface_up',
                        'info',
                        "Interface up: {$deviceLabel} / {$ifaceLabel} (RX " . ($rx !== null ? "{$rx} dBm" : 'N/A') . ")",
                        $tg
                    );
                }

                if (
                    $prevKnown && $warnNow && $prevWarnLevel !== $nowWarnLevel
                    && ($alertSettings['interface_warning'] ?? true)
                ) {
                    $tg = "🟡 RX WARNING\n📟 Device: {$deviceLabel}\n🔌 Interface: {$ifaceLabel}\n📡 RX: " . ($rx !== null ? "{$rx} dBm" : 'N/A') . "\n🕒 Time: {$timeLabel}";
                    $this->emitAlert(
                        $alertSettings,
                        $deviceMeta,
                        $ifaceMeta,
                        'interface_warning',
                        $nowWarnLevel === 'critical' ? 'critical' : 'warning',
                        "RX warning: {$deviceLabel} / {$ifaceLabel} (RX " . ($rx !== null ? "{$rx} dBm" : 'N/A') . ")",
                        $tg
                    );
                }
            }

            DB::statement(
                "INSERT INTO interfaces
                (device_id, if_index, if_name, if_alias, if_description, optical_index, rx_power, tx_power, oper_status, last_seen, is_sfp, interface_type, if_speed, in_octets, out_octets, in_rate_bps, out_rate_bps, counters_polled_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    if_name=VALUES(if_name),
                    if_alias=VALUES(if_alias),
                    if_description=VALUES(if_description),
                    optical_index=VALUES(optical_index),
                    rx_power=VALUES(rx_power),
                    tx_power=VALUES(tx_power),
                    oper_status=VALUES(oper_status),
                    last_seen=NOW(),
                    is_sfp=VALUES(is_sfp),
                    interface_type=VALUES(interface_type),
                    if_speed=VALUES(if_speed),
                    in_octets=VALUES(in_octets),
                    out_octets=VALUES(out_octets),
                    in_rate_bps=VALUES(in_rate_bps),
                    out_rate_bps=VALUES(out_rate_bps),
                    counters_polled_at=NOW()",
                [
                    $deviceId,
                    $ifIdx,
                    $name,
                    $alias,
                    $desc,
                    null,
                    $rx,
                    $tx,
                    $oper,
                    $isSfp,
                    $type,
                    $speedBps,
                    $inOct,
                    $outOct,
                    $inRate,
                    $outRate,
                ]
            );

            $inserted++;
            if ($isSfp) {
                $sfpCount++;
            }

            if ($isSfp && $monitored && ($inOct !== null || $outOct !== null)) {
                DB::table('interface_traffic_stats')->insert([
                    'device_id' => $deviceId,
                    'if_index' => $ifIdx,
                    'in_octets' => $inOct,
                    'out_octets' => $outOct,
                    'in_rate_bps' => $inRate,
                    'out_rate_bps' => $outRate,
                    'created_at' => now(),
                ]);
            }

            if ($isSfp && $monitored && $rx !== null) {
                $loss = ($tx !== null && $rx !== null) ? ($tx - $rx) : null;
                DB::table('interface_stats')->insert([
                    'device_id' => $deviceId,
                    'if_index' => $ifIdx,
                    'tx_power' => $tx,
                    'rx_power' => $rx,
                    'loss' => $loss,
                    'created_at' => now(),
                ]);

                DB::table('interfaces')
                    ->where('device_id', $deviceId)
                    ->where('if_index', $ifIdx)
                    ->update([
                        'rx_power' => $rx,
                        'tx_power' => $tx,
                        'oper_status' => $oper,
                        'updated_at' => now(),
                    ]);
            }
        }

        if ($isCli && $alertStateDirty) {
            $this->saveAlertState($alertStateFile, $alertState);
        }

        if ($isCli && $seenIdx !== []) {
            $this->reconcileVanishedPorts($deviceId, $seenIdx, $prevRows, $unmonitored);
        }

        return [
            'success' => true,
            'inserted' => $inserted,
            'sfp_count' => $sfpCount,
            'sfp_down_count' => $downSfpCount,
            'optical_found' => count($opticalMap),
            'message' => "Discover OK: {$inserted} interfaces ({$sfpCount} SFP/QSFP, {$downSfpCount} down)",
        ];
    }

    /**
     * Parse a raw SNMP scalar value (e.g. "Counter64: 12345", "Gauge32: 100",
     * "INTEGER: 7", or a bare integer) into an int. Returns null if no integer
     * can be extracted.
     */
    private function snmpIntVal($raw): ?int
    {
        if ($raw === null || $raw === false) {
            return null;
        }
        $s = (string) $raw;
        if (preg_match('/(-?\d+)(?!.*\d)/', $s, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function loadTelegramSettings(): array
    {
        // Backward compatibility shim (older callers)
        $s = $this->loadAlertSettings();
        return [
            'bot_token' => $s['bot_token'] ?? '',
            'chat_id' => $s['chat_id'] ?? '',
            'rx_threshold' => (float) ($s['rx_warn_low'] ?? -25.0),
        ];
    }

    /**
     * Record an interface down/up transition into interface_down_events for
     * SLA/uptime reporting. On down we open an event (if none is open); on up
     * we close the latest open event and compute its duration. Best-effort —
     * never breaks polling.
     */
    /**
     * @return array<int,bool> ifIndex => true for open SLA down events of the device.
     */
    private function loadOpenDownEvents(int $deviceId): array
    {
        try {
            if (!Schema::hasTable('interface_down_events')) {
                return [];
            }

            return DB::table('interface_down_events')
                ->where('device_id', $deviceId)
                ->whereNull('up_at')
                ->pluck('if_index')
                ->mapWithKeys(fn ($i) => [(int) $i => true])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Ports the device no longer reports (hardware swapped, MikroTik ifIndex renumbered
     * after a reboot/upgrade, module slot removed…) used to linger forever with their
     * last RX value, e.g. an sfp-sfpplus8 row on a 4-port switch last seen months ago.
     *
     * After a complete walk, a port absent for more than VANISHED_AFTER_HOURS is marked
     * "tidak dipakai" by `system` (so it drops out of the UI, SLA, KPI and alerts without
     * deleting the row — legacy tables still reference interfaces.id). If such a
     * system-retired port shows up again it is re-enabled automatically; ports an admin
     * retired stay retired.
     *
     * @param array<int,bool> $seenIdx ifIndex values returned by this poll
     * @param array<int,bool> $unmonitored ifIndex values that were is_monitored = 0
     */
    private function reconcileVanishedPorts(int $deviceId, array $seenIdx, $prevRows, array $unmonitored): void
    {
        try {
            if (!Schema::hasTable('interface_monitoring_changes')) {
                return;
            }
            $cutoff = time() - self::VANISHED_AFTER_HOURS * 3600;
            $now = now();

            foreach ($prevRows as $row) {
                $idx = (int) $row->if_index;
                if (isset($seenIdx[$idx]) || isset($unmonitored[$idx])) {
                    continue;
                }
                $last = $row->last_seen ? strtotime((string) $row->last_seen) : false;
                if ($last === false || $last > $cutoff) {
                    continue;
                }
                DB::table('interfaces')->where('device_id', $deviceId)->where('if_index', $idx)->update(['is_monitored' => 0]);
                DB::table('interface_monitoring_changes')->insert([
                    'device_id' => $deviceId, 'if_index' => $idx, 'is_monitored' => 0,
                    'reason' => 'Tidak lagi dilaporkan perangkat (otomatis)', 'changed_by' => 'system', 'created_at' => $now,
                ]);
                $open = DB::table('interface_down_events')->where('device_id', $deviceId)->where('if_index', $idx)
                    ->whereNull('up_at')->get(['id', 'down_at']);
                foreach ($open as $ev) {
                    DB::table('interface_down_events')->where('id', $ev->id)->update([
                        'up_at' => $now,
                        'duration_sec' => max(0, $now->getTimestamp() - strtotime((string) $ev->down_at)),
                    ]);
                }
            }

            // Re-enable ports that the SYSTEM retired and that are reported again.
            $back = array_keys(array_intersect_key($unmonitored, $seenIdx));
            if ($back === []) {
                return;
            }
            $latest = DB::table('interface_monitoring_changes')
                ->where('device_id', $deviceId)->whereIn('if_index', $back)
                ->orderBy('id')->get(['if_index', 'changed_by', 'is_monitored'])
                ->keyBy('if_index');
            foreach ($back as $idx) {
                $c = $latest[$idx] ?? null;
                if ($c && $c->changed_by === 'system' && (int) $c->is_monitored === 0) {
                    DB::table('interfaces')->where('device_id', $deviceId)->where('if_index', $idx)->update(['is_monitored' => 1]);
                    DB::table('interface_monitoring_changes')->insert([
                        'device_id' => $deviceId, 'if_index' => $idx, 'is_monitored' => 1,
                        'reason' => 'Dilaporkan perangkat lagi (otomatis)', 'changed_by' => 'system', 'created_at' => $now,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Housekeeping must never break polling.
        }
    }

    private function recordDownEvent(int $deviceId, int $ifIndex, string $ifName, string $ifAlias, string $deviceName, bool $down): void
    {
        try {
            if (!Schema::hasTable('interface_down_events')) {
                return;
            }

            $base = DB::table('interface_down_events')
                ->where('device_id', $deviceId)
                ->where('if_index', $ifIndex)
                ->whereNull('up_at');

            if ($down) {
                // Don't open a second event if one is already open.
                if ((clone $base)->exists()) {
                    return;
                }
                DB::table('interface_down_events')->insert([
                    'device_id' => $deviceId,
                    'if_index' => $ifIndex,
                    'if_name' => $ifName,
                    'if_alias' => $ifAlias,
                    'device_name' => $deviceName,
                    'down_at' => now(),
                    'up_at' => null,
                    'duration_sec' => null,
                    'created_at' => now(),
                ]);
                return;
            }

            // Up: close the most recent open event.
            $open = (clone $base)->orderByDesc('down_at')->first(['id', 'down_at']);
            if ($open) {
                $dur = max(0, time() - strtotime((string) $open->down_at));
                DB::table('interface_down_events')->where('id', $open->id)->update([
                    'up_at' => now(),
                    'duration_sec' => $dur,
                ]);
            }
        } catch (\Throwable $e) {
            // Never let SLA recording break polling.
        }
    }

    /**
     * Load per-interface RX threshold overrides for a device, keyed by
     * ifIndex. Each entry has rx_warn_high / rx_warn_low / rx_down_threshold,
     * any of which may be null (meaning "use the global value"). Returns an
     * empty array if the feature table does not exist yet.
     *
     * @return array<int,array{rx_warn_high:?float,rx_warn_low:?float,rx_down_threshold:?float}>
     */
    private function loadThresholdOverrides(int $deviceId): array
    {
        if (!Schema::hasTable('interface_thresholds')) {
            return [];
        }

        $map = [];
        $rows = DB::table('interface_thresholds')
            ->where('device_id', $deviceId)
            ->get(['if_index', 'rx_warn_high', 'rx_warn_low', 'rx_down_threshold']);
        foreach ($rows as $row) {
            $map[(int) $row->if_index] = [
                'rx_warn_high' => $row->rx_warn_high !== null ? (float) $row->rx_warn_high : null,
                'rx_warn_low' => $row->rx_warn_low !== null ? (float) $row->rx_warn_low : null,
                'rx_down_threshold' => $row->rx_down_threshold !== null ? (float) $row->rx_down_threshold : null,
            ];
        }

        return $map;
    }

    /**
     * Catat hasil probe SNMP ke `snmp_devices` supaya daftar perangkat, peta web,
     * dan API mobile membaca keadaan yang sama dengan alert Telegram/FCM.
     *
     * Sebelumnya hanya tombol "Test SNMP" (DevicesApiController::testSnmp) yang
     * menulis `last_status`; poller hanya mengirim alert, sehingga perangkat yang
     * sudah dinyatakan DOWN tetap tampil "online" sampai ditest manual.
     */
    private function markDeviceStatus(int $deviceId, bool $up, ?string $error = null): void
    {
        try {
            DB::table('snmp_devices')->where('id', $deviceId)->update([
                'last_status' => $up ? 'OK' : 'FAILED',
                'last_error' => $up ? null : substr((string) $error, 0, 250),
                'last_monitor_status' => $up ? 'up' : 'down',
                'last_monitor_time' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('markDeviceStatus gagal', ['device_id' => $deviceId, 'error' => $e->getMessage()]);
        }
    }

    private function loadAlertSettings(): array
    {
        $settings = [
            'bot_token' => '',
            'chat_id' => '',

            // Channel toggles (default enabled to preserve old behavior)
            'telegram_enabled' => true,
            'webui_enabled' => true,
            'mobile_enabled' => true,

            // Event toggles (default enabled)
            'interface_down' => true,
            'interface_up' => true,
            'interface_warning' => true,
            'interface_degradation' => true,
            'device_down' => true,
            'device_up' => true,

            // Thresholds
            'rx_warn_high' => -18.0,
            'rx_warn_low' => -25.0,
            'rx_down_threshold' => -40.0,

            // Flap/rate cooldown: min minutes between repeated alerts of the
            // same collapsed category for the same device/interface.
            'rate_limit_min' => 5,
        ];

        $keys = [
            'bot_token',
            'chat_id',
            'alert_telegram_enabled',
            'alert_webui_enabled',
            'alert_mobile_enabled',
            'alert_interface_down',
            'alert_interface_up',
            'alert_interface_warning',
            'alert_interface_degradation',
            'alert_device_down',
            'alert_device_up',
            'alert_rx_warning_high',
            'alert_rx_warning_low',
            'alert_rx_down_threshold',
            'alert_rate_limit_min',
        ];

        $rows = DB::table('settings')->whereIn('name', $keys)->get();
        foreach ($rows as $row) {
            $name = (string) $row->name;
            $val = $row->value;

            if ($name === 'bot_token') {
                $settings['bot_token'] = trim(Secret::reveal((string) $val));
                continue;
            }
            if ($name === 'chat_id') {
                $settings['chat_id'] = trim((string) $val);
                continue;
            }

            if ($name === 'alert_telegram_enabled') {
                $settings['telegram_enabled'] = trim((string) $val) !== '0';
                continue;
            }
            if ($name === 'alert_webui_enabled') {
                $settings['webui_enabled'] = trim((string) $val) !== '0';
                continue;
            }
            if ($name === 'alert_mobile_enabled') {
                $settings['mobile_enabled'] = trim((string) $val) !== '0';
                continue;
            }

            if ($name === 'alert_interface_down') {
                $settings['interface_down'] = trim((string) $val) !== '0';
                continue;
            }
            if ($name === 'alert_interface_up') {
                $settings['interface_up'] = trim((string) $val) !== '0';
                continue;
            }
            if ($name === 'alert_interface_warning') {
                $settings['interface_warning'] = trim((string) $val) !== '0';
                continue;
            }
            if ($name === 'alert_interface_degradation') {
                $settings['interface_degradation'] = trim((string) $val) !== '0';
                continue;
            }
            if ($name === 'alert_device_down') {
                $settings['device_down'] = trim((string) $val) !== '0';
                continue;
            }
            if ($name === 'alert_device_up') {
                $settings['device_up'] = trim((string) $val) !== '0';
                continue;
            }

            if ($name === 'alert_rx_warning_high' && is_numeric($val)) {
                $settings['rx_warn_high'] = (float) $val;
                continue;
            }
            if ($name === 'alert_rx_warning_low' && is_numeric($val)) {
                $settings['rx_warn_low'] = (float) $val;
                continue;
            }
            if ($name === 'alert_rx_down_threshold' && is_numeric($val)) {
                $settings['rx_down_threshold'] = (float) $val;
                continue;
            }
            if ($name === 'alert_rate_limit_min' && is_numeric($val)) {
                $settings['rate_limit_min'] = max(0, (int) $val);
                continue;
            }
        }

        return $settings;
    }

    /**
     * Public entry point for the daily optical degradation check to emit an
     * alert through the same channels (web log + mobile push + Telegram),
     * honoring the alert_interface_degradation toggle.
     *
     * @param  string  $eventType  'interface_degradation' or 'interface_recovered'
     */
    public function emitDegradationAlert(
        array $deviceMeta,
        array $ifaceMeta,
        string $eventType,
        string $severity,
        string $logMessage,
        string $telegramText = ''
    ): void {
        $settings = $this->loadAlertSettings();
        if (($settings['interface_degradation'] ?? true) !== true) {
            return;
        }
        $this->emitAlert($settings, $deviceMeta, $ifaceMeta, $eventType, $severity, $logMessage, $telegramText);
    }

    /**
     * Whether alerting is currently muted for this device — either a global
     * mute (device_id = 0) or a per-device mute, optionally auto-expiring.
     */
    private function isMuted(int $deviceId): bool
    {
        if (!Schema::hasTable('alert_mutes')) {
            return false;
        }

        return DB::table('alert_mutes')
            ->where(function ($q) use ($deviceId) {
                $q->where('device_id', 0);
                if ($deviceId > 0) {
                    $q->orWhere('device_id', $deviceId);
                }
            })
            ->where(function ($q) {
                $q->whereNull('muted_until')->orWhere('muted_until', '>', now());
            })
            ->exists();
    }

    /**
     * Cooldown key for the rate-limited (non-transition) alert types only.
     * Down/up transitions bypass the cooldown entirely in emitAlert(), so the
     * only callers here are RX warnings (which can oscillate between
     * warning/critical) and the daily degradation check.
     */
    private function cooldownKey(string $eventType, int $deviceId, $ifIndex): string
    {
        $cat = match ($eventType) {
            'interface_warning' => 'warn',
            'interface_degradation', 'interface_recovered' => 'degr',
            default => $eventType,
        };

        return $ifIndex !== null
            ? "cd:{$cat}:{$deviceId}:{$ifIndex}"
            : "cd:{$cat}:{$deviceId}";
    }

    private function emitAlert(
        array $settings,
        array $deviceMeta,
        ?array $ifaceMeta,
        string $eventType,
        string $severity,
        string $logMessage,
        string $telegramText = ''
    ): void {
        $deviceId = (int) ($deviceMeta['device_id'] ?? 0);

        // Maintenance: drop the alert entirely if this device (or all) is muted.
        if ($this->isMuted($deviceId)) {
            return;
        }

        // Flap/rate cooldown — only for alerts that can repeat without an
        // intervening opposite event (RX warnings oscillating between
        // warning/critical, daily degradation). Down/up are edge-triggered and
        // strictly alternating: a "down" can't fire again before an "up", so
        // every one is a real, non-duplicate event and must always be delivered
        // — rate-limiting them only ever drops a genuine recovery.
        $skipCooldown = in_array(
            $eventType,
            ['interface_down', 'interface_up', 'device_down', 'device_up'],
            true
        );
        $cooldownSec = $skipCooldown ? 0 : (int) ($settings['rate_limit_min'] ?? 5) * 60;
        if ($cooldownSec > 0 && Schema::hasTable('alert_cooldowns')) {
            $key = $this->cooldownKey($eventType, $deviceId, $ifaceMeta['if_index'] ?? null);
            $last = DB::table('alert_cooldowns')->where('k', $key)->value('last_sent_at');
            if ($last !== null && (time() - strtotime((string) $last)) < $cooldownSec) {
                return;
            }
            DB::table('alert_cooldowns')->updateOrInsert(
                ['k' => $key],
                ['last_sent_at' => date('Y-m-d H:i:s')]
            );
        }

        // Web UI log
        if (($settings['webui_enabled'] ?? true) === true) {
            try {
                $this->ensureAlertLogTable();
                if (Schema::hasTable('alert_logs')) {
                    $fingerprintBase = $eventType . '|' . ($deviceMeta['device_id'] ?? '') . '|' . ($ifaceMeta['if_index'] ?? '');
                    DB::table('alert_logs')->insert([
                        'event_type' => $eventType,
                        'severity' => $severity,
                        'device_id' => $deviceMeta['device_id'] ?? null,
                        'device_name' => $deviceMeta['device_name'] ?? null,
                        'device_ip' => $deviceMeta['device_ip'] ?? null,
                        'if_index' => $ifaceMeta['if_index'] ?? null,
                        'if_name' => $ifaceMeta['if_name'] ?? null,
                        'if_alias' => $ifaceMeta['if_alias'] ?? null,
                        'rx_power' => $ifaceMeta['rx_power'] ?? null,
                        'tx_power' => $ifaceMeta['tx_power'] ?? null,
                        'message' => $logMessage,
                        'context' => json_encode([
                            'device' => $deviceMeta,
                            'iface' => $ifaceMeta,
                        ]),
                        'fingerprint' => hash('sha256', $fingerprintBase),
                    ]);
                }
            } catch (\Throwable $e) {
                // Avoid breaking polling due to logging failures.
            }
        }

        // Mobile push (best-effort). Global toggle overrides per-user prefs:
        // when the admin disables mobile_enabled, no push goes out even if
        // individual users have push_enabled=true.
        if (($settings['mobile_enabled'] ?? true) === true) {
            try {
                $this->sendMobilePush($severity, $eventType, $logMessage, $deviceMeta, $ifaceMeta);
            } catch (\Throwable $e) {
                // Do not break polling if push fails.
            }
        }

        // Telegram
        if (($settings['telegram_enabled'] ?? true) !== true) {
            return;
        }
        $botToken = trim((string) ($settings['bot_token'] ?? ''));
        $chatId = trim((string) ($settings['chat_id'] ?? ''));
        if ($botToken === '' || $chatId === '' || trim($telegramText) === '') {
            return;
        }
        $this->telegramSendMessage($botToken, $chatId, $telegramText);
    }

    private function sendMobilePush(
        string $severity,
        string $eventType,
        string $logMessage,
        array $deviceMeta,
        ?array $ifaceMeta
    ): void {
        if (!Schema::hasTable('device_tokens')) {
            return;
        }

        // Hanya token milik akun aktif ber-peran admin/technician. Dulu SEMUA baris
        // device_tokens dikirimi, termasuk HP akun viewer (demo) dan akun yang sudah
        // dinonaktifkan admin; token yang user-nya sudah dihapus juga ikut (tanpa JOIN).
        $tokens = DB::table('device_tokens')
            ->join('users', 'users.id', '=', 'device_tokens.user_id')
            ->whereIn('users.role', self::PUSH_ROLES)
            ->where('users.is_active', 1)
            ->whereNotNull('device_tokens.token')
            ->orderByDesc('device_tokens.last_seen_at')
            ->get(['device_tokens.token', 'device_tokens.user_id', 'device_tokens.last_seen_at']);

        if ($tokens->isEmpty()) {
            return;
        }

        $prefsCache = [];
        $severityRank = [
            'info' => 1,
            'warning' => 2,
            'critical' => 3,
        ];
        $sevVal = $severityRank[strtolower($severity)] ?? 1;
        $title = strtoupper($severity) . ' • ' . str_replace('_', ' ', $eventType);
        $body = $logMessage;

        /** @var FcmService $fcm */
        $fcm = app(FcmService::class);

        foreach ($tokens as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $token = (string) ($row->token ?? '');
            if ($userId <= 0 || $token === '') {
                continue;
            }

            if (!array_key_exists($userId, $prefsCache)) {
                $prefsCache[$userId] = $this->loadMobileAlertPref($userId);
            }

            $pref = $prefsCache[$userId];
            if (($pref['push_enabled'] ?? true) !== true) {
                continue;
            }
            $min = strtolower((string) ($pref['severity_min'] ?? 'warning'));
            $minVal = $severityRank[$min] ?? 2;
            if ($sevVal < $minVal) {
                continue;
            }

            try {
                $fcm->sendToToken(
                    deviceToken: $token,
                    title: $title,
                    body: $body,
                    data: [
                        'kind' => 'alert',
                        'severity' => strtolower($severity),
                        'event_type' => $eventType,
                        'device_id' => (string) ($deviceMeta['device_id'] ?? ''),
                        'if_index' => (string) ($ifaceMeta['if_index'] ?? ''),
                        'ts' => date('c'),
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('FCM push failed', [
                    'user_id' => $userId,
                    'token_prefix' => substr($token, 0, 16),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }
    }

    private function loadMobileAlertPref(int $userId): array
    {
        if (!Schema::hasTable('settings')) {
            return [
                'push_enabled' => true,
                'severity_min' => 'warning',
            ];
        }

        $key = 'mobile_alert_pref_user_' . $userId;
        $val = DB::table('settings')->where('name', $key)->value('value');
        $decoded = $val ? json_decode((string) $val, true) : null;
        if (!is_array($decoded)) {
            return [
                'push_enabled' => true,
                'severity_min' => 'warning',
            ];
        }
        return $decoded;
    }

    private function ensureAlertLogTable(): void
    {
        if (self::$alertLogTableChecked) {
            return;
        }
        self::$alertLogTableChecked = true;

        try {
            if (Schema::hasTable('alert_logs')) {
                return;
            }

            Schema::create('alert_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->timestamp('created_at')->useCurrent();

                $table->string('event_type', 64)->index();
                $table->string('severity', 16)->index();

                $table->unsignedBigInteger('device_id')->nullable()->index();
                $table->string('device_name', 190)->nullable();
                $table->string('device_ip', 64)->nullable();

                $table->unsignedInteger('if_index')->nullable()->index();
                $table->string('if_name', 190)->nullable();
                $table->string('if_alias', 190)->nullable();

                $table->decimal('rx_power', 8, 3)->nullable();
                $table->decimal('tx_power', 8, 3)->nullable();

                $table->text('message');
                // Use JSON when supported; on older MariaDB this is typically an alias to LONGTEXT anyway.
                $table->json('context')->nullable();

                $table->string('fingerprint', 64)->nullable()->index();
            });
        } catch (\Throwable $e) {
            // Do not fail polling if schema cannot be created (permissions, etc).
        }
    }

    /**
     * Build optical power map for Huawei switches using SNMP only.
     *
     * Uses HUAWEI-ENTITY-EXTENT-MIB (hwEntityOpticalRxPower / hwEntityOpticalTxPower)
     * combined with ENTITY-MIB entAliasMappingIdentifier to translate
     * entPhysicalIndex → ifIndex → ifName.
     *
     * @param  array<string,int>  $ifNameMap  [ifName => ifIndex] from discover()
     * @return array<string,array{tx:float|null,rx:float|null}>
     */
    private function loadAlertState(string $path, ?int $deviceId = null): array
    {
        if (!is_file($path)) {
            // One-time fallback: extract this device's keys from the legacy
            // monolithic alert_state.json until the per-device file is written.
            return $deviceId !== null ? $this->legacyDeviceState($deviceId) : [];
        }
        $fp = fopen($path, 'r');
        if (!$fp) {
            return [];
        }
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Pull a single device's keys ("dev:{id}" and "{id}:{ifIndex}") out of the
     * pre-split monolithic alert_state.json, so the switch to per-device state
     * files doesn't drop transition baselines on first run.
     */
    private function legacyDeviceState(int $deviceId): array
    {
        $legacy = storage_path('app/alert_state.json');
        if (!is_file($legacy)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($legacy), true);
        if (!is_array($data)) {
            return [];
        }
        $prefix = $deviceId . ':';
        $devKey = 'dev:' . $deviceId;
        $out = [];
        foreach ($data as $k => $v) {
            if ($k === $devKey || str_starts_with((string) $k, $prefix)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function saveAlertState(string $path, array $state): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fp = fopen($path, 'c+');
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function telegramSendMessage(string $botToken, string $chatId, string $text): bool
    {
        if ($botToken === '' || $chatId === '' || $text === '') {
            return false;
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $payload = http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => 1,
        ]);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $result = curl_exec($ch);
            $ok = ($result !== false);
            curl_close($ch);
            return $ok;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);
        $result = @file_get_contents($url, false, $context);
        return ($result !== false);
    }
}
