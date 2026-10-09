<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\RxThresholds;
use App\Support\ViewerDummyData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InterfacesController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = (string) ($user->role ?? '');

        if (!in_array($role, ['admin', 'technician', 'viewer'], true)) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        // Viewer = akun demo: data dummy, sama seperti halaman web (MonitoringController).
        if ($role === 'viewer') {
            $perPage = (int) $request->query('per_page', 25);
            $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
            $dummy = ViewerDummyData::apiInterfaces(max(1, (int) $request->query('page', 1)), $perPage, (int) $request->query('device_id', 0));
            $dummy['meta']['thresholds'] = ViewerDummyData::globalRxThresholds();

            return response()->json(['success' => true] + $dummy);
        }

        if (!Schema::hasTable('interfaces')) {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => ['total' => 0, 'page' => 1, 'per_page' => 25, 'last_page' => 1],
            ]);
        }

        $perPage = (int) $request->query('per_page', 25);
        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 25;
        }

        $page = max(1, (int) $request->query('page', 1));
        $deviceId = (int) $request->query('device_id', 0);
        $status = strtolower(trim((string) $request->query('status', 'all')));
        $q = trim((string) $request->query('q', ''));
        $sort = strtolower(trim((string) $request->query('sort', 'device')));
        $thresholds = RxThresholds::global();

        $base = DB::table('interfaces')
            ->leftJoin('snmp_devices', 'interfaces.device_id', '=', 'snmp_devices.id')
            ->where('interfaces.is_sfp', 1);

        if ($deviceId > 0) {
            $base->where('interfaces.device_id', $deviceId);
        }

        // Port yang ditandai tidak dipakai tidak ikut, kecuali ?include_unmonitored=1.
        if (!$request->boolean('include_unmonitored')) {
            $base->where(function ($sub) {
                $sub->whereNull('interfaces.is_monitored')->orWhere('interfaces.is_monitored', 1);
            });
        }

        if ($status === 'up') {
            $base->where('interfaces.oper_status', 1);
        } elseif ($status === 'down') {
            $base->where(function ($sub) {
                $sub->whereNull('interfaces.oper_status')
                    ->orWhere('interfaces.oper_status', '!=', 1);
            });
        } elseif ($status === 'warn') {
            // Marjinal: port masih up tetapi RX di bawah ambang peringatan.
            $base->where('interfaces.oper_status', 1)
                ->whereNotNull('interfaces.rx_power')
                ->where('interfaces.rx_power', '<', $thresholds['rx_warn_low'])
                ->where('interfaces.rx_power', '>', $thresholds['rx_down_threshold']);
        }

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $base->where(function ($sub) use ($like) {
                $sub->where('interfaces.if_name', 'like', $like)
                    ->orWhere('interfaces.if_alias', 'like', $like)
                    ->orWhere('interfaces.if_description', 'like', $like)
                    ->orWhere('snmp_devices.device_name', 'like', $like);
            });
        }

        $total = (clone $base)->count('interfaces.id');
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        if ($page > $lastPage) {
            $page = $lastPage;
        }
        $offset = ($page - 1) * $perPage;

        $rows = $base
            ->select([
                'interfaces.id',
                'interfaces.device_id',
                'snmp_devices.device_name',
                'snmp_devices.ip_address',
                'interfaces.if_index',
                'interfaces.if_name',
                'interfaces.if_alias',
                'interfaces.if_description',
                'interfaces.rx_power',
                'interfaces.tx_power',
                'interfaces.oper_status',
                'interfaces.if_speed',
                'interfaces.in_rate_bps',
                'interfaces.out_rate_bps',
                'interfaces.last_seen',
                'interfaces.interface_type',
            ])
            // sort=rx: port hidup dengan RX terlemah di atas; port down / tanpa
            // DDM (−40 = tanpa pembacaan) ke bawah supaya yang marjinal tampak.
            ->when($sort === 'rx', fn ($qq) => $qq
                ->orderByRaw('(interfaces.oper_status IS NULL OR interfaces.oper_status <> 1)')
                ->orderByRaw('(interfaces.rx_power IS NULL OR interfaces.rx_power <= ?)', [$thresholds['rx_down_threshold']])
                ->orderBy('interfaces.rx_power'))
            ->orderBy('snmp_devices.device_name')
            ->orderBy('interfaces.if_index')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $history = RxThresholds::history24h(
            $rows->map(fn ($r) => [(int) $r->device_id, (int) $r->if_index])->all(),
            $thresholds,
        );

        $data = $rows->map(fn ($r) => [
            'history_24h' => $history[((int) $r->device_id) . ':' . ((int) $r->if_index)] ?? str_repeat('n', 24),
            'id' => (int) $r->id,
            'device_id' => (int) $r->device_id,
            'device_name' => $r->device_name !== null ? (string) $r->device_name : null,
            'device_ip' => $r->ip_address !== null ? (string) $r->ip_address : null,
            'if_index' => (int) $r->if_index,
            'if_name' => $r->if_name !== null ? (string) $r->if_name : null,
            'if_alias' => $r->if_alias !== null ? (string) $r->if_alias : null,
            'if_description' => $r->if_description !== null ? (string) $r->if_description : null,
            'rx_power' => $r->rx_power !== null ? (float) $r->rx_power : null,
            'tx_power' => $r->tx_power !== null ? (float) $r->tx_power : null,
            'oper_status' => $r->oper_status !== null ? (int) $r->oper_status : null,
            'if_speed' => $r->if_speed !== null ? (int) $r->if_speed : null,
            'in_rate_bps' => $r->in_rate_bps !== null ? (int) $r->in_rate_bps : null,
            'out_rate_bps' => $r->out_rate_bps !== null ? (int) $r->out_rate_bps : null,
            'last_seen' => $r->last_seen !== null ? (string) $r->last_seen : null,
            'interface_type' => $r->interface_type !== null ? (string) $r->interface_type : null,
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'thresholds' => $thresholds,
            ],
        ]);
    }

    public function trafficHistory(Request $request)
    {
        $user = $request->user();
        $role = (string) ($user->role ?? '');

        if (!in_array($role, ['admin', 'technician', 'viewer'], true)) {
            return response()->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $deviceId = (int) $request->query('device_id', 0);
        $ifIndex = (int) $request->query('if_index', 0);
        $range = strtolower(trim((string) $request->query('range', '1d')));
        if (!in_array($range, ['1d', '7d', '30d', '3mo', '6mo', '1y'], true)) {
            $range = '1d';
        }

        if ($deviceId <= 0 || $ifIndex <= 0) {
            return response()->json(['success' => false, 'error' => 'Missing device_id or if_index'], 400);
        }

        if ($role === 'viewer') {
            return response()->json(['success' => true] + ViewerDummyData::apiTrafficHistory($deviceId, $ifIndex, $range));
        }

        // Short ranges read raw per-minute rows (kept ~30 days); longer ranges
        // read rollup tables so history survives after raw is pruned.
        [$intervalSql, $source] = match ($range) {
            '7d'  => ['INTERVAL 7 DAY', 'raw'],
            '30d' => ['INTERVAL 30 DAY', 'hourly'],
            '3mo' => ['INTERVAL 3 MONTH', 'hourly'],
            '6mo' => ['INTERVAL 6 MONTH', 'daily'],
            '1y'  => ['INTERVAL 1 YEAR', 'daily'],
            default => ['INTERVAL 1 DAY', 'raw'],
        };

        $iface = DB::table('interfaces')
            ->leftJoin('snmp_devices', 'interfaces.device_id', '=', 'snmp_devices.id')
            ->where('interfaces.device_id', $deviceId)
            ->where('interfaces.if_index', $ifIndex)
            ->select([
                'interfaces.if_name', 'interfaces.if_alias', 'interfaces.if_description',
                'interfaces.if_speed', 'interfaces.oper_status', 'interfaces.interface_type',
                'snmp_devices.device_name', 'snmp_devices.ip_address',
            ])
            ->first();

        if (!$iface) {
            return response()->json(['success' => false, 'error' => 'Interface not found'], 404);
        }

        $meta = [
            'device_name' => $iface->device_name ?? null,
            'device_ip' => $iface->ip_address ?? null,
            'if_name' => $iface->if_name ?? null,
            'if_alias' => $iface->if_alias ?? null,
            'if_description' => $iface->if_description ?? null,
            'if_speed' => $iface->if_speed !== null ? (int) $iface->if_speed : null,
            'oper_status' => $iface->oper_status !== null ? (int) $iface->oper_status : null,
            'interface_type' => $iface->interface_type ?? null,
            'range' => $range,
        ];

        if (!Schema::hasTable('interface_traffic_stats')) {
            return response()->json([
                'success' => true,
                'meta' => $meta,
                'data' => [],
                'summary' => $this->emptySummary(),
            ]);
        }

        if ($source === 'raw') {
            $sql = "SELECT created_at, in_rate_bps, out_rate_bps,
                           NULL AS in_rate_max, NULL AS out_rate_max
                    FROM interface_traffic_stats
                    WHERE device_id = ? AND if_index = ?
                      AND created_at >= NOW() - $intervalSql
                    ORDER BY created_at ASC";
        } else {
            $table = $source === 'daily' ? 'interface_traffic_daily' : 'interface_traffic_hourly';
            $sql = "SELECT bucket AS created_at,
                           in_rate_avg AS in_rate_bps, out_rate_avg AS out_rate_bps,
                           in_rate_max, out_rate_max
                    FROM $table
                    WHERE device_id = ? AND if_index = ?
                      AND bucket >= NOW() - $intervalSql
                    ORDER BY bucket ASC";
        }

        $rows = DB::select($sql, [$deviceId, $ifIndex]);

        $data = [];
        $inSum = 0.0; $outSum = 0.0;
        $inMax = null; $outMax = null;
        $inCount = 0; $outCount = 0;
        $inCur = null; $outCur = null;

        foreach ($rows as $row) {
            $inV = $row->in_rate_bps !== null ? (int) $row->in_rate_bps : null;
            $outV = $row->out_rate_bps !== null ? (int) $row->out_rate_bps : null;

            // For rollup sources the true peak is the bucket's stored max.
            $inPeak = (isset($row->in_rate_max) && $row->in_rate_max !== null) ? (int) $row->in_rate_max : $inV;
            $outPeak = (isset($row->out_rate_max) && $row->out_rate_max !== null) ? (int) $row->out_rate_max : $outV;

            $data[] = [
                'created_at' => (string) $row->created_at,
                'in_rate_bps' => $inV,
                'out_rate_bps' => $outV,
            ];

            if ($inV !== null) {
                $inSum += $inV; $inCount++;
                if ($inPeak !== null && ($inMax === null || $inPeak > $inMax)) $inMax = $inPeak;
                $inCur = $inV;
            }
            if ($outV !== null) {
                $outSum += $outV; $outCount++;
                if ($outPeak !== null && ($outMax === null || $outPeak > $outMax)) $outMax = $outPeak;
                $outCur = $outV;
            }
        }

        return response()->json([
            'success' => true,
            'meta' => $meta,
            'data' => $data,
            'summary' => [
                'in_cur' => $inCur,
                'in_avg' => $inCount > 0 ? (int) ($inSum / $inCount) : null,
                'in_max' => $inMax,
                'out_cur' => $outCur,
                'out_avg' => $outCount > 0 ? (int) ($outSum / $outCount) : null,
                'out_max' => $outMax,
            ],
        ]);
    }

    private function emptySummary(): array
    {
        return [
            'in_cur' => null, 'in_avg' => null, 'in_max' => null,
            'out_cur' => null, 'out_avg' => null, 'out_max' => null,
        ];
    }
}
