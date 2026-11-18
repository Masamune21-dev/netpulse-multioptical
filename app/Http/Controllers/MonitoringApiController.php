<?php

namespace App\Http\Controllers;

use App\Support\ViewerDummyData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonitoringApiController extends Controller
{
    public function devices(Request $request)
    {
        if (ViewerDummyData::isViewer($request)) {
            return response()->json(ViewerDummyData::monitoringDevices());
        }

        $devices = DB::table('snmp_devices')
            ->select(['id', 'device_name'])
            ->where('is_active', 1)
            ->orderBy('device_name')
            ->get();

        return response()->json($devices);
    }

    public function interfaces(Request $request)
    {
        $deviceId = (int) $request->query('device_id', 0);
        if ($deviceId <= 0) {
            return response()->json([]);
        }

        if (ViewerDummyData::isViewer($request)) {
            return response()->json(ViewerDummyData::monitoringInterfaces($deviceId));
        }

        $rows = DB::table('interfaces')
            // oper_status + last_seen wajib ikut: tanpa itu halaman Monitoring tidak bisa
            // membedakan "port masih nyala" dari "port mati dan grafiknya berhenti".
            ->select(['if_index', 'if_name', 'if_alias', 'tx_power', 'rx_power', 'is_monitored', 'oper_status', 'last_seen'])
            ->where('device_id', $deviceId)
            ->where('is_sfp', 1)
            // Port tidak dipakai disembunyikan kecuali diminta (?include_unmonitored=1).
            ->when(!$request->boolean('include_unmonitored'), fn ($q) => $q->where(function ($w) {
                $w->whereNull('is_monitored')->orWhere('is_monitored', 1);
            }))
            ->orderBy('if_index')
            ->get();

        // Ambang RX dipakai badge "Sekarang (RX)" di halaman Monitoring. Dikirim bersama
        // baris interface supaya badge memakai nilai pengaturan alert yang sama, bukan
        // angka hardcode di sisi JS.
        [$warnHigh, $warnLow, $downThreshold] = $this->rxThresholds();
        $overrides = $this->thresholdOverrides($deviceId);

        $rows = $rows->map(function ($r) use ($warnHigh, $warnLow, $downThreshold, $overrides) {
            $r->is_monitored = (int) ($r->is_monitored ?? 1) === 1;
            $r->oper_status = $r->oper_status !== null ? (int) $r->oper_status : null;

            $ovr = $overrides[(int) $r->if_index] ?? [];
            $r->rx_warn_high = $ovr['rx_warn_high'] ?? $warnHigh;
            $r->rx_warn_low = $ovr['rx_warn_low'] ?? $warnLow;
            $r->rx_down_threshold = $ovr['rx_down_threshold'] ?? $downThreshold;

            return $r;
        });

        return response()->json($rows);
    }

    /**
     * Ambang RX global (dBm) dari tabel settings, dengan nilai bawaan yang sama
     * seperti InterfaceDiscovery::loadAlertSettings().
     *
     * @return array{0:float,1:float,2:float} [warn_high, warn_low, down_threshold]
     */
    private function rxThresholds(): array
    {
        $settings = [];
        try {
            if (Schema::hasTable('settings')) {
                $settings = DB::table('settings')
                    ->whereIn('name', ['alert_rx_warning_high', 'alert_rx_warning_low', 'alert_rx_down_threshold'])
                    ->pluck('value', 'name')
                    ->all();
            }
        } catch (\Throwable $e) {
            $settings = [];
        }

        $num = function (string $key, float $fallback) use ($settings): float {
            $v = $settings[$key] ?? null;

            return is_numeric($v) ? (float) $v : $fallback;
        };

        return [
            $num('alert_rx_warning_high', -18.0),
            $num('alert_rx_warning_low', -25.0),
            $num('alert_rx_down_threshold', -40.0),
        ];
    }

    /**
     * Ambang per-port dari interface_thresholds (kolom null = pakai nilai global).
     *
     * @return array<int,array{rx_warn_high:?float,rx_warn_low:?float,rx_down_threshold:?float}>
     */
    private function thresholdOverrides(int $deviceId): array
    {
        try {
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
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function chart(Request $request)
    {
        $deviceId = (int) $request->query('device_id', 0);
        $ifIndex = (int) $request->query('if_index', 0);
        $range = $request->query('range', '1h');

        if (ViewerDummyData::isViewer($request)) {
            return response()->json(ViewerDummyData::interfaceChart($deviceId, $ifIndex, (string) $range));
        }

        // Pick the data source per range. Short ranges read raw per-minute
        // samples (kept ~30 days); longer ranges read the rollup tables so
        // redaman history survives after raw is pruned.
        //   source: 'raw' | 'hourly' | 'daily'
        [$interval, $source] = match ($range) {
            '1h'  => ['1 HOUR', 'raw'],
            '1d'  => ['24 HOUR', 'raw'],
            '3d'  => ['72 HOUR', 'raw'],
            '7d'  => ['7 DAY', 'raw'],
            '30d' => ['30 DAY', 'hourly'],
            '3mo' => ['3 MONTH', 'hourly'],
            '6mo' => ['6 MONTH', 'daily'],
            '1y'  => ['1 YEAR', 'daily'],
            default => ['1 HOUR', 'raw'],
        };

        if ($source === 'raw') {
            $sql = "SELECT created_at, tx_power, rx_power, loss
                    FROM interface_stats
                    WHERE device_id = ? AND if_index = ?
                      AND created_at >= NOW() - INTERVAL $interval
                    ORDER BY created_at ASC";
        } else {
            // Rollup tables already store min/avg/max per bucket. Expose avg as
            // the main line plus min/max so the UI can show a redaman band.
            $table = $source === 'daily' ? 'interface_stats_daily' : 'interface_stats_hourly';
            $sql = "SELECT bucket AS created_at,
                           tx_avg AS tx_power, rx_avg AS rx_power, loss_avg AS loss,
                           rx_min, rx_max, tx_min, tx_max, loss_min, loss_max
                    FROM $table
                    WHERE device_id = ? AND if_index = ?
                      AND bucket >= NOW() - INTERVAL $interval
                    ORDER BY bucket ASC";
        }

        $rows = DB::select($sql, [$deviceId, $ifIndex]);

        $defaultDownRx = -40.00;
        $data = [];

        foreach ($rows as $row) {
            $rx = $row->rx_power;
            $tx = $row->tx_power;

            if ($rx === null || $rx === '') {
                $rx = $defaultDownRx;
            } else {
                $rx = (float) $rx;
            }

            if ($tx !== null && $tx !== '') {
                $tx = (float) $tx;
            } else {
                $tx = null;
            }

            $loss = ($tx !== null && $rx !== null) ? $tx - $rx : null;

            $point = [
                'created_at' => $row->created_at,
                'tx_power' => $tx,
                'rx_power' => $rx,
                'loss' => $loss,
            ];

            // Rollup sources carry a min/max band for redaman trend analysis.
            foreach (['rx_min', 'rx_max', 'tx_min', 'tx_max', 'loss_min', 'loss_max'] as $band) {
                if (isset($row->$band) && $row->$band !== null) {
                    $point[$band] = (float) $row->$band;
                }
            }

            $data[] = $point;
        }

        if (empty($data)) {
            $now = time();
            for ($i = 0; $i < 12; $i++) {
                $time = date('Y-m-d H:i:s', $now - ($i * 300));
                $data[] = [
                    'created_at' => $time,
                    'tx_power' => null,
                    'rx_power' => $defaultDownRx,
                    'loss' => null,
                ];
            }
            usort($data, fn ($a, $b) => strtotime($a['created_at']) <=> strtotime($b['created_at']));
        }

        return response()->json($data);
    }
}
