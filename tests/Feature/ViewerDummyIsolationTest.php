<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\FcmService;
use App\Services\InterfaceDiscovery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Viewer = akun demo: tidak boleh melihat data produksi (audit 9 Okt 2026). Setiap endpoint
 * yang dulu bocor diuji dengan fixture "produksi" (nama/alias penanda di bawah) lalu
 * dipastikan viewer hanya mendapat data dummy, sedangkan admin/teknisi tetap data asli.
 *
 * Catatan cakupan: ringkasan & kejadian SLA serta grafik trafik jalur admin memakai SQL khusus
 * MySQL (INTERVAL, TIMESTAMPDIFF, GREATEST) yang tidak ada di sqlite — pembanding admin
 * untuk SLA memakai kandidat (query builder); jalur viewer sepenuhnya tanpa SQL itu.
 */
class ViewerDummyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_DEVICE = 'SW-PRODUKSI-UJI';
    private const REAL_IP = '192.0.2.23';
    private const REAL_ALIAS = 'MITRA-RAHASIA';
    private const DUMMY_DEVICES = ['RTR-CORE-DEMO', 'SW-AGG-DEMO', 'OLT-HIOSO-DEMO', 'EDGE-POP-DEMO'];

    /** @var array<string,User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable();
            $table->string('full_name')->nullable();
            $table->string('role')->default('viewer');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('snmp_devices', function (Blueprint $t) {
            $t->id();
            $t->string('device_name')->nullable();
            $t->string('ip_address')->nullable();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('interfaces', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_id');
            $t->integer('if_index');
            $t->string('if_name')->nullable();
            $t->string('if_alias')->nullable();
            $t->string('if_description')->nullable();
            $t->string('interface_type')->nullable();
            $t->boolean('is_sfp')->default(1);
            $t->boolean('is_monitored')->nullable()->default(1);
            $t->integer('oper_status')->nullable();
            $t->decimal('rx_power', 8, 3)->nullable();
            $t->decimal('tx_power', 8, 3)->nullable();
            $t->bigInteger('if_speed')->nullable();
            $t->bigInteger('in_rate_bps')->nullable();
            $t->bigInteger('out_rate_bps')->nullable();
            $t->timestamp('last_seen')->nullable();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('name')->primary();
            $t->text('value')->nullable();
        });

        // "Produksi": id 23 seperti rentang id perangkat asli (23–50).
        DB::table('snmp_devices')->insert(['id' => 23, 'device_name' => self::REAL_DEVICE, 'ip_address' => self::REAL_IP]);
        DB::table('interfaces')->insert([
            'device_id' => 23, 'if_index' => 5, 'if_name' => 'sfp5', 'if_alias' => self::REAL_ALIAS,
            'is_sfp' => 1, 'is_monitored' => 1, 'oper_status' => 2, 'rx_power' => -40.0, 'if_speed' => 1_000_000_000,
        ]);
        DB::table('interface_down_events')->insert([
            'device_id' => 23, 'if_index' => 5, 'if_name' => 'sfp5', 'if_alias' => self::REAL_ALIAS,
            'device_name' => self::REAL_DEVICE, 'down_at' => now()->subDays(10)->toDateTimeString(),
            'up_at' => null, 'duration_sec' => null, 'created_at' => now()->subDays(10),
        ]);
        DB::table('interface_thresholds')->insert([
            'device_id' => 23, 'if_index' => 5, 'rx_warn_low' => -30.0, 'updated_at' => now(),
        ]);
        DB::table('interface_monitoring_changes')->insert([
            'device_id' => 23, 'if_index' => 5, 'is_monitored' => true, 'reason' => 'alasan-rahasia',
            'changed_by' => 'admin-rahasia', 'created_at' => now(),
        ]);
        DB::table('settings')->insert([
            ['name' => 'alert_rx_warning_high', 'value' => '-19'],
            ['name' => 'alert_rx_warning_low', 'value' => '-27'],
            ['name' => 'alert_rx_down_threshold', 'value' => '-40'],
        ]);
    }

    private function user(string $role, bool $active = true): User
    {
        $key = $role . ($active ? '' : '-off');
        if (!isset($this->users[$key])) {
            $id = DB::table('users')->insertGetId([
                'name' => $key, 'email' => $key . '@example.test', 'username' => $key . '-uji',
                'full_name' => 'Uji', 'password' => Hash::make('kata-sandi-uji-123'), 'role' => $role,
                'is_active' => $active, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->users[$key] = User::query()->findOrFail($id);
        }

        return $this->users[$key];
    }

    private function as(string $role): static
    {
        $u = $this->user($role);

        return $this->withSession(['auth.logged_in' => true, 'auth.user' => [
            'id' => $u->id, 'username' => $u->username, 'full_name' => 'Uji', 'role' => $role,
        ]]);
    }

    private function bearer(string $role): array
    {
        return ['Authorization' => 'Bearer ' . $this->user($role)->createToken('uji')->plainTextToken];
    }

    private function assertNoProductionData(string $content): void
    {
        foreach ([self::REAL_DEVICE, self::REAL_IP, self::REAL_ALIAS, 'admin-rahasia', 'alasan-rahasia', 'catatan-rahasia'] as $marker) {
            $this->assertStringNotContainsString($marker, $content);
        }
    }

    // --- 1. Laporan SLA ----------------------------------------------------------

    public function test_viewer_ringkasan_sla_berisi_kejadian_dummy(): void
    {
        $res = $this->as('viewer')->getJson('/api/sla?days=30')->assertOk()->assertJsonPath('success', true);
        $this->assertNoProductionData($res->getContent());

        $rows = $res->json('interfaces');
        $this->assertCount(6, $rows);
        $this->assertSame(10, $res->json('totals.down_events'));
        $this->assertEmpty(array_diff(array_column($rows, 'device_name'), self::DUMMY_DEVICES));
        // Urutan sama dengan query asli: down_count terbanyak di atas.
        $this->assertSame([102, 2, 3], [$rows[0]['device_id'], $rows[0]['if_index'], $rows[0]['down_count']]);
        $edge = collect($rows)->first(fn ($r) => $r['device_id'] === 104 && $r['if_index'] === 1);
        $this->assertTrue($edge['still_down']);

        // Jendela 7 hari: port yang down sejak 9 hari tidak dihitung sebagai kejadian baru.
        $week = $this->as('viewer')->getJson('/api/sla?days=7')->assertOk();
        $this->assertSame(4, $week->json('totals.interfaces'));
        $this->assertSame(5, $week->json('totals.down_events'));

        // Filter perangkat & pencarian bekerja di atas data dummy; id produksi tidak menghasilkan apa pun.
        $this->assertCount(2, $this->as('viewer')->getJson('/api/sla?days=30&device_id=104')->json('interfaces'));
        $this->assertEqualsCanonicalizing([102, 103, 104], array_column(
            $this->as('viewer')->getJson('/api/sla?days=30&q=uplink-2')->json('interfaces'), 'device_id'
        ));
        $this->as('viewer')->getJson('/api/sla?days=30&device_id=23')->assertOk()->assertJsonPath('interfaces', []);
    }

    public function test_viewer_kejadian_dan_kandidat_sla_dummy(): void
    {
        $events = $this->as('viewer')->getJson('/api/sla/events?days=30&device_id=102&if_index=2')->assertOk()->json('events');
        $this->assertCount(3, $events);
        $this->assertGreaterThan($events[1]['down_at'], $events[0]['down_at']); // terbaru dulu

        // Kejadian produksi untuk id asli tidak ikut.
        $this->as('viewer')->getJson('/api/sla/events?days=30&device_id=23&if_index=5')
            ->assertOk()->assertJsonPath('events', []);

        $cand = $this->as('viewer')->getJson('/api/sla/candidates')->assertOk();
        $this->assertNoProductionData($cand->getContent());
        $this->assertSame([[104, 1, 'EDGE-POP-DEMO']], array_map(
            fn ($c) => [$c['device_id'], $c['if_index'], $c['device_name']], $cand->json('candidates')
        ));
        $this->assertGreaterThanOrEqual(9, $cand->json('candidates.0.down_days'));
    }

    public function test_admin_dan_teknisi_tetap_melihat_kandidat_sla_asli(): void
    {
        foreach (['admin', 'technician'] as $role) {
            $res = $this->as($role)->getJson('/api/sla/candidates')->assertOk();
            $this->assertSame([[23, 5, self::REAL_DEVICE]], array_map(
                fn ($c) => [$c['device_id'], $c['if_index'], $c['device_name']], $res->json('candidates')
            ));
        }
    }

    public function test_viewer_ekspor_csv_sla_dari_data_dummy(): void
    {
        $csv = $this->as('viewer')->get('/api/sla/export?days=30')->assertOk()->streamedContent();
        $this->assertNoProductionData($csv);
        $this->assertStringContainsString('SW-AGG-DEMO', $csv);
        $this->assertSame(1 + 6, count(array_filter(explode("\n", trim($csv)))));

        // Per-interface dengan id produksi: nama rekaan, tanpa kejadian asli.
        $one = $this->as('viewer')->get('/api/sla/interface/export?days=30&device_id=23&if_index=5')->assertOk()->streamedContent();
        $this->assertNoProductionData($one);
        $this->assertStringContainsString('Demo Device 23', $one);
        $this->assertStringContainsString('"Down count",0', $one);

        $dummy = $this->as('viewer')->get('/api/sla/interface/export?days=30&device_id=102&if_index=2')->assertOk()->streamedContent();
        $this->assertStringContainsString('SW-AGG-DEMO', $dummy);
        $this->assertStringContainsString('"Down count",3', $dummy);
    }

    public function test_viewer_ekspor_pdf_sla_dari_data_dummy(): void
    {
        $captured = [];
        View::composer(['sla.pdf', 'sla.pdf_interface'], function ($view) use (&$captured) {
            $captured[$view->name()] = $view->getData();
        });

        $this->as('viewer')->get('/api/sla/export-pdf?days=30&device_id=23')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame('Device #23', $captured['sla.pdf']['scope']);
        $this->assertSame([], $captured['sla.pdf']['rows']);

        $this->as('viewer')->get('/api/sla/export-pdf?days=30&device_id=101')->assertOk();
        $this->assertSame('RTR-CORE-DEMO', $captured['sla.pdf']['scope']);
        $this->assertSame(['RTR-CORE-DEMO'], array_values(array_unique(array_column($captured['sla.pdf']['rows'], 'device_name'))));

        $this->as('viewer')->get('/api/sla/interface/export-pdf?days=30&device_id=23&if_index=5')->assertOk();
        $this->assertSame('Demo Device 23', $captured['sla.pdf_interface']['meta']['device_name']);
        $this->assertSame([], $captured['sla.pdf_interface']['events']);
    }

    // --- 2. Grafik trafik interface ---------------------------------------------------

    public function test_viewer_traffic_history_tidak_membaca_interface_produksi(): void
    {
        $res = $this->as('viewer')->getJson('/api/interfaces/traffic_history?device_id=23&if_index=5&range=1d')->assertOk();
        $this->assertNoProductionData($res->getContent());
        $res->assertJsonPath('meta.device_name', 'Demo Device 23');

        $demo = $this->as('viewer')->getJson('/api/interfaces/traffic_history?device_id=101&if_index=1&range=7d')->assertOk();
        $demo->assertJsonPath('meta.device_name', 'RTR-CORE-DEMO')->assertJsonPath('meta.if_alias', 'Uplink-1')
            ->assertJsonPath('meta.range', '7d');
        $this->assertNotEmpty($demo->json('data'));
        $this->assertNotNull($demo->json('summary.in_avg'));

        // Jalur "tabel statistik belum ada" dulu juga mengembalikan meta asli sebelum cek viewer.
        Schema::drop('interface_traffic_stats');
        $this->assertNoProductionData(
            $this->as('viewer')->getJson('/api/interfaces/traffic_history?device_id=23&if_index=5')->assertOk()->getContent()
        );

        // Pembanding: admin tetap mendapat meta interface asli.
        $this->as('admin')->getJson('/api/interfaces/traffic_history?device_id=23&if_index=5')
            ->assertOk()
            ->assertJsonPath('meta.device_name', self::REAL_DEVICE)
            ->assertJsonPath('meta.if_alias', self::REAL_ALIAS);
    }

    public function test_viewer_daftar_interface_memakai_perangkat_dummy_yang_sama(): void
    {
        $res = $this->as('viewer')->getJson('/api/interfaces/all?per_page=50')->assertOk();
        $this->assertNoProductionData($res->getContent());
        $this->assertEqualsCanonicalizing(self::DUMMY_DEVICES, array_values(array_unique(array_column($res->json('data'), 'device_name'))));
    }

    // --- 3. GET lain tanpa cabang viewer ------------------------------------------------

    public function test_viewer_ambang_rx_interface_dummy_admin_tetap_asli(): void
    {
        $res = $this->as('viewer')->getJson('/api/interfaces/thresholds?device_id=23&if_index=5')->assertOk();
        $this->assertNoProductionData($res->getContent());
        $res->assertJsonPath('meta.device_name', 'Demo Device 23')
            ->assertJsonPath('global.rx_warn_high', -18)      // dummy, bukan -19 produksi
            ->assertJsonPath('override.rx_warn_low', null)    // override produksi -30 tidak ikut
            ->assertJsonPath('suggestion.available', false);

        $this->as('viewer')->getJson('/api/interfaces/thresholds?device_id=101&if_index=1')
            ->assertOk()->assertJsonPath('meta.device_name', 'RTR-CORE-DEMO');

        // Saran baseline admin memakai SQL MySQL; tanpa tabel harian jalur itu dilewati.
        Schema::drop('interface_stats_daily');
        $this->as('admin')->getJson('/api/interfaces/thresholds?device_id=23&if_index=5')
            ->assertOk()
            ->assertJsonPath('meta.device_name', self::REAL_DEVICE)
            ->assertJsonPath('global.rx_warn_high', -19)
            ->assertJsonPath('override.rx_warn_low', -30);
    }

    public function test_viewer_riwayat_status_pantau_kosong_teknisi_tetap_asli(): void
    {
        $this->as('viewer')->getJson('/api/interfaces/monitoring/history?device_id=23&if_index=5')
            ->assertOk()->assertJsonPath('history', []);

        $this->as('technician')->getJson('/api/interfaces/monitoring/history?device_id=23&if_index=5')
            ->assertOk()->assertJsonPath('history.0.changed_by', 'admin-rahasia');
    }

    public function test_viewer_status_mute_dummy_admin_tetap_asli(): void
    {
        DB::table('alert_mutes')->insert([
            ['device_id' => 0, 'note' => null, 'muted_until' => null, 'created_at' => now()],
            ['device_id' => 23, 'note' => 'catatan-rahasia', 'muted_until' => null, 'created_at' => now()],
        ]);

        $res = $this->as('viewer')->getJson('/api/alert_mutes')->assertOk();
        $this->assertNoProductionData($res->getContent());
        $res->assertJsonPath('global.muted', false)->assertJsonPath('devices.0.device_name', 'EDGE-POP-DEMO');

        $this->as('admin')->getJson('/api/alert_mutes')
            ->assertOk()
            ->assertJsonPath('global.muted', true)
            ->assertJsonPath('devices.0.device_name', self::REAL_DEVICE)
            ->assertJsonPath('devices.0.note', 'catatan-rahasia');
    }

    public function test_api_mobile_viewer_memakai_ambang_rx_dummy(): void
    {
        $this->getJson('/api/v1/dashboard', $this->bearer('viewer'))
            ->assertOk()->assertJsonPath('data.thresholds.rx_warn_low', -25);
        $this->getJson('/api/v1/interfaces', $this->bearer('viewer'))
            ->assertOk()->assertJsonPath('meta.thresholds.rx_warn_low', -25);

        $this->getJson('/api/v1/interfaces', $this->bearer('technician'))
            ->assertOk()->assertJsonPath('meta.thresholds.rx_warn_low', -27)
            ->assertJsonPath('data.0.device_name', self::REAL_DEVICE);
    }

    // --- 4. Push alert & token FCM --------------------------------------------------------

    public function test_push_alert_hanya_ke_admin_dan_teknisi_aktif(): void
    {
        $tokens = [
            'fcm-admin' => $this->user('admin')->id,
            'fcm-teknisi' => $this->user('technician')->id,
            'fcm-viewer' => $this->user('viewer')->id,
            'fcm-teknisi-nonaktif' => $this->user('technician', active: false)->id,
            'fcm-user-terhapus' => 9999,
        ];
        foreach ($tokens as $token => $userId) {
            DeviceToken::query()->create(['user_id' => $userId, 'token' => $token, 'last_seen_at' => now()]);
        }

        $sent = [];
        $this->mock(FcmService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendToToken')->andReturnUsing(function (...$args) use (&$sent) {
                $sent[] = $args['deviceToken'] ?? $args[0];

                return ['ok' => true];
            });
        });

        $this->app->make(InterfaceDiscovery::class)->emitDegradationAlert(
            ['device_id' => 23, 'device_name' => self::REAL_DEVICE, 'device_ip' => self::REAL_IP],
            ['if_index' => 5, 'if_name' => 'sfp5', 'if_alias' => self::REAL_ALIAS, 'rx_power' => -30.0, 'tx_power' => 2.0],
            'interface_degradation',
            'warning',
            'Redaman naik (uji)',
        );

        $this->assertEqualsCanonicalizing(['fcm-admin', 'fcm-teknisi'], $sent);
    }

    public function test_viewer_device_token_sukses_tanpa_disimpan(): void
    {
        $this->postJson('/api/v1/device-token', ['token' => 'fcm-hp-demo', 'platform' => 'android'], $this->bearer('viewer'))
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseMissing('device_tokens', ['token' => 'fcm-hp-demo']);

        // HP bersama: token masih tercatat milik teknisi yang sudah logout di semua perangkat →
        // dilepas, supaya alert akun lama tidak terus masuk ke HP yang kini dipakai akun demo.
        $old = $this->user('technician', active: false);
        DeviceToken::query()->create(['user_id' => $old->id, 'token' => 'fcm-hp-bersama', 'last_seen_at' => now()]);
        $this->postJson('/api/v1/device-token', ['token' => 'fcm-hp-bersama'], $this->bearer('viewer'))->assertOk();
        $this->assertDatabaseMissing('device_tokens', ['token' => 'fcm-hp-bersama']);

        // Pembanding: teknisi tetap tersimpan.
        $this->postJson('/api/v1/device-token', ['token' => 'fcm-hp-teknisi'], $this->bearer('technician'))->assertOk();
        $this->assertSame($this->user('technician')->id, (int) DeviceToken::query()->where('token', 'fcm-hp-teknisi')->value('user_id'));
    }

    public function test_viewer_lokasi_sukses_tanpa_disimpan(): void
    {
        $body = ['latitude' => -6.75, 'longitude' => 110.97, 'accuracy' => 12];

        $this->postJson('/api/v1/location', $body, $this->bearer('viewer'))->assertOk()->assertJsonPath('success', true);
        $this->assertSame(0, DB::table('user_locations')->count());

        $this->postJson('/api/v1/location', $body, $this->bearer('technician'))->assertOk();
        $this->assertSame(1, DB::table('user_locations')->where('user_id', $this->user('technician')->id)->count());
    }
}
