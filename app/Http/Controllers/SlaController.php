<?php

namespace App\Http\Controllers;

use App\Support\ViewerDummyData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SLA / uptime report from interface_down_events: which interfaces went down,
 * how often over a period, total downtime and availability, plus a per-event
 * drilldown (each down's start/end/duration).
 *
 * Viewer (akun demo) mendapat kejadian dummy dari ViewerDummyData di semua endpoint,
 * termasuk ekspor CSV/PDF — perhitungan ringkasan/ketersediaan tetap kode yang sama.
 */
class SlaController extends Controller
{
    public function index()
    {
        return view('sla.index');
    }

    /** GET /api/sla — per-interface summary over the last N days. */
    public function summary(Request $request)
    {
        $days = $this->days($request);
        $deviceId = (int) $request->query('device_id', 0);
        $q = trim((string) $request->query('q', ''));

        $rows = ViewerDummyData::isViewer($request)
            ? ViewerDummyData::slaSummaryRows($days, $deviceId, $q)
            : $this->summaryRows($request, $days, $deviceId, $q);

        $windowSec = $days * 86400;
        $data = array_map(function ($r) use ($windowSec) {
            $downSec = (int) $r->down_sec;
            $avail = $windowSec > 0 ? max(0, min(100, (1 - $downSec / $windowSec) * 100)) : null;
            return [
                'device_id' => (int) $r->device_id,
                'if_index' => (int) $r->if_index,
                'device_name' => $r->device_name,
                'if_name' => $r->if_name,
                'if_alias' => $r->if_alias,
                'down_count' => (int) $r->down_count,
                'down_sec' => $downSec,
                'availability' => $avail !== null ? round($avail, 3) : null,
                'still_down' => (int) $r->still_down === 1,
                'last_down_at' => $r->last_down_at,
            ];
        }, $rows);

        return response()->json([
            'success' => true,
            'days' => $days,
            'window_sec' => $windowSec,
            'interfaces' => $data,
            'totals' => [
                'interfaces' => count($data),
                'down_events' => array_sum(array_column($data, 'down_count')),
                'down_sec' => array_sum(array_column($data, 'down_sec')),
            ],
        ]);
    }

    /** Per-interface aggregate rows from interface_down_events (MySQL). */
    private function summaryRows(Request $request, int $days, int $deviceId, string $q): array
    {
        $bindings = [];
        $where = ["COALESCE(up_at, NOW()) >= NOW() - INTERVAL {$days} DAY"];
        if ($deviceId > 0) {
            $where[] = 'device_id = ?';
            $bindings[] = $deviceId;
        }
        if ($q !== '') {
            $where[] = '(device_name LIKE ? OR if_name LIKE ? OR if_alias LIKE ?)';
            $like = '%' . $q . '%';
            array_push($bindings, $like, $like, $like);
        }
        // Port yang ditandai tidak dipakai keluar dari laporan (dan ekspornya), kecuali
        // diminta eksplisit. Riwayat per-interface tetap bisa dibuka lewat events().
        if (!$request->boolean('include_unmonitored')) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM interfaces i WHERE i.device_id = interface_down_events.device_id AND i.if_index = interface_down_events.if_index AND i.is_monitored = 0)';
        }
        $whereSql = implode(' AND ', $where);

        return DB::select("
            SELECT device_id, if_index,
                   MAX(device_name) AS device_name,
                   MAX(if_name) AS if_name,
                   MAX(if_alias) AS if_alias,
                   SUM(CASE WHEN down_at >= NOW() - INTERVAL {$days} DAY THEN 1 ELSE 0 END) AS down_count,
                   SUM(GREATEST(0, TIMESTAMPDIFF(SECOND,
                        GREATEST(down_at, NOW() - INTERVAL {$days} DAY),
                        COALESCE(up_at, NOW())))) AS down_sec,
                   MAX(CASE WHEN up_at IS NULL THEN 1 ELSE 0 END) AS still_down,
                   MAX(down_at) AS last_down_at
            FROM interface_down_events
            WHERE {$whereSql}
            GROUP BY device_id, if_index
            HAVING down_count > 0
            ORDER BY down_count DESC, down_sec DESC
        ", $bindings);
    }

    /** GET /api/sla/export — CSV of the per-interface SLA summary. */
    public function export(Request $request)
    {
        $days = $this->days($request);
        $json = $this->summary($request)->getData(true);
        $rows = $json['interfaces'] ?? [];

        $filename = 'sla_report_' . $days . 'd_' . date('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, ['Device', 'Interface', 'Alias', 'Down count', 'Total downtime', 'Downtime (sec)', 'Availability %', 'Status', 'Last down']);
            foreach ($rows as $r) {
                $this->putCsv($out, [
                    $r['device_name'],
                    $r['if_name'],
                    $r['if_alias'],
                    $r['down_count'],
                    $this->humanDuration((int) $r['down_sec']),
                    $r['down_sec'],
                    $r['availability'] !== null ? number_format($r['availability'], 3) : '',
                    $r['still_down'] ? 'DOWN' : 'up',
                    $r['last_down_at'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** GET /api/sla/export-pdf — branded PDF of the per-interface SLA summary. */
    public function exportPdf(Request $request)
    {
        $days = $this->days($request);
        $json = $this->summary($request)->getData(true);

        $rows = array_map(function ($r) {
            $avail = $r['availability'];
            $r['downtime_h'] = $this->humanDuration((int) $r['down_sec']);
            $r['avail_str'] = $avail !== null ? number_format($avail, 3) . '%' : '—';
            $r['avail_class'] = $avail === null ? '' : ($avail >= 99.9 ? 'ok' : ($avail >= 99 ? 'warn' : 'bad'));
            return $r;
        }, $json['interfaces'] ?? []);

        $deviceId = (int) $request->query('device_id', 0);
        $scope = 'All devices';
        if ($deviceId > 0) {
            $name = ViewerDummyData::isViewer($request)
                ? (ViewerDummyData::device($deviceId)['device_name'] ?? null)
                : DB::table('snmp_devices')->where('id', $deviceId)->value('device_name');
            $scope = (string) ($name ?? ('Device #' . $deviceId));
        }

        $pdf = Pdf::loadView('sla.pdf', [
            'days' => $days,
            'rows' => $rows,
            'totals' => $json['totals'] ?? [],
            'totalDowntime' => $this->humanDuration((int) ($json['totals']['down_sec'] ?? 0)),
            'generatedAt' => date('Y-m-d H:i'),
            'scope' => $scope,
            'search' => trim((string) $request->query('q', '')),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('sla_report_' . $days . 'd_' . date('Ymd_His') . '.pdf');
    }

    private function humanDuration(int $sec): string
    {
        if ($sec < 60) {
            return $sec . 's';
        }
        $d = intdiv($sec, 86400);
        $h = intdiv($sec % 86400, 3600);
        $m = intdiv($sec % 3600, 60);
        $parts = [];
        if ($d) {
            $parts[] = $d . 'd';
        }
        if ($h) {
            $parts[] = $h . 'h';
        }
        if ($m) {
            $parts[] = $m . 'm';
        }
        return $parts ? implode(' ', $parts) : '0m';
    }

    /** Port yang down terus-menerus lebih lama dari ini diusulkan untuk ditandai tidak dipakai. */
    public const UNUSED_CANDIDATE_DAYS = 7;

    /**
     * GET /api/sla/candidates — port yang masih dipantau tetapi down tanpa henti lebih dari
     * UNUSED_CANDIDATE_DAYS hari (kejadian terbuka yang sudah lama). Kandidat "tidak dipakai".
     */
    public function candidates(Request $request)
    {
        $minDays = self::UNUSED_CANDIDATE_DAYS;
        if (ViewerDummyData::isViewer($request)) {
            return response()->json(['success' => true, 'min_days' => $minDays, 'candidates' => ViewerDummyData::slaCandidates($minDays)]);
        }

        $cutoff = now()->subDays($minDays);

        $rows = DB::table('interface_down_events as e')
            ->join('interfaces as i', function ($j) {
                $j->on('i.device_id', '=', 'e.device_id')->on('i.if_index', '=', 'e.if_index');
            })
            ->leftJoin('snmp_devices as d', 'd.id', '=', 'e.device_id')
            ->whereNull('e.up_at')
            ->where('e.down_at', '<=', $cutoff)
            ->where(function ($q) {
                $q->whereNull('i.is_monitored')->orWhere('i.is_monitored', 1);
            })
            ->orderBy('e.down_at')
            ->get([
                'e.device_id', 'e.if_index', 'e.down_at',
                'i.if_name', 'i.if_alias', 'i.oper_status', 'i.rx_power', 'i.last_seen',
                'd.device_name',
            ]);

        $now = time();
        $data = $rows->map(fn ($r) => [
            'device_id' => (int) $r->device_id,
            'if_index' => (int) $r->if_index,
            'device_name' => $r->device_name ?? ('Device #' . $r->device_id),
            'if_name' => $r->if_name,
            'if_alias' => $r->if_alias,
            'oper_status' => $r->oper_status !== null ? (int) $r->oper_status : null,
            'rx_power' => is_numeric($r->rx_power) ? (float) $r->rx_power : null,
            'down_at' => (string) $r->down_at,
            'down_days' => (int) floor(($now - strtotime((string) $r->down_at)) / 86400),
        ])->values();

        return response()->json(['success' => true, 'min_days' => $minDays, 'candidates' => $data]);
    }

    /** GET /api/sla/events — each down event for one interface over the period. */
    public function events(Request $request)
    {
        $days = $this->days($request);
        $deviceId = (int) $request->query('device_id', 0);
        $ifIndex = (int) $request->query('if_index', 0);
        if ($deviceId <= 0 || $ifIndex <= 0) {
            return response()->json(['success' => false, 'error' => 'Missing device_id or if_index'], 400);
        }

        $events = ViewerDummyData::isViewer($request)
            ? ViewerDummyData::slaInterfaceEvents($deviceId, $ifIndex, $days)
            : $this->eventRows($deviceId, $ifIndex, $days);

        return response()->json(['success' => true, 'days' => $days, 'events' => $events]);
    }

    /** Down events of one interface touching the last $days days, newest first (MySQL). */
    private function eventRows(int $deviceId, int $ifIndex, int $days): array
    {
        $rows = DB::select("
            SELECT down_at, up_at,
                   COALESCE(duration_sec, TIMESTAMPDIFF(SECOND, down_at, NOW())) AS duration_sec
            FROM interface_down_events
            WHERE device_id = ? AND if_index = ?
              AND COALESCE(up_at, NOW()) >= NOW() - INTERVAL {$days} DAY
            ORDER BY down_at DESC
        ", [$deviceId, $ifIndex]);

        return array_map(fn ($r) => [
            'down_at' => $r->down_at,
            'up_at' => $r->up_at,
            'duration_sec' => (int) $r->duration_sec,
            'ongoing' => $r->up_at === null,
        ], $rows);
    }

    /** GET /api/sla/interface/export — CSV of every down event for one interface. */
    public function exportInterface(Request $request)
    {
        [$ok, $deviceId, $ifIndex, $days, $data] = $this->interfacePayload($request);
        if (!$ok) {
            return response()->json(['success' => false, 'error' => 'Missing device_id or if_index'], 400);
        }

        $label = $this->slugLabel($data['meta']);
        $filename = "sla_{$label}_{$days}d_" . date('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            $m = $data['meta'];
            $this->putCsv($out, ['Device', $m['device_name']]);
            $this->putCsv($out, ['Interface', $m['if_name']]);
            $this->putCsv($out, ['Alias', $m['if_alias']]);
            $this->putCsv($out, ['Down count', $data['summary']['down_count']]);
            $this->putCsv($out, ['Total downtime', $this->humanDuration($data['summary']['down_sec'])]);
            $this->putCsv($out, ['Availability %', $data['summary']['availability']]);
            $this->putCsv($out, []);
            $this->putCsv($out, ['#', 'Down at', 'Up at', 'Duration', 'Duration (sec)']);
            foreach ($data['events'] as $i => $e) {
                $this->putCsv($out, [
                    $i + 1,
                    $e['down_at'],
                    $e['ongoing'] ? 'still down' : $e['up_at'],
                    $this->humanDuration($e['duration_sec']),
                    $e['duration_sec'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** GET /api/sla/interface/export-pdf — branded PDF of one interface's downs. */
    public function exportInterfacePdf(Request $request)
    {
        [$ok, $deviceId, $ifIndex, $days, $data] = $this->interfacePayload($request);
        if (!$ok) {
            return response()->json(['success' => false, 'error' => 'Missing device_id or if_index'], 400);
        }

        $events = array_map(function ($e) {
            $e['duration_h'] = $this->humanDuration($e['duration_sec']);
            return $e;
        }, $data['events']);

        $avail = $data['summary']['availability'];
        $pdf = Pdf::loadView('sla.pdf_interface', [
            'days' => $days,
            'meta' => $data['meta'],
            'events' => $events,
            'summary' => $data['summary'],
            'totalDowntime' => $this->humanDuration($data['summary']['down_sec']),
            'longest' => $this->humanDuration($data['summary']['longest_sec']),
            'availClass' => $avail === null ? '' : ($avail >= 99.9 ? 'ok' : ($avail >= 99 ? 'warn' : 'bad')),
            'generatedAt' => date('Y-m-d H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("sla_{$this->slugLabel($data['meta'])}_{$days}d_" . date('Ymd_His') . '.pdf');
    }

    /**
     * Resolve [ok, deviceId, ifIndex, days, data] for a single interface, where
     * data = ['meta'=>..., 'events'=>[...], 'summary'=>...].
     */
    private function interfacePayload(Request $request): array
    {
        $days = $this->days($request);
        $deviceId = (int) $request->query('device_id', 0);
        $ifIndex = (int) $request->query('if_index', 0);
        if ($deviceId <= 0 || $ifIndex <= 0) {
            return [false, 0, 0, $days, []];
        }

        if (ViewerDummyData::isViewer($request)) {
            $events = ViewerDummyData::slaInterfaceEvents($deviceId, $ifIndex, $days);
            $meta = (object) ViewerDummyData::interfaceMeta($deviceId, $ifIndex);
        } else {
            $events = $this->eventRows($deviceId, $ifIndex, $days);

            // Meta: prefer the latest event snapshot, else the live interface row.
            $meta = DB::table('interface_down_events')
                ->where('device_id', $deviceId)->where('if_index', $ifIndex)
                ->orderByDesc('down_at')
                ->first(['device_name', 'if_name', 'if_alias']);
            if (!$meta) {
                $meta = DB::table('interfaces')
                    ->leftJoin('snmp_devices', 'interfaces.device_id', '=', 'snmp_devices.id')
                    ->where('interfaces.device_id', $deviceId)->where('interfaces.if_index', $ifIndex)
                    ->first(['snmp_devices.device_name', 'interfaces.if_name', 'interfaces.if_alias']);
            }
        }
        $metaArr = [
            'device_name' => $meta->device_name ?? ('Device #' . $deviceId),
            'if_name' => $meta->if_name ?? ('ifIndex ' . $ifIndex),
            'if_alias' => $meta->if_alias ?? '',
        ];

        $windowStart = strtotime('-' . $days . ' days');
        $now = time();
        $downSec = 0;
        $longest = 0;
        $downCount = 0;
        foreach ($events as $e) {
            $start = strtotime((string) $e['down_at']);
            $end = $e['up_at'] !== null ? strtotime((string) $e['up_at']) : $now;
            $clip = max(0, min($end, $now) - max($start, $windowStart));
            $downSec += $clip;
            $longest = max($longest, $e['duration_sec']);
            if ($start >= $windowStart) {
                $downCount++;
            }
        }
        $windowSec = $days * 86400;
        $avail = $windowSec > 0 ? max(0, min(100, (1 - $downSec / $windowSec) * 100)) : null;

        return [true, $deviceId, $ifIndex, $days, [
            'meta' => $metaArr,
            'events' => $events,
            'summary' => [
                'down_count' => $downCount,
                'down_sec' => $downSec,
                'longest_sec' => $longest,
                'availability' => $avail !== null ? round($avail, 3) : null,
                'still_down' => !empty($events) && $events[0]['ongoing'],
            ],
        ]];
    }

    private function slugLabel(array $meta): string
    {
        $base = $meta['if_alias'] ?: $meta['if_name'];
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $base);
        return trim((string) $slug, '-') ?: 'interface';
    }

    private function days(Request $request): int
    {
        $d = (int) $request->query('days', 30);
        return max(1, min(365, $d));
    }

    /**
     * fputcsv dengan pengaman injeksi formula. Nama perangkat dan ifAlias berasal dari
     * perangkat/pengguna; sel yang diawali = + - @ tab atau CR dieksekusi sebagai formula
     * saat CSV dibuka di Excel/LibreOffice. Sel teks seperti itu diawali tanda kutip tunggal.
     * Nilai numerik (int/float) tidak disentuh.
     */
    private function putCsv($out, array $row): void
    {
        $safe = array_map(static function ($v) {
            if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'" . $v;
            }

            return $v;
        }, $row);

        fputcsv($out, $safe);
    }
}
