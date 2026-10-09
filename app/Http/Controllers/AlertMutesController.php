<?php

namespace App\Http\Controllers;

use App\Support\ViewerDummyData;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance-window alert muting. A row in alert_mutes suppresses alerting for
 * a device (device_id = 0 means globally). muted_until = optional auto-expiry.
 */
class AlertMutesController extends Controller
{
    /** GET: global mute state + list of currently muted devices. */
    public function index(Request $request)
    {
        // Viewer (akun demo): status mute rekaan — catatan pemeliharaan produksi tidak dikirim.
        if (ViewerDummyData::isViewer($request)) {
            return response()->json(['success' => true] + ViewerDummyData::alertMutes());
        }

        $now = Carbon::now();

        $rows = DB::table('alert_mutes')
            ->leftJoin('snmp_devices', 'alert_mutes.device_id', '=', 'snmp_devices.id')
            ->select([
                'alert_mutes.device_id',
                'alert_mutes.note',
                'alert_mutes.muted_until',
                'snmp_devices.device_name',
            ])
            ->get();

        $global = ['muted' => false, 'muted_until' => null];
        $devices = [];
        foreach ($rows as $r) {
            $active = $r->muted_until === null || Carbon::parse($r->muted_until)->gt($now);
            if (!$active) {
                continue; // expired; treated as not muted
            }
            if ((int) $r->device_id === 0) {
                $global = ['muted' => true, 'muted_until' => $r->muted_until];
                continue;
            }
            $devices[] = [
                'device_id' => (int) $r->device_id,
                'device_name' => $r->device_name,
                'note' => $r->note,
                'muted_until' => $r->muted_until,
            ];
        }

        return response()->json(['success' => true, 'global' => $global, 'devices' => $devices]);
    }

    /** POST: set or clear a mute. {device_id, muted, minutes?, note?} */
    public function save(Request $request)
    {
        $body = $request->json()->all();
        $deviceId = (int) ($body['device_id'] ?? 0); // 0 = global
        $muted = (bool) ($body['muted'] ?? false);

        if ($deviceId > 0 && !DB::table('snmp_devices')->where('id', $deviceId)->exists()) {
            return response()->json(['success' => false, 'error' => 'Device not found'], 404);
        }

        if (!$muted) {
            DB::table('alert_mutes')->where('device_id', $deviceId)->delete();
            return response()->json(['success' => true, 'muted' => false]);
        }

        $mutedUntil = null;
        if (isset($body['minutes']) && is_numeric($body['minutes']) && (int) $body['minutes'] > 0) {
            $mutedUntil = Carbon::now()->addMinutes((int) $body['minutes'])->format('Y-m-d H:i:s');
        }
        $note = isset($body['note']) ? mb_substr(trim((string) $body['note']), 0, 190) : null;

        DB::table('alert_mutes')->updateOrInsert(
            ['device_id' => $deviceId],
            ['muted_until' => $mutedUntil, 'note' => $note, 'created_at' => now()]
        );

        return response()->json(['success' => true, 'muted' => true, 'muted_until' => $mutedUntil]);
    }

    /** DELETE: unmute a device (or global with device_id=0). */
    public function clear(Request $request)
    {
        $deviceId = (int) $request->input('device_id', 0);
        DB::table('alert_mutes')->where('device_id', $deviceId)->delete();
        return response()->json(['success' => true, 'muted' => false]);
    }
}
