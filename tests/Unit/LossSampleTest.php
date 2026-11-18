<?php

namespace Tests\Unit;

use App\Services\InterfaceDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * Kapan poller mencatat sampel LOS (-40) ke `interface_stats`.
 *
 * Latar (18 Nov 2026): sebuah port SFP pelanggan benar-benar mati, alert Telegram/FCM
 * terkirim, dan `interfaces.oper_status` sudah 2 — tetapi halaman Monitoring tetap
 * menampilkan RX dari sampel terakhir sebelum link putus, seolah port masih nyala.
 * Sejak commit 11 Nov 2026 sampel -40 memang tidak pernah ditulis, sehingga grafik
 * berhenti tanpa penjelasan apa pun.
 *
 * Aturannya sekarang: port SFP terpantau yang statusnya DIKETAHUI mati mencatat satu
 * sampel LOS per siklus. Port UP dengan RX tak terbaca tetap tidak dicatat karena itu
 * kegagalan walk DDM (penyebab alert palsu 26 & 28 Okt 2026), bukan link putus.
 */
class LossSampleTest extends TestCase
{
    private function should(bool $sfp, bool $monitored, bool $operKnown, int $oper): bool
    {
        $m = new \ReflectionMethod(InterfaceDiscovery::class, 'shouldRecordLossSample');

        return (bool) $m->invoke(null, $sfp, $monitored, $operKnown, $oper);
    }

    public function test_port_terpantau_yang_benar_benar_mati_mencatat_los(): void
    {
        $this->assertTrue($this->should(true, true, true, 2));
        $this->assertTrue($this->should(true, true, true, 3));
    }

    public function test_port_up_tidak_pernah_mencatat_los(): void
    {
        $this->assertFalse($this->should(true, true, true, 1));
    }

    public function test_status_tidak_diketahui_tidak_mencatat_los(): void
    {
        // Walk ifOperStatus terpotong: jangan menebak port mati.
        $this->assertFalse($this->should(true, true, false, 2));
    }

    public function test_port_tidak_dipakai_dan_non_sfp_tidak_mencatat_los(): void
    {
        // Port "tidak dipakai" dikecualikan dari seluruh sampel statistik.
        $this->assertFalse($this->should(true, false, true, 2));
        $this->assertFalse($this->should(false, true, true, 2));
    }
}
