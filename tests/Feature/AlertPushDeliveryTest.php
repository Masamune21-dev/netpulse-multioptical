<?php

namespace Tests\Feature;

use App\Services\InterfaceDiscovery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Push FCM dari poller harus cepat: alert yang lambat dulu membuat proses poll per perangkat
 * dibunuh timeout sebelum state alert tersimpan, sehingga LINK DOWN yang sama terkirim tiap
 * menit (21 Okt 2026). Data rekaan saja; HTTP ke Google dipalsukan.
 */
class AlertPushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private InterfaceDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('name', 50)->primary();
            $t->text('value')->nullable();
        });

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $path = tempnam(sys_get_temp_dir(), 'fcm-uji-');
        file_put_contents($path, json_encode([
            'client_email' => 'uji@proyek-uji.iam.gserviceaccount.com',
            'private_key' => $pem,
            'project_id' => 'proyek-uji',
        ]));
        config(['services.firebase.service_account_json' => $path]);

        $uid = DB::table('users')->insertGetId([
            'name' => 'Teknisi Uji', 'email' => 'teknisi@example.test', 'password' => 'x',
            'role' => 'technician', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['token-hidup-1', 'token-mati', 'token-hidup-2'] as $i => $token) {
            DB::table('device_tokens')->insert([
                'user_id' => $uid, 'token' => $token, 'platform' => 'android',
                'last_seen_at' => now()->subMinutes($i), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->discovery = app(InterfaceDiscovery::class);
    }

    private function push(): void
    {
        $m = new \ReflectionMethod(InterfaceDiscovery::class, 'sendMobilePush');
        $m->invoke($this->discovery, 'critical', 'interface_down', 'Interface down: SW-UJI / sfp1',
            ['device_id' => 1, 'device_name' => 'SW-UJI', 'device_ip' => '192.0.2.10'], ['if_index' => 1]);
    }

    private function oauthCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com'))->count();
    }

    public function test_access_token_is_fetched_once_and_unregistered_token_is_removed(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'akses-uji', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => function (Request $r) {
                return ($r->data()['message']['token'] ?? '') === 'token-mati'
                    ? Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                    : Http::response(['name' => 'projects/proyek-uji/messages/1']);
            },
        ]);

        $this->push();
        $this->push();

        $this->assertSame(1, $this->oauthCalls());
        $this->assertSame(
            ['token-hidup-1', 'token-hidup-2'],
            DB::table('device_tokens')->orderBy('token')->pluck('token')->all()
        );
    }

    public function test_other_fcm_errors_keep_the_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'akses-uji', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 500, 'status' => 'INTERNAL']], 500),
        ]);

        $this->push();

        $this->assertSame(3, DB::table('device_tokens')->count());
    }

    public function test_unreachable_google_stops_pushing_for_the_rest_of_the_poll(): void
    {
        $attempts = 0;
        Http::fake([
            'oauth2.googleapis.com/*' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('Resolving timed out after 10000 milliseconds');
            },
            'fcm.googleapis.com/*' => Http::response(['name' => 'x']),
        ]);

        $this->push(); // first token fails → the other two are skipped
        $this->push(); // a later alert in the same poll does not retry either

        $this->assertSame(1, $attempts);
        $this->assertSame(3, DB::table('device_tokens')->count());
    }
}
