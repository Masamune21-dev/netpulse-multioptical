<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\RxThresholds;
use App\Support\ViewerDummyData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = (string) ($user->role ?? '');

        if ($role === 'viewer') {
            $c = ViewerDummyData::dashboardCounts();
            $dh = ViewerDummyData::dashboardDeviceHealth();
            return response()->json([
                'success' => true,
                'data' => [
                    'device_count'     => $c['deviceCount'],
                    'device_total'     => $dh['total'],
                    'interface_count'  => $c['ifCount'],
                    'if_up_count'      => 28,
                    'if_down_count'    => 4,
                    'sfp_count'        => $c['sfpCount'],
                    'bad_optical_count'=> $c['badOptical'],
                    'user_count'       => $c['userCount'],
                    'device_health'    => $dh,
                    'worst_ports'      => collect(ViewerDummyData::dashboardWorstPorts())->map(fn($p) => (array)$p)->values()->all(),
                    'recent_alerts'    => collect(ViewerDummyData::dashboardRecentAlerts())->map(fn($a) => (array)$a)->values()->all(),
                    'thresholds'       => ViewerDummyData::globalRxThresholds(),
                ],
            ]);
        }

        if (!in_array($role, ['admin', 'technician'], true)) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $counts = DB::selectOne("
            SELECT
                (SELECT COUNT(*) FROM snmp_devices WHERE is_active = 1)                        AS device_count,
                (SELECT COUNT(*) FROM snmp_devices)                                             AS device_total,
                (SELECT COUNT(*) FROM interfaces WHERE COALESCE(is_monitored, 1) = 1)                                               AS interface_count,
                (SELECT COUNT(*) FROM interfaces WHERE is_sfp = 1 AND COALESCE(is_monitored, 1) = 1 AND tx_power IS NOT NULL)    AS sfp_count
        ");

        $badOpticalCount = 0;
        if (Schema::hasTable('alert_logs')) {
            $badRow = DB::selectOne("
                SELECT COUNT(*) as bad_optical_count
                FROM interfaces i
                WHERE i.is_sfp = 1 AND COALESCE(i.is_monitored, 1) = 1
                  AND i.oper_status IS NOT NULL
                  AND i.oper_status <> 1
                  AND EXISTS (
                      SELECT 1
                      FROM alert_logs al
                      WHERE al.device_id = i.device_id
                        AND al.if_name = i.if_name
                        AND al.event_type = 'interface_down'
                  )
            ");
            $badOpticalCount = (int) ($badRow->bad_optical_count ?? 0);
        }

        $userCountRow = DB::selectOne("SELECT COUNT(*) as user_count FROM users");

        // ── Device Health Breakdown ──────────────────────────────────────────
        $deviceHealthRow = DB::selectOne("
            SELECT
                COUNT(*)                                                  AS total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END)           AS active,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END)           AS inactive,
                SUM(CASE WHEN last_status = 'FAILED' THEN 1 ELSE 0 END)  AS failed
            FROM snmp_devices
        ");

        $deviceHealth = [
            'total'    => (int) ($deviceHealthRow->total    ?? 0),
            'active'   => (int) ($deviceHealthRow->active   ?? 0),
            'inactive' => (int) ($deviceHealthRow->inactive ?? 0),
            'failed'   => (int) ($deviceHealthRow->failed   ?? 0),
        ];

        // ── Interface Up / Down counts ───────────────────────────────────────
        $ifStatusRow = DB::selectOne("
            SELECT
                SUM(CASE WHEN oper_status = 1 THEN 1 ELSE 0 END)  AS up_count,
                SUM(CASE WHEN oper_status <> 1 THEN 1 ELSE 0 END) AS down_count
            FROM interfaces
            WHERE is_sfp = 1 AND COALESCE(is_monitored, 1) = 1 AND oper_status IS NOT NULL
        ");

        $ifUpCount   = (int) ($ifStatusRow->up_count   ?? 0);
        $ifDownCount = (int) ($ifStatusRow->down_count ?? 0);

        // ── Worst SFP Ports (6 lowest RX power) ─────────────────────────────
        // Hanya port yang hidup dan punya pembacaan nyata: port down / SFP kosong
        // bernilai −40 (tanpa pembacaan) dikecualikan supaya daftar ini benar-benar
        // menunjukkan port marjinal, bukan slot kosong.
        $thresholds = RxThresholds::global();
        $worstPorts = DB::select("
            SELECT i.if_name, i.if_alias, i.rx_power, i.tx_power,
                   d.device_name, d.ip_address
            FROM interfaces i
            JOIN snmp_devices d ON d.id = i.device_id
            WHERE i.is_sfp = 1 AND COALESCE(i.is_monitored, 1) = 1
              AND i.oper_status = 1
              AND i.rx_power IS NOT NULL
              AND i.rx_power > ?
              AND i.tx_power IS NOT NULL
            ORDER BY i.rx_power ASC
            LIMIT 6
        ", [$thresholds['rx_down_threshold']]);

        // ── Recent Alerts (8 latest) ─────────────────────────────────────────
        $recentAlerts = [];
        if (Schema::hasTable('alert_logs')) {
            $recentAlerts = DB::select("
                SELECT event_type, severity, device_name, if_name, message, created_at
                FROM alert_logs
                ORDER BY created_at DESC
                LIMIT 8
            ");
        }

        return response()->json([
            'success' => true,
            'data' => [
                'device_count'      => (int) ($counts->device_count    ?? 0),
                'device_total'      => (int) ($counts->device_total    ?? 0),
                'interface_count'   => (int) ($counts->interface_count ?? 0),
                'if_up_count'       => $ifUpCount,
                'if_down_count'     => $ifDownCount,
                'sfp_count'         => (int) ($counts->sfp_count       ?? 0),
                'bad_optical_count' => $badOpticalCount,
                'user_count'        => (int) ($userCountRow->user_count ?? 0),
                'device_health'     => $deviceHealth,
                'worst_ports'       => collect($worstPorts)->map(fn($p) => (array)$p)->values()->all(),
                'recent_alerts'     => collect($recentAlerts)->map(fn($a) => (array)$a)->values()->all(),
                'thresholds'        => $thresholds,
            ],
        ]);
    }
}
