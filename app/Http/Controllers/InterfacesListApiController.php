<?php

namespace App\Http\Controllers;

use App\Support\ViewerDummyData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InterfacesListApiController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('interfaces')) {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => ['total' => 0, 'page' => 1, 'per_page' => 25, 'last_page' => 1],
            ]);
        }

        $perPage = (int) $request->query('per_page', 25);
        if (!in_array($perPage, [10, 25, 50], true)) {
            $perPage = 25;
        }

        $page = (int) $request->query('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        $deviceId = (int) $request->query('device_id', 0);
        $status = strtolower(trim((string) $request->query('status', 'all')));
        $q = trim((string) $request->query('q', ''));

        if (ViewerDummyData::isViewer($request)) {
            return $this->dummyResponse($perPage, $page, $deviceId, $status, $q);
        }

        $base = DB::table('interfaces')
            ->leftJoin('snmp_devices', 'interfaces.device_id', '=', 'snmp_devices.id')
            ->where('interfaces.is_sfp', 1);

        if ($deviceId > 0) {
            $base->where('interfaces.device_id', $deviceId);
        }

        // Port tidak dipakai (is_monitored = 0) disembunyikan secara bawaan.
        // monitored=all menampilkan semua, monitored=unmonitored hanya yang tidak dipakai.
        $monitoredFilter = strtolower(trim((string) $request->query('monitored', 'monitored')));
        $unmonitoredTotal = (clone $base)->where('interfaces.is_monitored', 0)->count('interfaces.id');
        if ($monitoredFilter === 'unmonitored') {
            $base->where('interfaces.is_monitored', 0);
        } elseif ($monitoredFilter !== 'all') {
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
        }

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $base->where(function ($sub) use ($like) {
                $sub->where('interfaces.if_name', 'like', $like)
                    ->orWhere('interfaces.if_alias', 'like', $like)
                    ->orWhere('interfaces.if_description', 'like', $like);
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
                'interfaces.is_monitored',
            ])
            ->orderBy('snmp_devices.device_name')
            ->orderBy('interfaces.if_index')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $reasons = InterfaceMonitoringController::latestReasons(
            $rows->filter(fn ($r) => (int) ($r->is_monitored ?? 1) === 0)
                ->map(fn ($r) => [(int) $r->device_id, (int) $r->if_index])->values()->all()
        );

        $data = $rows->map(function ($r) use ($reasons) {
            $monitored = (int) ($r->is_monitored ?? 1) === 1;
            $why = $reasons[$r->device_id . ':' . $r->if_index] ?? null;
            return [
                'is_monitored' => $monitored,
                'unmonitored_reason' => $monitored ? null : ($why['reason'] ?? null),
                'unmonitored_by' => $monitored ? null : ($why['changed_by'] ?? null),
                'unmonitored_at' => $monitored ? null : ($why['changed_at'] ?? null),
                // Port tidak dipakai yang kini hidup lagi dengan sinyal: patut dicek.
                'active_again' => !$monitored && (int) $r->oper_status === 1 && $r->rx_power !== null && (float) $r->rx_power > -40,
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
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'unmonitored_total' => $unmonitoredTotal,
            ],
        ]);
    }

    public function trafficHistory(Request $request)
    {
        $deviceId = (int) $request->query('device_id', 0);
        $ifIndex = (int) $request->query('if_index', 0);
        $range = strtolower(trim((string) $request->query('range', '1d')));
        if (!in_array($range, ['1d', '7d', '30d', '3mo', '6mo', '1y'], true)) {
            $range = '1d';
        }

        if ($deviceId <= 0 || $ifIndex <= 0) {
            return response()->json(['success' => false, 'error' => 'Missing device_id or if_index'], 400);
        }

        // Viewer (akun demo) dijawab sebelum tabel produksi disentuh. Dulu cek ini ada di
        // bawah query interface sehingga meta (nama/IP perangkat, alias port, status) yang
        // dikirim tetap milik perangkat asli — cukup menebak device_id produksi.
        if (ViewerDummyData::isViewer($request)) {
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
                'interfaces.if_name',
                'interfaces.if_alias',
                'interfaces.if_description',
                'interfaces.if_speed',
                'interfaces.oper_status',
                'interfaces.interface_type',
                'snmp_devices.device_name',
                'snmp_devices.ip_address',
            ])
            ->first();

        if (!$iface) {
            return response()->json(['success' => false, 'error' => 'Interface not found'], 404);
        }

        if (!Schema::hasTable('interface_traffic_stats')) {
            return response()->json([
                'success' => true,
                'meta' => $this->ifaceMeta($iface, $range),
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
        $inSum = 0.0;
        $outSum = 0.0;
        $inMax = null;
        $outMax = null;
        $inCount = 0;
        $outCount = 0;
        $inCur = null;
        $outCur = null;

        foreach ($rows as $row) {
            $inV = $row->in_rate_bps !== null ? (int) $row->in_rate_bps : null;
            $outV = $row->out_rate_bps !== null ? (int) $row->out_rate_bps : null;

            // For rollup sources the true peak is the bucket's stored max, not
            // the avg we plot; fall back to the value itself for raw rows.
            $inPeak = (isset($row->in_rate_max) && $row->in_rate_max !== null) ? (int) $row->in_rate_max : $inV;
            $outPeak = (isset($row->out_rate_max) && $row->out_rate_max !== null) ? (int) $row->out_rate_max : $outV;

            $data[] = [
                'created_at' => $row->created_at,
                'in_rate_bps' => $inV,
                'out_rate_bps' => $outV,
            ];

            if ($inV !== null) {
                $inSum += $inV;
                $inCount++;
                if ($inPeak !== null && ($inMax === null || $inPeak > $inMax)) $inMax = $inPeak;
                $inCur = $inV;
            }
            if ($outV !== null) {
                $outSum += $outV;
                $outCount++;
                if ($outPeak !== null && ($outMax === null || $outPeak > $outMax)) $outMax = $outPeak;
                $outCur = $outV;
            }
        }

        $summary = [
            'in_cur' => $inCur,
            'in_avg' => $inCount > 0 ? (int) ($inSum / $inCount) : null,
            'in_max' => $inMax,
            'out_cur' => $outCur,
            'out_avg' => $outCount > 0 ? (int) ($outSum / $outCount) : null,
            'out_max' => $outMax,
        ];

        return response()->json([
            'success' => true,
            'meta' => $this->ifaceMeta($iface, $range),
            'data' => $data,
            'summary' => $summary,
        ]);
    }

    private function ifaceMeta($iface, string $range): array
    {
        return [
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
    }

    private function emptySummary(): array
    {
        return [
            'in_cur' => null, 'in_avg' => null, 'in_max' => null,
            'out_cur' => null, 'out_avg' => null, 'out_max' => null,
        ];
    }

    private function dummyResponse(int $perPage, int $page, int $deviceId, string $status, string $q)
    {
        // Perangkat sama dengan /api/monitoring_devices (filter perangkat di halaman ini) dan
        // modal grafik trafik, supaya nama di tabel dan di modal tidak berbeda.
        $devices = $deviceId > 0 ? [$deviceId] : array_column(ViewerDummyData::devices(), 'id');
        $all = [];
        foreach ($devices as $dId) {
            foreach (ViewerDummyData::interfaces($dId) as $iface) {
                if (!($iface['is_sfp'] ?? 0)) {
                    continue;
                }
                $meta = ViewerDummyData::interfaceMeta($dId, (int) $iface['if_index']);
                $all[] = array_merge($iface, [
                    'device_id' => $dId,
                    'device_name' => $meta['device_name'],
                    'device_ip' => $meta['device_ip'],
                    'if_speed' => 1000000000,
                    'in_rate_bps' => random_int(1_000_000, 500_000_000),
                    'out_rate_bps' => random_int(1_000_000, 500_000_000),
                    'oper_status' => $iface['rx_power'] !== null && $iface['rx_power'] > -40 ? 1 : 2,
                ]);
            }
        }

        if ($status === 'up') {
            $all = array_values(array_filter($all, fn ($r) => ($r['oper_status'] ?? 0) === 1));
        } elseif ($status === 'down') {
            $all = array_values(array_filter($all, fn ($r) => ($r['oper_status'] ?? 0) !== 1));
        }

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $all = array_values(array_filter($all, function ($r) use ($needle) {
                $hay = mb_strtolower(($r['if_name'] ?? '') . ' ' . ($r['if_alias'] ?? '') . ' ' . ($r['if_description'] ?? ''));
                return str_contains($hay, $needle);
            }));
        }

        $total = count($all);
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        if ($page > $lastPage) {
            $page = $lastPage;
        }
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'success' => true,
            'data' => $slice,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
