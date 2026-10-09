<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Port "tidak dipakai" (interfaces.is_monitored = 0). Data rekaan saja.
 *
 * Catatan cakupan: jalur poller (InterfaceDiscovery) memanggil fungsi SNMP global sehingga
 * tidak bisa diuji di sini; ringkasan SLA memakai fungsi khusus MySQL (GREATEST,
 * TIMESTAMPDIFF) yang tidak ada di sqlite. Keduanya diverifikasi lewat review kode.
 */
class InterfaceMonitoringTest extends TestCase
{
    use RefreshDatabase;

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
            $t->integer('optical_index')->nullable();
        });

        DB::table('snmp_devices')->insert(['id' => 1, 'device_name' => 'SW-UJI-1', 'ip_address' => '192.0.2.10']);
        DB::table('interfaces')->insert([
            ['device_id' => 1, 'if_index' => 1, 'if_name' => 'sfp1', 'is_sfp' => 1, 'is_monitored' => 1, 'oper_status' => 1, 'rx_power' => -12.0, 'tx_power' => 2.0],
            ['device_id' => 1, 'if_index' => 2, 'if_name' => 'sfp2', 'is_sfp' => 1, 'is_monitored' => 1, 'oper_status' => 2, 'rx_power' => -40.0, 'tx_power' => null],
            ['device_id' => 1, 'if_index' => 3, 'if_name' => 'sfp3', 'is_sfp' => 1, 'is_monitored' => 0, 'oper_status' => 1, 'rx_power' => -13.0, 'tx_power' => 2.0],
        ]);
    }

    private function as(string $role): static
    {
        if (!isset($this->users[$role])) {
            $id = DB::table('users')->insertGetId([
                'name' => $role, 'email' => $role . '@example.test', 'username' => $role . '-uji',
                'full_name' => 'Uji', 'password' => Hash::make('kata-sandi-uji-123'), 'role' => $role,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->users[$role] = User::query()->findOrFail($id);
        }
        $u = $this->users[$role];

        return $this->withSession(['auth.logged_in' => true, 'auth.user' => [
            'id' => $u->id, 'username' => $u->username, 'full_name' => 'Uji', 'role' => $role,
        ]]);
    }

    private function openEvent(int $ifIndex, string $downAt): int
    {
        return DB::table('interface_down_events')->insertGetId([
            'device_id' => 1, 'if_index' => $ifIndex, 'if_name' => "sfp{$ifIndex}", 'if_alias' => '',
            'device_name' => 'SW-UJI-1', 'down_at' => $downAt, 'up_at' => null, 'duration_sec' => null,
            'created_at' => $downAt,
        ]);
    }

    public function test_hanya_admin_yang_boleh_mengubah_status_pantau(): void
    {
        foreach (['technician', 'viewer'] as $role) {
            $this->as($role)->postJson('/api/interfaces/monitoring', [
                'items' => [['device_id' => 1, 'if_index' => 2]], 'monitored' => false,
            ])->assertForbidden();
        }
        $this->assertSame(1, (int) DB::table('interfaces')->where('if_index', 2)->value('is_monitored'));
    }

    public function test_tandai_tidak_dipakai_menutup_kejadian_down_dan_mencatat_riwayat(): void
    {
        $id = $this->openEvent(2, now()->subDays(10)->toDateTimeString());

        $this->as('admin')->postJson('/api/interfaces/monitoring', [
            'items' => [['device_id' => 1, 'if_index' => 2]], 'monitored' => false, 'reason' => 'Pelanggan pindah jalur',
        ])->assertOk()->assertJson(['changed' => 1, 'closed_events' => 1]);

        $this->assertSame(0, (int) DB::table('interfaces')->where('if_index', 2)->value('is_monitored'));
        $event = DB::table('interface_down_events')->where('id', $id)->first();
        $this->assertNotNull($event->up_at);
        $this->assertGreaterThanOrEqual(10 * 86400 - 5, (int) $event->duration_sec);

        $history = DB::table('interface_monitoring_changes')->where('if_index', 2)->get();
        $this->assertCount(1, $history);
        $this->assertSame('Pelanggan pindah jalur', $history[0]->reason);
        $this->assertSame('admin-uji', $history[0]->changed_by);
    }

    public function test_pantau_lagi_tercatat_dan_perubahan_yang_sama_tidak_diduplikasi(): void
    {
        $body = ['items' => [['device_id' => 1, 'if_index' => 3]], 'monitored' => true];
        $this->as('admin')->postJson('/api/interfaces/monitoring', $body)->assertOk()->assertJson(['changed' => 1]);
        $this->as('admin')->postJson('/api/interfaces/monitoring', $body)->assertOk()->assertJson(['changed' => 0]);

        $this->assertSame(1, (int) DB::table('interfaces')->where('if_index', 3)->value('is_monitored'));
        $this->assertSame(1, DB::table('interface_monitoring_changes')->where('if_index', 3)->count());
    }

    public function test_validasi_input(): void
    {
        $this->as('admin')->postJson('/api/interfaces/monitoring', ['items' => [['device_id' => 1, 'if_index' => 2]]])
            ->assertStatus(422);
        $this->as('admin')->postJson('/api/interfaces/monitoring', ['items' => [], 'monitored' => false])
            ->assertStatus(422);
        $this->as('admin')->postJson('/api/interfaces/monitoring', [
            'items' => [['device_id' => 1, 'if_index' => 2]], 'monitored' => false, 'reason' => str_repeat('x', 256),
        ])->assertStatus(422);
    }

    public function test_kandidat_tidak_dipakai_hanya_port_dipantau_yang_down_lama(): void
    {
        $this->openEvent(2, now()->subDays(9)->toDateTimeString()); // kandidat
        $this->openEvent(1, now()->subDays(2)->toDateTimeString());  // baru 2 hari: bukan
        $this->openEvent(3, now()->subDays(30)->toDateTimeString()); // sudah tidak dipakai: bukan

        // Viewer (akun demo) mendapat kandidat dummy — lihat ViewerDummyIsolationTest.
        $res = $this->as('technician')->getJson('/api/sla/candidates')->assertOk();
        $this->assertSame([2], array_column($res->json('candidates'), 'if_index'));
        $this->assertGreaterThanOrEqual(9, $res->json('candidates.0.down_days'));
    }

    public function test_daftar_interface_menyembunyikan_port_tidak_dipakai_secara_bawaan(): void
    {
        $res = $this->as('admin')->getJson('/api/interfaces/all')->assertOk();
        $this->assertEqualsCanonicalizing([1, 2], array_column($res->json('data'), 'if_index'));
        $this->assertSame(1, $res->json('meta.unmonitored_total'));

        $all = $this->as('admin')->getJson('/api/interfaces/all?monitored=all')->assertOk();
        $this->assertCount(3, $all->json('data'));
        $row = collect($all->json('data'))->firstWhere('if_index', 3);
        $this->assertFalse($row['is_monitored']);
        $this->assertTrue($row['active_again']); // up dengan RX: patut dicek

        $only = $this->as('admin')->getJson('/api/interfaces/all?monitored=unmonitored')->assertOk();
        $this->assertSame([3], array_column($only->json('data'), 'if_index'));
    }

    public function test_monitoring_menyembunyikan_port_tidak_dipakai_kecuali_diminta(): void
    {
        $res = $this->as('admin')->getJson('/api/monitoring_interfaces?device_id=1')->assertOk();
        $this->assertEqualsCanonicalizing([1, 2], array_column($res->json(), 'if_index'));

        $all = $this->as('admin')->getJson('/api/monitoring_interfaces?device_id=1&include_unmonitored=1')->assertOk();
        $this->assertCount(3, $all->json());
    }

    public function test_api_mobile_monitoring_menyembunyikan_port_tidak_dipakai(): void
    {
        $this->as('admin'); // buat user
        $token = $this->users['admin']->createToken('uji')->plainTextToken;
        $h = ['Authorization' => 'Bearer ' . $token];

        $res = $this->getJson('/api/v1/monitoring/interfaces?device_id=1', $h)->assertOk();
        $this->assertEqualsCanonicalizing([1, 2], array_column($res->json('data'), 'if_index'));

        $all = $this->getJson('/api/v1/monitoring/interfaces?device_id=1&include_unmonitored=1', $h)->assertOk();
        $this->assertCount(3, $all->json('data'));
    }
}
