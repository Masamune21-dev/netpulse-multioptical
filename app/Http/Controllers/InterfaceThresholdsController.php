<?php

namespace App\Http\Controllers;

use App\Support\ViewerDummyData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin management of per-interface RX threshold overrides.
 *
 * RX warn/down thresholds are global by default; this lets an admin override
 * any of the three for a single interface (e.g. a long-haul SFP whose normal
 * RX is much lower). NULL on a column means "use the global value".
 */
class InterfaceThresholdsController extends Controller
{
    private const RX_MIN = -60.0;
    private const RX_MAX = 10.0;

    /** GET: effective thresholds (global + override + a suggestion from history). */
    public function show(Request $request)
    {
        $deviceId = (int) $request->query('device_id', 0);
        $ifIndex = (int) $request->query('if_index', 0);
        if ($deviceId <= 0 || $ifIndex <= 0) {
            return response()->json(['success' => false, 'error' => 'Missing device_id or if_index'], 400);
        }

        // Viewer (akun demo): meta port, ambang global, override, dan saran baseline semuanya
        // rekaan — dulu nama perangkat, alias, RX terkini, dan override produksi ikut terkirim.
        if (ViewerDummyData::isViewer($request)) {
            $meta = ViewerDummyData::interfaceMeta($deviceId, $ifIndex);
            $global = ViewerDummyData::rxThresholds();

            return response()->json([
                'success' => true,
                'meta' => [
                    'device_id' => $deviceId,
                    'if_index' => $ifIndex,
                    'device_name' => $meta['device_name'],
                    'if_name' => $meta['if_name'],
                    'if_alias' => $meta['if_alias'],
                    'current_rx' => $meta['rx_power'] !== null ? (float) $meta['rx_power'] : null,
                ],
                'global' => $global,
                'override' => ['rx_warn_high' => null, 'rx_warn_low' => null, 'rx_down_threshold' => null],
                'effective' => $global,
                'suggestion' => ['available' => false],
            ]);
        }

        $iface = DB::table('interfaces')
            ->leftJoin('snmp_devices', 'interfaces.device_id', '=', 'snmp_devices.id')
            ->where('interfaces.device_id', $deviceId)
            ->where('interfaces.if_index', $ifIndex)
            ->select([
                'interfaces.if_name', 'interfaces.if_alias', 'interfaces.rx_power',
                'snmp_devices.device_name',
            ])
            ->first();
        if (!$iface) {
            return response()->json(['success' => false, 'error' => 'Interface not found'], 404);
        }

        $global = $this->globalThresholds();
        $override = $this->override($deviceId, $ifIndex);

        $effective = [
            'rx_warn_high' => $override['rx_warn_high'] ?? $global['rx_warn_high'],
            'rx_warn_low' => $override['rx_warn_low'] ?? $global['rx_warn_low'],
            'rx_down_threshold' => $override['rx_down_threshold'] ?? $global['rx_down_threshold'],
        ];

        return response()->json([
            'success' => true,
            'meta' => [
                'device_id' => $deviceId,
                'if_index' => $ifIndex,
                'device_name' => $iface->device_name ?? null,
                'if_name' => $iface->if_name ?? null,
                'if_alias' => $iface->if_alias ?? null,
                'current_rx' => $iface->rx_power !== null ? (float) $iface->rx_power : null,
            ],
            'global' => $global,
            'override' => $override,
            'effective' => $effective,
            'suggestion' => $this->suggestion($deviceId, $ifIndex, $global),
        ]);
    }

    /** POST: set/clear the override. Null (or absent) column = use global. */
    public function save(Request $request)
    {
        $body = $request->json()->all();
        $deviceId = (int) ($body['device_id'] ?? 0);
        $ifIndex = (int) ($body['if_index'] ?? 0);
        if ($deviceId <= 0 || $ifIndex <= 0) {
            return response()->json(['success' => false, 'error' => 'Missing device_id or if_index'], 400);
        }

        if (!DB::table('interfaces')->where('device_id', $deviceId)->where('if_index', $ifIndex)->exists()) {
            return response()->json(['success' => false, 'error' => 'Interface not found'], 404);
        }

        $cols = [];
        foreach (['rx_warn_high', 'rx_warn_low', 'rx_down_threshold'] as $k) {
            if (!array_key_exists($k, $body)) {
                $cols[$k] = null;
                continue;
            }
            $v = $body[$k];
            if ($v === null || $v === '') {
                $cols[$k] = null;
                continue;
            }
            if (!is_numeric($v) || (float) $v < self::RX_MIN || (float) $v > self::RX_MAX) {
                return response()->json(['success' => false, 'error' => "Invalid {$k} (expected dBm in [-60, 10])"], 422);
            }
            $cols[$k] = round((float) $v, 2);
        }

        // All cleared → remove the override entirely (revert fully to global).
        if ($cols['rx_warn_high'] === null && $cols['rx_warn_low'] === null && $cols['rx_down_threshold'] === null) {
            DB::table('interface_thresholds')->where('device_id', $deviceId)->where('if_index', $ifIndex)->delete();
            return response()->json(['success' => true, 'cleared' => true]);
        }

        DB::table('interface_thresholds')->updateOrInsert(
            ['device_id' => $deviceId, 'if_index' => $ifIndex],
            $cols + ['updated_at' => now()]
        );

        return response()->json(['success' => true, 'override' => $this->override($deviceId, $ifIndex)]);
    }

    /** DELETE: remove the override (revert to global). */
    public function clear(Request $request)
    {
        $deviceId = (int) ($request->input('device_id', 0));
        $ifIndex = (int) ($request->input('if_index', 0));
        if ($deviceId <= 0 || $ifIndex <= 0) {
            return response()->json(['success' => false, 'error' => 'Missing device_id or if_index'], 400);
        }
        DB::table('interface_thresholds')->where('device_id', $deviceId)->where('if_index', $ifIndex)->delete();
        return response()->json(['success' => true, 'cleared' => true]);
    }

    private function globalThresholds(): array
    {
        $rows = DB::table('settings')
            ->whereIn('name', ['alert_rx_warning_high', 'alert_rx_warning_low', 'alert_rx_down_threshold'])
            ->pluck('value', 'name');

        return [
            'rx_warn_high' => is_numeric($rows['alert_rx_warning_high'] ?? null) ? (float) $rows['alert_rx_warning_high'] : -18.0,
            'rx_warn_low' => is_numeric($rows['alert_rx_warning_low'] ?? null) ? (float) $rows['alert_rx_warning_low'] : -25.0,
            'rx_down_threshold' => is_numeric($rows['alert_rx_down_threshold'] ?? null) ? (float) $rows['alert_rx_down_threshold'] : -40.0,
        ];
    }

    /** @return array{rx_warn_high:?float,rx_warn_low:?float,rx_down_threshold:?float} */
    private function override(int $deviceId, int $ifIndex): array
    {
        $row = DB::table('interface_thresholds')
            ->where('device_id', $deviceId)->where('if_index', $ifIndex)
            ->first(['rx_warn_high', 'rx_warn_low', 'rx_down_threshold']);

        return [
            'rx_warn_high' => $row && $row->rx_warn_high !== null ? (float) $row->rx_warn_high : null,
            'rx_warn_low' => $row && $row->rx_warn_low !== null ? (float) $row->rx_warn_low : null,
            'rx_down_threshold' => $row && $row->rx_down_threshold !== null ? (float) $row->rx_down_threshold : null,
        ];
    }

    /**
     * Suggest thresholds relative to the interface's learned baseline RX
     * (avg of daily best-RX over the last 14 up-days): warn 3 dB below normal,
     * critical 6 dB below. Down stays on the global value.
     */
    private function suggestion(int $deviceId, int $ifIndex, array $global): array
    {
        if (!Schema::hasTable('interface_stats_daily')) {
            return ['available' => false];
        }

        $row = DB::selectOne("
            SELECT AVG(rx_max) AS baseline, COUNT(*) AS days
            FROM interface_stats_daily
            WHERE device_id = ? AND if_index = ?
              AND bucket >= (CURDATE() - INTERVAL 14 DAY)
              AND rx_max > -38
        ", [$deviceId, $ifIndex]);

        if (!$row || $row->baseline === null || (int) $row->days < 3) {
            return ['available' => false];
        }

        $baseline = round((float) $row->baseline, 2);

        return [
            'available' => true,
            'baseline_rx' => $baseline,
            'based_on_days' => (int) $row->days,
            'rx_warn_high' => round($baseline - 3.0, 1),
            'rx_warn_low' => round($baseline - 6.0, 1),
            'rx_down_threshold' => $global['rx_down_threshold'],
        ];
    }
}
