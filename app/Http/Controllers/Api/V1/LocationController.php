<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LocationController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        // Viewer = akun demo: lokasi HP tidak dicatat (APK tetap menerima sukses).
        if ((string) ($user->role ?? '') === 'viewer') {
            return response()->json(['success' => true]);
        }

        $recordedAt = null;
        if (!empty($data['recorded_at'])) {
            $recordedAt = Carbon::parse($data['recorded_at']);
        }

        UserLocation::query()->create([
            'user_id' => $user->id,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'accuracy' => $data['accuracy'] ?? null,
            'recorded_at' => $recordedAt ?? now(),
        ]);

        return response()->json(['success' => true]);
    }
}

