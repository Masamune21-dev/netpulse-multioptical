<?php

namespace App\Http\Controllers;

use App\Support\UserState;
use App\Support\ViewerDummyData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tandai port "tidak dipakai" (interfaces.is_monitored = 0) atau pantau lagi.
 *
 * Port yang tidak dipakai tetap dibaca poller (status & RX di tabel interfaces
 * tetap segar, supaya UI bisa memberi tanda "aktif kembali"), tetapi tidak lagi
 * menghasilkan alert, kejadian SLA, sampel statistik, maupun masuk hitungan
 * dashboard dan laporan SLA. Mematikan pantauan menutup kejadian down yang masih
 * terbuka pada saat perubahan, supaya port itu tidak dihitung down selamanya.
 */
class InterfaceMonitoringController extends Controller
{
    private const MAX_ITEMS = 500;

    /** POST /api/interfaces/monitoring  {items:[{device_id,if_index}], monitored:bool, reason?:string} */
    public function update(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $body = $request->json()->all();
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (!array_key_exists('monitored', $body) || !is_bool($body['monitored'])) {
            return response()->json(['success' => false, 'error' => 'Field monitored (true/false) wajib diisi'], 422);
        }
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            return response()->json(['success' => false, 'error' => 'Pilih 1–' . self::MAX_ITEMS . ' port'], 422);
        }

        $monitored = $body['monitored'];
        $reason = trim((string) ($body['reason'] ?? ''));
        if (mb_strlen($reason) > 255) {
            return response()->json(['success' => false, 'error' => 'Alasan maksimal 255 karakter'], 422);
        }

        $ports = [];
        foreach ($items as $it) {
            $d = (int) ($it['device_id'] ?? 0);
            $i = (int) ($it['if_index'] ?? 0);
            if ($d <= 0 || $i <= 0) {
                return response()->json(['success' => false, 'error' => 'device_id / if_index tidak sah'], 422);
            }
            $ports["{$d}:{$i}"] = [$d, $i];
        }

        $user = (array) $request->session()->get('auth.user', []);
        $by = mb_substr((string) ($user['username'] ?? ''), 0, 100);
        $now = now();
        $changed = 0;
        $closed = 0;

        DB::transaction(function () use ($ports, $monitored, $reason, $by, $now, &$changed, &$closed) {
            foreach ($ports as [$deviceId, $ifIndex]) {
                $row = DB::table('interfaces')->where('device_id', $deviceId)->where('if_index', $ifIndex)->first(['is_monitored']);
                if (!$row) {
                    continue;
                }
                if ((int) ($row->is_monitored ?? 1) === ($monitored ? 1 : 0)) {
                    continue; // sudah dalam status itu
                }

                DB::table('interfaces')->where('device_id', $deviceId)->where('if_index', $ifIndex)
                    ->update(['is_monitored' => $monitored ? 1 : 0]);

                DB::table('interface_monitoring_changes')->insert([
                    'device_id' => $deviceId,
                    'if_index' => $ifIndex,
                    'is_monitored' => $monitored,
                    'reason' => $reason !== '' ? $reason : null,
                    'changed_by' => $by !== '' ? $by : null,
                    'created_at' => $now,
                ]);
                $changed++;

                if (!$monitored && Schema::hasTable('interface_down_events')) {
                    $open = DB::table('interface_down_events')
                        ->where('device_id', $deviceId)->where('if_index', $ifIndex)
                        ->whereNull('up_at')->get(['id', 'down_at']);
                    foreach ($open as $e) {
                        DB::table('interface_down_events')->where('id', $e->id)->update([
                            'up_at' => $now,
                            'duration_sec' => max(0, $now->getTimestamp() - strtotime((string) $e->down_at)),
                        ]);
                        $closed++;
                    }
                }
            }
        });

        return response()->json([
            'success' => true,
            'monitored' => $monitored,
            'changed' => $changed,
            'closed_events' => $closed,
        ]);
    }

    /** GET /api/interfaces/monitoring/history?device_id=&if_index= */
    public function history(Request $request): JsonResponse
    {
        $deviceId = (int) $request->query('device_id', 0);
        $ifIndex = (int) $request->query('if_index', 0);
        if ($deviceId <= 0 || $ifIndex <= 0) {
            return response()->json(['success' => false, 'error' => 'Missing device_id or if_index'], 400);
        }

        // Viewer (akun demo): semua port dummy dipantau, jadi riwayatnya kosong. Dulu riwayat
        // produksi (alasan + username admin di changed_by) ikut terkirim.
        if (ViewerDummyData::isViewer($request)) {
            return response()->json(['success' => true, 'history' => []]);
        }

        $rows = DB::table('interface_monitoring_changes')
            ->where('device_id', $deviceId)->where('if_index', $ifIndex)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)
            ->get(['is_monitored', 'reason', 'changed_by', 'created_at']);

        return response()->json(['success' => true, 'history' => $rows]);
    }

    /**
     * Alasan & waktu perubahan terakhir untuk port yang tidak dipakai, dipakai
     * oleh endpoint daftar interface: "device:ifIndex" => [reason, changed_by, at].
     *
     * @param  array<int,array{0:int,1:int}>  $ports
     * @return array<string,array{reason:?string,changed_by:?string,changed_at:?string}>
     */
    public static function latestReasons(array $ports): array
    {
        if ($ports === [] || !Schema::hasTable('interface_monitoring_changes')) {
            return [];
        }
        $deviceIds = array_values(array_unique(array_map(fn ($p) => $p[0], $ports)));
        $wanted = [];
        foreach ($ports as [$d, $i]) {
            $wanted["{$d}:{$i}"] = true;
        }

        $out = [];
        $rows = DB::table('interface_monitoring_changes')
            ->whereIn('device_id', $deviceIds)
            ->orderBy('created_at')->orderBy('id')
            ->get(['device_id', 'if_index', 'reason', 'changed_by', 'created_at']);
        foreach ($rows as $r) {
            $k = $r->device_id . ':' . $r->if_index;
            if (isset($wanted[$k])) {
                $out[$k] = ['reason' => $r->reason, 'changed_by' => $r->changed_by, 'changed_at' => (string) $r->created_at];
            }
        }

        return $out;
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $user = (array) $request->session()->get('auth.user', []);
        $state = UserState::fresh((int) ($user['id'] ?? 0));
        $role = $state['role'] ?? ($user['role'] ?? null);

        return $role === 'admin' ? null : response()->json(['success' => false, 'error' => 'Khusus admin'], 403);
    }
}
