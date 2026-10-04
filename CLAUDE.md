# CLAUDE.md — Netpulse Multi Optical

Netpulse Multi Optical — Sistem monitoring link fiber optik, DDM power optical, dan status antarmuka perangkat ISP (Laravel 12 + Blade/vanilla JS + Flutter Mobile; repo GitHub PUBLIK sejak 24 Sep 2026 — jangan commit IP internal, kredensial, atau data pelanggan). Dimiliki oleh PT BERKAH MEDIA KUSUMA VISION (BMKV). Domain: `netpulse.kusumavision.net`.

User berkomunikasi dalam bahasa Indonesia — jawab dalam bahasa Indonesia (bilingual saat membahas istilah teknis).

## Perintah Utama

```bash
php artisan serve --port=8000
npm run dev
npm run build
php artisan poll:interfaces          # Jalankan polling manual 23 perangkat SNMP
bash bin/build-apk.sh                # Kompilasi Flutter APK (hasil: public/downloads/netpulse.apk)
```

## Arsitektur & Lingkungan Server

- **Database**: MariaDB `netpulse` di `127.0.0.1:3306`.
- **Runtime**: PHP 8.3-FPM (`/run/php/php8.3-fpm.sock`).
- **Nginx & SSL**: `/etc/nginx/sites-available/netpulse.kusumavision.net` memakai wildcard SSL `/etc/nginx/ssl/kusumavision.wildcard.pem`.
- **Scheduler**: Cron di `/etc/cron.d/netpulse` (`* * * * * www-data php .../artisan schedule:run && /usr/local/bin/kv-beat netpulse-schedule` — detak untuk pemantauan server). Jadwal di `routes/console.php`: `poll:interfaces` tiap menit, `stats:rollup` tiap jam, `stats:prune` 03:30, `optical:degradation` 06:00. Poller men-dispatch paralel 23 router/switch via SNMP.
- **Tanpa Redis**: `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync` (push FCM terkirim sinkron). `/healthz` hanya memeriksa MariaDB.
- **Header keamanan & CSP**: dipasang nginx lewat `snippets/netpulse-headers.conf` (di luar repo); CSP **ditegakkan** sejak 24 Sep 2026 — sumber skrip/gaya dari origin baru wajib diizinkan di sana dulu.
- **Git**: produksi di branch `main`, upstream `origin/main` = `github.com/Masamune21-dev/netpulse-multioptical` (PUBLIK). Branch `fix/up-alert-cooldown` sudah digabung ke `main` (3 Okt 2026).
- **APK**: versi di `mobile/pubspec.yaml` (kini `2.1.2+8`); `bin/build-apk.sh` menghasilkan split-per-abi `public/downloads/netpulse.apk` (arm64) + `netpulse-arm32.apk`, diunduh lewat `/download/app`.
- **Mobile Toolchain**: Flutter 3.44 di `/opt/flutter` + Android SDK di `/opt/android-sdk`. `google-services.json` di `mobile/android/app/` (di-.gitignore). Kunci rilis APK di `/root/.kv-keystores/` (di luar repo, ikut backup GPG `kv-backup-all.sh`); `bin/build-apk.sh` menolak build tanpa kunci itu.

## Aturan Baku AI Agent

- **Wajib Catat di WORKLOG.md**: Setiap pekerjaan dan perubahan kode/fitur/tampilan/bugfix **WAJIB dicatat di berkas `WORKLOG.md`** sebelum commit. Cantumkan tanggal, kategori perubahan (Created/Changed/Fixed/Notes), dan penjelasan teknis secara akurat. Jangan pernah menyelesaikan task tanpa memperbarui `WORKLOG.md`.

## Test

Jalankan **hanya** `bash scripts/test.sh` / `composer test` — checkout ini produksi (MariaDB `netpulse`,
config cache aktif) dan `php artisan test` polos akan mengenai database produksi. `RefreshDatabase`
bisa dipakai sejak 24 Sep 2026. Tabel inti lama (`snmp_devices`, `interfaces`, `users` berkolom
username/role/is_active) **tidak** dibuat oleh migrasi — test yang membutuhkannya menyiapkan
kolom/tabelnya sendiri (contoh: `tests/Feature/SecurityHardeningTest::setUp`).
