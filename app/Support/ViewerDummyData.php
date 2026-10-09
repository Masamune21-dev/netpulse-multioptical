<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ViewerDummyData
{
    public static function isViewer(?Request $request = null): bool
    {
        $role = $request
            ? (string) ($request->session()->get('auth.user.role') ?? '')
            : (string) (session('auth.user.role') ?? '');

        return $role === 'viewer';
    }

    public static function dashboardCounts(): array
    {
        return [
            'deviceCount' => 6,
            'ifCount' => 248,
            'sfpCount' => 32,
            'badOptical' => 3,
            'userCount' => 8,
        ];
    }

    public static function devices(): array
    {
        $now = date('Y-m-d H:i:s');
        return [
            [
                'id' => 101,
                'device_name' => 'RTR-CORE-DEMO',
                'ip_address' => '10.10.0.1',
                'snmp_version' => '2c',
                'community' => null,
                'snmp_user' => null,
                'is_active' => 1,
                'last_status' => 'OK',
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 102,
                'device_name' => 'SW-AGG-DEMO',
                'ip_address' => '10.10.0.2',
                'snmp_version' => '2c',
                'community' => null,
                'snmp_user' => null,
                'is_active' => 1,
                'last_status' => 'OK',
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 103,
                'device_name' => 'OLT-HIOSO-DEMO',
                'ip_address' => '10.10.10.1',
                'snmp_version' => '2c',
                'community' => null,
                'snmp_user' => null,
                'is_active' => 1,
                'last_status' => 'OK',
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 104,
                'device_name' => 'EDGE-POP-DEMO',
                'ip_address' => '10.10.0.4',
                'snmp_version' => '3',
                'community' => null,
                'snmp_user' => 'snmpv3-demo',
                'is_active' => 1,
                'last_status' => 'FAILED',
                'last_error' => 'Timeout (demo)',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
    }

    /** Satu perangkat dummy menurut id (101–104), null bila bukan id dummy. */
    public static function device(int $deviceId): ?array
    {
        foreach (self::devices() as $d) {
            if ((int) $d['id'] === $deviceId) {
                return $d;
            }
        }

        return null;
    }

    /**
     * Meta satu interface dummy (nama perangkat/port/alias) untuk modal grafik trafik,
     * modal ambang RX, dan laporan SLA viewer. Id di luar perangkat dummy tetap dijawab
     * dengan nama rekaan — tidak pernah dari tabel produksi.
     */
    public static function interfaceMeta(int $deviceId, int $ifIndex): array
    {
        $dev = self::device($deviceId);
        $iface = null;
        foreach (self::interfaces($deviceId) as $i) {
            if ((int) $i['if_index'] === $ifIndex) {
                $iface = $i;
                break;
            }
        }
        $isSfp = (int) ($iface['is_sfp'] ?? 1) === 1;

        return [
            'device_name' => $dev['device_name'] ?? ('Demo Device ' . $deviceId),
            'device_ip' => $dev['ip_address'] ?? ('10.0.0.' . ($deviceId % 256)),
            'if_name' => $iface['if_name'] ?? ('port' . $ifIndex),
            'if_alias' => $iface['if_alias'] ?? null,
            'if_description' => null,
            'if_speed' => $isSfp ? 10_000_000_000 : 1_000_000_000,
            'oper_status' => 1,
            'interface_type' => $isSfp ? 'SFP+' : null,
            'rx_power' => $iface['rx_power'] ?? null,
        ];
    }

    /** Ambang RX global dummy (selaras settings()) — viewer tidak membaca ambang produksi. */
    public static function rxThresholds(): array
    {
        $s = self::settings();

        return [
            'rx_warn_high' => (float) $s['alert_rx_warning_high'],
            'rx_warn_low' => (float) $s['alert_rx_warning_low'],
            'rx_down_threshold' => (float) $s['alert_rx_down_threshold'],
        ];
    }

    /** Bentuk sama dengan RxThresholds::global() (dipakai respons API mobile). */
    public static function globalRxThresholds(): array
    {
        $t = self::rxThresholds();

        return ['rx_warn_low' => $t['rx_warn_low'], 'rx_down_threshold' => $t['rx_down_threshold']];
    }

    public static function monitoringDevices(): array
    {
        return array_map(
            fn ($d) => ['id' => $d['id'], 'device_name' => $d['device_name']],
            self::devices()
        );
    }

    public static function interfaces(int $deviceId): array
    {
        $base = [
            [
                'id' => 9001,
                'if_index' => 1,
                'if_name' => 'sfp-sfpplus1',
                'if_alias' => 'Uplink-1',
                'optical_index' => 1,
                'rx_power' => -17.20,
                'tx_power' => -1.10,
                'last_seen' => date('Y-m-d H:i:s'),
                'is_sfp' => 1,
            ],
            [
                'id' => 9002,
                'if_index' => 2,
                'if_name' => 'sfp-sfpplus2',
                'if_alias' => 'Uplink-2',
                'optical_index' => 2,
                'rx_power' => -26.80,
                'tx_power' => -2.40,
                'last_seen' => date('Y-m-d H:i:s'),
                'is_sfp' => 1,
            ],
            [
                'id' => 9003,
                'if_index' => 3,
                'if_name' => 'ether3',
                'if_alias' => 'Access-1',
                'optical_index' => null,
                'rx_power' => null,
                'tx_power' => null,
                'last_seen' => date('Y-m-d H:i:s'),
                'is_sfp' => 0,
            ],
        ];

        // Make deviceId affect values slightly to avoid identical screens.
        $shift = ($deviceId % 7) / 10.0;
        foreach ($base as &$row) {
            if ($row['rx_power'] !== null) $row['rx_power'] = (float) $row['rx_power'] - $shift;
            if ($row['tx_power'] !== null) $row['tx_power'] = (float) $row['tx_power'] - ($shift / 2.0);
        }

        return $base;
    }

    public static function monitoringInterfaces(int $deviceId): array
    {
        $ifs = self::interfaces($deviceId);
        $out = [];
        foreach ($ifs as $i) {
            if ((int) ($i['is_sfp'] ?? 0) !== 1) continue;
            $out[] = [
                'if_index' => $i['if_index'],
                'if_name' => $i['if_name'],
                'if_alias' => $i['if_alias'] ?? null,
                'tx_power' => $i['tx_power'],
                'rx_power' => $i['rx_power'],
            ];
        }
        return $out;
    }

    public static function interfaceChart(int $deviceId, int $ifIndex, string $range): array
    {
        $points = match ($range) {
            '1h' => 12,
            '1d' => 24,
            '3d' => 36,
            '7d' => 56,
            '30d' => 60,
            '1y' => 60,
            default => 12,
        };

        $stepSec = match ($range) {
            '1h' => 300,
            '1d' => 3600,
            '3d' => 2 * 3600,
            '7d' => 3 * 3600,
            '30d' => 12 * 3600,
            '1y' => 6 * 24 * 3600,
            default => 300,
        };

        $seed = ($deviceId * 31) + ($ifIndex * 7);
        $txBase = -2.0 - (($seed % 9) / 10.0);
        $rxBase = -20.0 - (($seed % 17) / 10.0);

        $now = time();
        $out = [];
        for ($i = $points - 1; $i >= 0; $i--) {
            $t = $now - ($i * $stepSec);
            $n = (($seed + $t) % 13) / 10.0;
            $tx = $txBase + ($n / 3.0);
            $rx = $rxBase - ($n);
            $loss = $tx - $rx;

            $out[] = [
                'created_at' => date('Y-m-d H:i:s', $t),
                'tx_power' => round($tx, 3),
                'rx_power' => round($rx, 3),
                'loss' => round($loss, 3),
            ];
        }

        return $out;
    }

    public static function mapNodes(bool $withInterfaces): array
    {
        $now = date('Y-m-d H:i:s');
        // Keep nodes close to the default map view (map.js sets view around -6.7489, 110.9752).
        $centerLng = 110.975234;
        $centerLat = -6.748974;
        $nodes = [
            [
                'id' => 1,
                'device_id' => 101,
                'node_name' => 'CORE',
                'node_type' => 'router',
                'x_position' => $centerLng - 0.015,
                'y_position' => $centerLat + 0.008,
                'icon_type' => 'router',
                'is_locked' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'device_name' => 'RTR-CORE-DEMO',
                'ip_address' => '10.10.0.1',
                'last_status' => 'OK',
                'snmp_version' => '2c',
            ],
            [
                'id' => 2,
                'device_id' => 102,
                'node_name' => 'AGG',
                'node_type' => 'switch',
                'x_position' => $centerLng + 0.000,
                'y_position' => $centerLat + 0.000,
                'icon_type' => 'switch',
                'is_locked' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'device_name' => 'SW-AGG-DEMO',
                'ip_address' => '10.10.0.2',
                'last_status' => 'OK',
                'snmp_version' => '2c',
            ],
            [
                'id' => 3,
                'device_id' => 103,
                'node_name' => 'OLT',
                // Use a known icon key (map.js nodeIcons) so the marker renders consistently.
                'node_type' => 'server',
                'x_position' => $centerLng + 0.018,
                'y_position' => $centerLat - 0.010,
                'icon_type' => 'server',
                'is_locked' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'device_name' => 'OLT-HIOSO-DEMO',
                'ip_address' => '10.10.10.1',
                'last_status' => 'OK',
                'snmp_version' => '2c',
            ],
            [
                'id' => 4,
                'device_id' => null,
                'node_name' => 'CLOUD',
                'node_type' => 'cloud',
                'x_position' => $centerLng - 0.030,
                'y_position' => $centerLat - 0.018,
                'icon_type' => 'cloud',
                'is_locked' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'device_name' => null,
                'ip_address' => null,
                'last_status' => 'OK',
                'snmp_version' => null,
            ],
        ];

        foreach ($nodes as &$n) {
            $n['status'] = $n['last_status'] ?? 'unknown';
            $n['interfaces'] = [];
            $n['interfaces_loaded'] = false;
            if ($withInterfaces && !empty($n['device_id']) && ($n['status'] === 'OK')) {
                $n['interfaces'] = array_map(function ($i) {
                    return [
                        'if_name' => $i['if_name'],
                        'if_alias' => $i['if_alias'] ?? null,
                        'if_description' => null,
                        'if_type' => null,
                        'is_sfp' => (int) ($i['is_sfp'] ?? 0),
                        'last_seen' => $i['last_seen'] ?? null,
                        'rx_power' => $i['rx_power'],
                        'tx_power' => $i['tx_power'],
                        'interface_type' => null,
                        'oper_status' => 1,
                    ];
                }, self::interfaces((int) $n['device_id']));
                $n['interfaces_loaded'] = true;
            }
        }

        return $nodes;
    }

    public static function mapLinks(): array
    {
        $now = date('Y-m-d H:i:s');
        return [
            [
                'id' => 1,
                'node_a_id' => 1,
                'node_b_id' => 2,
                'interface_a_id' => 9001,
                'interface_b_id' => 9002,
                'attenuation_db' => '16.2',
                'notes' => 'Demo link',
                'path_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'node_a_name' => 'CORE',
                'node_b_name' => 'AGG',
                'interface_a_name' => 'sfp-sfpplus1',
                'interface_b_name' => 'sfp-sfpplus2',
                'interface_a_status' => 1,
                'interface_b_status' => 1,
                'interface_a_rx' => -17.2,
                'interface_a_tx' => -1.1,
                'interface_b_rx' => -26.8,
                'interface_b_tx' => -2.4,
            ],
            [
                'id' => 2,
                'node_a_id' => 2,
                'node_b_id' => 3,
                'interface_a_id' => 9002,
                'interface_b_id' => 9001,
                'attenuation_db' => '24.1',
                'notes' => 'Demo uplink',
                'path_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'node_a_name' => 'AGG',
                'node_b_name' => 'OLT',
                'interface_a_name' => 'sfp-sfpplus2',
                'interface_b_name' => 'sfp-sfpplus1',
                'interface_a_status' => 1,
                'interface_b_status' => 1,
                'interface_a_rx' => -26.8,
                'interface_a_tx' => -2.4,
                'interface_b_rx' => -17.2,
                'interface_b_tx' => -1.1,
            ],
            [
                'id' => 3,
                'node_a_id' => 1,
                'node_b_id' => 4,
                'interface_a_id' => 9001,
                'interface_b_id' => 9001,
                'attenuation_db' => '-20.5',
                'notes' => 'Demo internet',
                'path_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'node_a_name' => 'CORE',
                'node_b_name' => 'CLOUD',
                'interface_a_name' => 'sfp-sfpplus1',
                'interface_b_name' => 'wan',
                'interface_a_status' => 1,
                'interface_b_status' => 1,
                'interface_a_rx' => -19.0,
                'interface_a_tx' => -2.0,
                'interface_b_rx' => -19.0,
                'interface_b_tx' => -2.0,
            ],
        ];
    }

    public static function mapDevices(): array
    {
        return array_map(function ($d) {
            return [
                'id' => $d['id'],
                'device_name' => $d['device_name'],
                'ip_address' => $d['ip_address'],
                'last_status' => $d['last_status'] ?? 'unknown',
            ];
        }, self::devices());
    }

    public static function users(int $currentUserId): array
    {
        $now = date('Y-m-d H:i:s');
        $all = [
            ['id' => 1, 'username' => 'admin-demo', 'full_name' => 'Admin Demo', 'role' => 'admin', 'is_active' => 1, 'created_at' => $now],
            ['id' => 2, 'username' => 'tech-demo', 'full_name' => 'Technician Demo', 'role' => 'technician', 'is_active' => 1, 'created_at' => $now],
            ['id' => 3, 'username' => 'viewer-demo', 'full_name' => 'Viewer Demo', 'role' => 'viewer', 'is_active' => 1, 'created_at' => $now],
            ['id' => 4, 'username' => 'disabled-demo', 'full_name' => 'Disabled Demo', 'role' => 'viewer', 'is_active' => 0, 'created_at' => $now],
        ];

        return array_values(array_filter($all, fn ($u) => (int) $u['id'] !== (int) $currentUserId));
    }

    public static function settings(): array
    {
        return [
            'bot_token' => '',
            'chat_id' => '',
            'alert_telegram_enabled' => '0',
            'alert_webui_enabled' => '1',
            'alert_interface_down' => '1',
            'alert_interface_up' => '1',
            'alert_interface_warning' => '1',
            'alert_device_down' => '1',
            'alert_device_up' => '1',
            'alert_rx_warning_high' => '-18.0',
            'alert_rx_warning_low' => '-25.0',
            'alert_rx_down_threshold' => '-40.0',
        ];
    }

    public static function alertLogs(): array
    {
        $now = time();
        $rows = [];
        for ($i = 0; $i < 24; $i++) {
            $t = $now - ($i * 300);
            $rows[] = [
                'id' => 1000 + $i,
                'created_at' => date('Y-m-d H:i:s', $t),
                'event_type' => ($i % 3 === 0) ? 'interface_warning' : (($i % 5 === 0) ? 'device_down' : 'interface_up'),
                'severity' => ($i % 5 === 0) ? 'critical' : (($i % 3 === 0) ? 'warning' : 'info'),
                'device_id' => 101,
                'device_name' => 'RTR-CORE-DEMO',
                'device_ip' => '10.10.0.1',
                'if_index' => 1,
                'if_name' => 'sfp-sfpplus1',
                'if_alias' => 'Uplink-1',
                'rx_power' => -20.5,
                'tx_power' => -2.1,
                'message' => 'Demo alert event (viewer mode)',
            ];
        }

        return $rows;
    }

    public static function securityLogsText(): string
    {
        $now = time();
        $lines = [];
        for ($i = 0; $i < 12; $i++) {
            $t = date('Y-m-d H:i:s', $now - ($i * 600));
            $lines[] = "[{$t}] [10.10.0." . (10 + $i) . "] [LOGIN_SUCCESS] user=viewer-demo msg=OK";
        }
        return implode("\n", array_reverse($lines));
    }

    // ── New dashboard widget data ────────────────────────────────────────────

    public static function dashboardDeviceHealth(): array
    {
        return ['total' => 6, 'active' => 5, 'inactive' => 0, 'failed' => 1];
    }

    public static function dashboardAlertTrend(): array
    {
        $base = [
            ['critical' => 0, 'warning' => 2, 'info' => 5],
            ['critical' => 1, 'warning' => 3, 'info' => 4],
            ['critical' => 0, 'warning' => 1, 'info' => 7],
            ['critical' => 2, 'warning' => 4, 'info' => 3],
            ['critical' => 0, 'warning' => 2, 'info' => 6],
            ['critical' => 1, 'warning' => 1, 'info' => 4],
            ['critical' => 0, 'warning' => 3, 'info' => 8],
        ];
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $idx = 6 - $i;
            $out[] = [
                'day'      => $day,
                'label'    => date('d M', strtotime($day)),
                'critical' => $base[$idx]['critical'],
                'warning'  => $base[$idx]['warning'],
                'info'     => $base[$idx]['info'],
            ];
        }
        return $out;
    }

    public static function dashboardWorstPorts(): array
    {
        return [
            (object)['device_name' => 'OLT-HIOSO-DEMO', 'ip_address' => '10.10.10.1', 'if_name' => 'sfp-sfpplus4', 'if_alias' => 'Client-4', 'rx_power' => -38.20, 'tx_power' => -2.10],
            (object)['device_name' => 'RTR-CORE-DEMO',  'ip_address' => '10.10.0.1',  'if_name' => 'sfp-sfpplus2', 'if_alias' => 'Uplink-2', 'rx_power' => -33.50, 'tx_power' => -1.80],
            (object)['device_name' => 'SW-AGG-DEMO',    'ip_address' => '10.10.0.2',  'if_name' => 'sfp-sfpplus6', 'if_alias' => 'Dist-6',   'rx_power' => -31.10, 'tx_power' => -2.40],
            (object)['device_name' => 'EDGE-POP-DEMO',  'ip_address' => '10.10.0.4',  'if_name' => 'sfp-sfpplus1', 'if_alias' => 'WAN',      'rx_power' => -28.90, 'tx_power' => -1.50],
            (object)['device_name' => 'OLT-HIOSO-DEMO', 'ip_address' => '10.10.10.1', 'if_name' => 'sfp-sfpplus7', 'if_alias' => 'Client-7', 'rx_power' => -26.40, 'tx_power' => -2.20],
            (object)['device_name' => 'RTR-CORE-DEMO',  'ip_address' => '10.10.0.1',  'if_name' => 'sfp-sfpplus3', 'if_alias' => 'Peering',  'rx_power' => -24.80, 'tx_power' => -1.90],
        ];
    }

    public static function dashboardRecentAlerts(): array
    {
        $now = time();
        $events = [
            ['interface_warning', 'warning',  'OLT-HIOSO-DEMO', 'sfp-sfpplus4', 'RX power degraded: -38.20 dBm (threshold: -35.00)'],
            ['interface_down',    'critical', 'EDGE-POP-DEMO',  'sfp-sfpplus1', 'Interface down detected'],
            ['interface_up',      'info',     'RTR-CORE-DEMO',  'sfp-sfpplus1', 'Interface restored to normal'],
            ['interface_warning', 'warning',  'SW-AGG-DEMO',    'sfp-sfpplus6', 'RX power degraded: -31.10 dBm'],
            ['device_down',       'critical', 'EDGE-POP-DEMO',  null,           'SNMP poll timeout after 3 retries'],
            ['interface_up',      'info',     'OLT-HIOSO-DEMO', 'sfp-sfpplus7', 'Interface restored'],
            ['interface_warning', 'warning',  'RTR-CORE-DEMO',  'sfp-sfpplus2', 'RX power low: -33.50 dBm'],
            ['device_up',         'info',     'SW-AGG-DEMO',    null,           'Device back online'],
        ];

        $out = [];
        foreach ($events as $idx => [$event_type, $severity, $device_name, $if_name, $message]) {
            $out[] = (object)[
                'event_type'  => $event_type,
                'severity'    => $severity,
                'device_name' => $device_name,
                'if_name'     => $if_name,
                'message'     => $message,
                'created_at'  => date('Y-m-d H:i:s', $now - ($idx * 420)),
            ];
        }
        return $out;
    }

    /**
     * Pengganti GET /api/v1/interfaces untuk viewer (bentuk respons sama dengan aslinya).
     * Dulu viewer menerima data interface asli lewat API mobile, padahal di web diberi dummy.
     */
    public static function apiInterfaces(int $page, int $perPage, int $deviceId = 0): array
    {
        $rows = [];
        foreach (self::devices() as $dev) {
            if ($deviceId > 0 && (int) $dev['id'] !== $deviceId) {
                continue;
            }
            foreach (self::interfaces((int) $dev['id']) as $if) {
                if ((int) $if['is_sfp'] !== 1) {
                    continue;
                }
                $rows[] = [
                    'history_24h' => str_repeat('g', 24),
                    'id' => (int) $if['id'] + (int) $dev['id'] * 10,
                    'device_id' => (int) $dev['id'],
                    'device_name' => $dev['device_name'],
                    'device_ip' => $dev['ip_address'],
                    'if_index' => (int) $if['if_index'],
                    'if_name' => $if['if_name'],
                    'if_alias' => $if['if_alias'],
                    'if_description' => null,
                    'rx_power' => $if['rx_power'],
                    'tx_power' => $if['tx_power'],
                    'oper_status' => 1,
                    'if_speed' => 10_000_000_000,
                    'in_rate_bps' => 120_000_000,
                    'out_rate_bps' => 45_000_000,
                    'last_seen' => $if['last_seen'],
                    'interface_type' => 'SFP+',
                ];
            }
        }

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return [
            'data' => array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage],
        ];
    }

    /** Pengganti GET /api/v1/interfaces/traffic-history untuk viewer. */
    public static function apiTrafficHistory(int $deviceId, int $ifIndex, string $range): array
    {
        $points = match ($range) {
            '7d' => 56, '30d' => 60, '3mo', '6mo', '1y' => 60,
            default => 24,
        };
        $step = match ($range) {
            '7d' => 3 * 3600, '30d' => 12 * 3600, '3mo' => 36 * 3600, '6mo' => 72 * 3600, '1y' => 6 * 86400,
            default => 3600,
        };

        $seed = ($deviceId * 31) + ($ifIndex * 7);
        $now = time();
        $data = [];
        for ($i = $points - 1; $i >= 0; $i--) {
            $t = $now - $i * $step;
            $n = (($seed + $t) % 13) / 13.0;
            $data[] = [
                'created_at' => date('Y-m-d H:i:s', $t),
                'in_rate_bps' => (int) (80_000_000 + $n * 60_000_000),
                'out_rate_bps' => (int) (30_000_000 + $n * 25_000_000),
            ];
        }
        $in = array_column($data, 'in_rate_bps');
        $out = array_column($data, 'out_rate_bps');

        $meta = self::interfaceMeta($deviceId, $ifIndex);
        unset($meta['rx_power']);

        return [
            'meta' => $meta + ['range' => $range],
            'data' => $data,
            'summary' => [
                'in_cur' => end($in), 'in_avg' => (int) (array_sum($in) / count($in)), 'in_max' => max($in),
                'out_cur' => end($out), 'out_avg' => (int) (array_sum($out) / count($out)), 'out_max' => max($out),
            ],
        ];
    }

    /** Pengganti GET /api/alert_mutes untuk viewer: satu mute rekaan, tanpa catatan produksi. */
    public static function alertMutes(): array
    {
        return [
            'global' => ['muted' => false, 'muted_until' => null],
            'devices' => [[
                'device_id' => 104,
                'device_name' => 'EDGE-POP-DEMO',
                'note' => 'Pemeliharaan (demo)',
                'muted_until' => date('Y-m-d H:i:s', time() + 2 * 3600),
            ]],
        ];
    }

    // ── Laporan SLA (viewer) ─────────────────────────────────────────────────
    // Dulu /api/sla* menyajikan interface_down_events produksi apa adanya (ribuan kejadian,
    // alias berisi nama mitra) ke akun demo. Kejadian di bawah dibuat relatif terhadap
    // sekarang supaya selalu jatuh di jendela 1/7/30/90 hari, dan memakai perangkat/port
    // yang sama dengan devices()/interfaces().

    /**
     * @return list<array{device_id:int,if_index:int,device_name:string,if_name:string,if_alias:?string,down_at:string,up_at:?string,duration_sec:?int}>
     */
    public static function slaDownEvents(): array
    {
        // [device_id, if_index, mulai (detik yang lalu), durasi (detik) | null = masih down]
        $spec = [
            [101, 1, 3 * 86400 + 4 * 3600, 240],
            [101, 1, 20 * 86400 + 7 * 3600, 720],
            [102, 2, 1 * 86400 + 2 * 3600, 120],
            [102, 2, 6 * 86400 + 9 * 3600, 2100],
            [102, 2, 11 * 86400 + 5 * 3600, 4800],
            [102, 2, 40 * 86400, 900],
            [102, 2, 75 * 86400, 3 * 3600],
            [103, 1, 15 * 86400 + 3 * 3600, 2 * 3600],
            [103, 2, 2 * 3600, null],
            [104, 1, 9 * 86400 + 3 * 3600, null],
            [104, 2, 2 * 86400 + 6 * 3600, 2700],
            [104, 2, 28 * 86400, 360],
        ];

        $now = time();
        $out = [];
        foreach ($spec as [$deviceId, $ifIndex, $ago, $duration]) {
            $meta = self::interfaceMeta($deviceId, $ifIndex);
            $start = $now - $ago;
            $out[] = [
                'device_id' => $deviceId,
                'if_index' => $ifIndex,
                'device_name' => $meta['device_name'],
                'if_name' => $meta['if_name'],
                'if_alias' => $meta['if_alias'],
                'down_at' => date('Y-m-d H:i:s', $start),
                'up_at' => $duration !== null ? date('Y-m-d H:i:s', $start + $duration) : null,
                'duration_sec' => $duration,
            ];
        }

        return $out;
    }

    /**
     * Baris ringkasan per interface, bentuk & aturan sama dengan query SlaController::summary():
     * kejadian yang menyentuh jendela ikut, down_count hanya yang mulai di dalam jendela,
     * downtime dipotong ke awal jendela, urut down_count lalu down_sec menurun.
     *
     * @return list<object>
     */
    public static function slaSummaryRows(int $days, int $deviceId = 0, string $q = ''): array
    {
        $now = time();
        $windowStart = $now - $days * 86400;
        $groups = [];

        foreach (self::slaDownEvents() as $e) {
            if ($deviceId > 0 && $e['device_id'] !== $deviceId) {
                continue;
            }
            if ($q !== '') {
                $hay = mb_strtolower($e['device_name'] . ' ' . $e['if_name'] . ' ' . ($e['if_alias'] ?? ''));
                if (!str_contains($hay, mb_strtolower($q))) {
                    continue;
                }
            }

            $start = strtotime($e['down_at']);
            $end = $e['up_at'] !== null ? strtotime($e['up_at']) : $now;
            if ($end < $windowStart) {
                continue;
            }

            $k = $e['device_id'] . ':' . $e['if_index'];
            $groups[$k] ??= [
                'device_id' => $e['device_id'],
                'if_index' => $e['if_index'],
                'device_name' => $e['device_name'],
                'if_name' => $e['if_name'],
                'if_alias' => $e['if_alias'],
                'down_count' => 0,
                'down_sec' => 0,
                'still_down' => 0,
                'last_down_at' => null,
            ];
            if ($start >= $windowStart) {
                $groups[$k]['down_count']++;
            }
            $groups[$k]['down_sec'] += max(0, $end - max($start, $windowStart));
            if ($e['up_at'] === null) {
                $groups[$k]['still_down'] = 1;
            }
            if ($groups[$k]['last_down_at'] === null || $e['down_at'] > $groups[$k]['last_down_at']) {
                $groups[$k]['last_down_at'] = $e['down_at'];
            }
        }

        $rows = array_values(array_filter($groups, fn ($g) => $g['down_count'] > 0));
        usort($rows, fn ($a, $b) => [$b['down_count'], $b['down_sec']] <=> [$a['down_count'], $a['down_sec']]);

        return array_map(fn ($g) => (object) $g, $rows);
    }

    /**
     * Kejadian down satu interface dalam jendela, bentuk sama dengan SlaController::events().
     *
     * @return list<array{down_at:string,up_at:?string,duration_sec:int,ongoing:bool}>
     */
    public static function slaInterfaceEvents(int $deviceId, int $ifIndex, int $days): array
    {
        $now = time();
        $windowStart = $now - $days * 86400;
        $out = [];

        foreach (self::slaDownEvents() as $e) {
            if ($e['device_id'] !== $deviceId || $e['if_index'] !== $ifIndex) {
                continue;
            }
            $end = $e['up_at'] !== null ? strtotime($e['up_at']) : $now;
            if ($end < $windowStart) {
                continue;
            }
            $out[] = [
                'down_at' => $e['down_at'],
                'up_at' => $e['up_at'],
                'duration_sec' => $e['duration_sec'] ?? max(0, $now - strtotime($e['down_at'])),
                'ongoing' => $e['up_at'] === null,
            ];
        }

        usort($out, fn ($a, $b) => strcmp($b['down_at'], $a['down_at']));

        return $out;
    }

    /** Port dummy yang down tanpa henti lebih dari $minDays hari (kandidat "tidak dipakai"). */
    public static function slaCandidates(int $minDays): array
    {
        $now = time();
        $cutoff = $now - $minDays * 86400;
        $out = [];

        foreach (self::slaDownEvents() as $e) {
            if ($e['up_at'] !== null || strtotime($e['down_at']) > $cutoff) {
                continue;
            }
            $out[] = [
                'device_id' => $e['device_id'],
                'if_index' => $e['if_index'],
                'device_name' => $e['device_name'],
                'if_name' => $e['if_name'],
                'if_alias' => $e['if_alias'],
                'oper_status' => 2,
                'rx_power' => -40.0,
                'down_at' => $e['down_at'],
                'down_days' => (int) floor(($now - strtotime($e['down_at'])) / 86400),
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['down_at'], $b['down_at']));

        return $out;
    }
}
