# AGENTS.md — Netpulse Multi Optical

`netpulse.kusumavision.net` — monitoring link fiber: DDM optical power (Rx/Tx dBm), status
interface, SLA, alert Telegram/FCM, network map, APK Flutter. Laravel 12 + Blade/vanilla JS.
**Login lokal** (bukan SSO) — memang terpisah dari ekosistem IdP.

> Detail lengkap: `CLAUDE.md` (wajib) · `docs/NETPULSE_DOCUMENTATION.md` ·
> `docs/SNMP_HUAWEI_OPTICAL_OID_MAP.md` · riwayat: `WORKLOG.md` ·
> aturan lintas app: `/var/www/AGENTS.md`.

## Fakta kunci

| | |
|:--|:--|
| Domain | `netpulse.kusumavision.net` |
| Database | MariaDB `netpulse` (`127.0.0.1:3306`) |
| Cache/queue | **tanpa Redis** — `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync` |
| Scheduler | cron `/etc/cron.d/netpulse` (`schedule:run` per menit + detak `kv-beat`) |
| Remote push | `origin` = **repo PUBLIK** (`netpulse-multioptical`), branch `main` |
| APK | `public/downloads/netpulse.apk`, versi di `mobile/pubspec.yaml` |

## Perintah

```bash
php artisan serve --port=8000
npm run dev / npm run build
bash scripts/test.sh                 # WAJIB — jangan `php artisan test` polos
php artisan poll:interfaces          # polling 23 perangkat SNMP
bash bin/build-apk.sh                # Flutter APK (butuh kunci rilis di /root/.kv-keystores/)
```

Jadwal (`routes/console.php`): `poll:interfaces` tiap menit, `stats:rollup` tiap jam,
`stats:prune` 03:30, `optical:degradation` 06:00.

## Aturan yang mudah dilanggar

- **Repo PUBLIK**: jangan pernah commit IP internal, kredensial SNMP, nama pelanggan, atau data
  produksi. `google-services.json` dan kunci APK berada di luar repo.
- **CSP ditegakkan** dan dipasang nginx lewat `snippets/netpulse-headers.conf` (di luar repo).
  Sumber skrip/gaya dari origin baru wajib diizinkan di sana dulu.
- **Tabel inti lama** (`snmp_devices`, `interfaces`, `users` berkolom username/role/is_active)
  **tidak** dibuat oleh migrasi — test yang membutuhkannya menyiapkan sendiri
  (contoh `SecurityHardeningTest::setUp`).
- Poller men-dispatch paralel 23 router/switch via SNMP; jangan menambah koneksi seri.
- **Design system sendiri** (bukan Dark-Glass Cyan lima app Vue). Ikuti gaya Blade/JS yang ada.
- APK rilis wajib lewat `bin/build-apk.sh` (menolak build tanpa kunci `/root/.kv-keystores/`).

## Struktur penting

`app/` · `routes/` · `resources/views` (Blade) · `mobile/` (Flutter) · `bin/build-apk.sh` ·
`docs/` · `scripts/test.sh`.
