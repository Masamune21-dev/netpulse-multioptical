<?php

namespace Tests\Unit;

use App\Services\InterfaceDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * Keputusan up/down + level peringatan RX per port SFP di poller (InterfaceDiscovery::linkState).
 * Ambang memakai bawaan Pengaturan: down ≤ −40, peringatan ≤ −18, kritis < −25.
 */
class LinkStateTest extends TestCase
{
    /** @return array{0: bool, 1: string} */
    private function state(int $oper, ?float $rx, bool $prevHadOptic = true, bool $prevLinkUp = true, string $prevWarnLevel = 'none'): array
    {
        $m = new \ReflectionMethod(InterfaceDiscovery::class, 'linkState');

        return $m->invoke(null, $oper, $rx, -40.0, -18.0, -25.0, $prevHadOptic, $prevLinkUp, $prevWarnLevel);
    }

    public function test_rx_tak_terbaca_saat_port_up_mempertahankan_keadaan_sebelumnya(): void
    {
        // Walk RX timeout sesiklus (switch Huawei, 28 Okt 2026): dulu jadi DOWN palsu.
        $this->assertSame([true, 'none'], $this->state(1, null));
        $this->assertSame([true, 'warning'], $this->state(1, null, prevWarnLevel: 'warning'));
        // Port yang memang sedang down tidak ikut "pulih" hanya karena RX tak terbaca.
        $this->assertSame([false, 'none'], $this->state(1, null, prevLinkUp: false));
    }

    public function test_port_tanpa_riwayat_optik_dengan_rx_null_tetap_tidak_dianggap_up(): void
    {
        // Modul tanpa DDM / port PON: perilaku lama, tidak pernah up sehingga tak pernah alert.
        $this->assertSame([false, 'none'], $this->state(1, null, prevHadOptic: false, prevLinkUp: false));
    }

    public function test_oper_down_tetap_down(): void
    {
        $this->assertSame([false, 'none'], $this->state(2, -40.0));
        $this->assertSame([false, 'none'], $this->state(2, null));
    }

    public function test_rx_di_ambang_down_tetap_down_walau_oper_up(): void
    {
        $this->assertSame([false, 'none'], $this->state(1, -40.0));
        $this->assertSame([false, 'none'], $this->state(1, -45.0));
    }

    public function test_level_peringatan_rx(): void
    {
        $this->assertSame([true, 'none'], $this->state(1, -12.3));
        $this->assertSame([true, 'warning'], $this->state(1, -18.0));
        $this->assertSame([true, 'warning'], $this->state(1, -25.0));
        $this->assertSame([true, 'critical'], $this->state(1, -25.1));
        $this->assertSame([true, 'critical'], $this->state(1, -39.9));
    }
}
