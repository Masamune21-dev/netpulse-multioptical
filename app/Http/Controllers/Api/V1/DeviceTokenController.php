<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', 'string', 'max:50'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        $model = DeviceToken::query()->firstOrNew(['token' => $data['token']]);

        // Token milik pengguna lain hanya boleh pindah kalau pemilik lamanya sudah tidak
        // punya sesi API aktif (sudah logout di semua perangkat) — itulah kasus HP bersama
        // yang sah. Dulu firstOrNew() selalu menimpa user_id, sehingga siapa pun yang tahu
        // token FCM HP orang lain bisa membelokkan alert-nya ke akun sendiri.
        if ($model->exists && (int) $model->user_id !== (int) $user->id
            && $this->ownerStillSignedIn((int) $model->user_id)) {
            return response()->json(['error' => 'Token already registered'], 409);
        }

        // Viewer = akun demo: tidak menerima push alert produksi, jadi tokennya tidak disimpan.
        // APK mendaftarkan token otomatis setelah login → tetap dijawab sukses. Baris lama untuk
        // token HP ini (milik viewer sendiri, atau milik akun lain yang sudah logout di semua
        // perangkat — HP bersama) dilepas supaya HP yang kini dipakai akun demo berhenti
        // menerima alert akun sebelumnya.
        if ((string) ($user->role ?? '') === 'viewer') {
            if ($model->exists) {
                $model->delete();
            }

            return response()->json(['success' => true]);
        }

        $model->fill([
            'user_id' => $user->id,
            'platform' => $data['platform'] ?? $model->platform,
            'device_name' => $data['device_name'] ?? $model->device_name,
            'last_seen_at' => now(),
        ]);
        $model->save();

        return response()->json(['success' => true]);
    }

    private function ownerStillSignedIn(int $userId): bool
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User())->getMorphClass())
            ->where('tokenable_id', $userId)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
