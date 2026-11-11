<?php

namespace Tests\Unit;

use App\Services\InterfaceDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * Ambang "mass transition" poller — pengaman terhadap banjir alert palsu.
 *
 * Kasus nyata 11 Nov 2026 21:40 WIB (switch inti agregasi): satu walk ifOperStatus
 * gagal, 34 dari 34 port SFP dilaporkan turun serentak, 8 alert DOWN + 8 alert UP
 * terkirim padahal trafik di port-port itu tidak pernah berhenti. Perubahan status
 * sebanyak itu dalam satu siklus harus dibaca sebagai kegagalan polling.
 */
class MassTransitionTest extends TestCase
{
    private function threshold(int $monitoredSfp): int
    {
        $m = new \ReflectionMethod(InterfaceDiscovery::class, 'massTransitionThreshold');

        return (int) $m->invoke(null, $monitoredSfp);
    }

    public function test_switch_dengan_banyak_port_memakai_setengah_jumlah_port(): void
    {
        // 34 port SFP terpantau → ambang 17 port.
        $this->assertSame(17, $this->threshold(34));
        $this->assertSame(17, $this->threshold(33));
    }

    public function test_switch_kecil_tetap_memakai_batas_minimal_lima_port(): void
    {
        // 6 port SFP → separuhnya hanya 3; satu port turun belum boleh ditahan.
        $this->assertSame(5, $this->threshold(6));
        $this->assertSame(5, $this->threshold(0));
        $this->assertSame(5, $this->threshold(8));
    }

    public function test_kejadian_sekarjalak_terdeteksi_sebagai_mass_transition(): void
    {
        $threshold = $this->threshold(34);

        // 34 port turun serentak > ambang 17 → alert per port harus ditahan.
        $this->assertGreaterThanOrEqual($threshold, 34);
        // 2 port turun bersamaan masih dianggap gangguan nyata.
        $this->assertLessThan($threshold, 2);
    }

    /** @return array{0:int,1:bool} */
    private function oper(array $map, int $ifIdx, int $prev = 2): array
    {
        $m = new \ReflectionMethod(InterfaceDiscovery::class, 'operForIndex');

        return $m->invoke(null, $map, $ifIdx, $prev);
    }

    public function test_entri_oper_hilang_dibaca_sebagai_tidak_tahu_bukan_down(): void
    {
        // Walk terpotong: ifIndex 12 ada, 13 tidak. 13 tidak boleh dianggap down.
        $map = [12 => 'INTEGER: 1'];

        $this->assertSame([1, true], $this->oper($map, 12));
        // Status sebelumnya (up) dipertahankan dan ditandai tidak diketahui.
        $this->assertSame([1, false], $this->oper($map, 13, 1));
        // Tanpa status sebelumnya, nilai aman adalah 2 (down) TAPI ditandai
        // tidak diketahui sehingga tidak memicu alert.
        $this->assertSame([2, false], $this->oper($map, 13));
    }
}
