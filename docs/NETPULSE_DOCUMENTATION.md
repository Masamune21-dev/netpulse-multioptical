# NetPulse MultiOptical Documentation

Last updated: 2026-10-04

Dokumen ini menjelaskan struktur aplikasi NetPulse MultiOptical saat ini: UI web, mobile app, routing, API, role, setting, scheduler, polling SNMP, deployment, dan troubleshooting.

## Ringkasan

NetPulse MultiOptical adalah platform monitoring jaringan optik/SFP berbasis Laravel dan Flutter. Aplikasi web dipakai untuk NOC/admin, sedangkan aplikasi mobile memakai API v1 dengan bearer token.

Komponen utama:

- Laravel 12 web dashboard dengan Blade views (upgrade dari 11 pada 24 Sep 2026).
- Legacy web API di `/api/*` untuk halaman web.
- Mobile API v1 di `/api/v1/*` untuk Flutter app.
- SNMP discovery/polling via command `poll:interfaces`.
- Alert log Web UI, Telegram alert, dan FCM push notification.
- Interactive network map dengan node, link, status interface, dan path link.
- Role-based access: `admin`, `technician`, `viewer`.

## Struktur Repository

| Path | Fungsi |
| --- | --- |
| `app/Http/Controllers` | Controller web page dan legacy web API. |
| `app/Http/Controllers/Api/V1` | Controller REST API untuk mobile app. |
| `app/Http/Middleware` | Middleware session auth, role, dan bearer token API. |
| `app/Services/InterfaceDiscovery.php` | Discovery SNMP, simpan interface/statistik, kirim alert. |
| `app/Services/Optical/` | Deteksi vendor dan driver optik (lihat [Dukungan Vendor & Driver Optik](#dukungan-vendor--driver-optik)). |
| `app/Services/FcmService.php` | Kirim FCM push notification via Firebase HTTP v1. |
| `app/Console/Commands/PollInterfaces.php` | Artisan command polling semua device aktif. |
| `routes/web.php` | Route halaman web dan legacy web API. |
| `routes/api.php` | Route REST API v1 untuk mobile. |
| `routes/console.php` | Laravel scheduler. |
| `resources/views` | Blade UI: login, layout, dashboard, monitoring, devices, map, users, settings. |
| `public/assets/js` | JavaScript halaman web. |
| `public/assets/css` | Global CSS. Halaman memuat `style.min.css`. |
| `mobile/` | Flutter Android app. |
| `scripts/cron/laravel_schedule_run.sh` | Script cron untuk menjalankan scheduler Laravel per menit. |
| `scripts/test.sh` | Pembungkus test aman (sqlite in-memory, cache produksi dilewati). |
| `bin/build-apk.sh` | Build APK rilis (split-per-abi) ke `public/downloads/`. |
| `storage/logs` | Laravel log, security log, schedule log. |
| `storage/app/alert_state.json` | State transisi alert polling SNMP. |

## Alur Akses

### Web Session

1. User login dari `/login`.
2. `AuthController` validasi `username`, `password`, dan `is_active`.
3. Session menyimpan `auth.logged_in` dan `auth.user`.
4. Route web dilindungi middleware `legacy.auth`.
5. Aksi tertentu dibatasi middleware `legacy.role`.

Security log web login ditulis ke:

```text
storage/logs/security.log
```

### API v1 Token

1. Client mobile memanggil `POST /api/v1/auth/login`.
2. Server membuat token di tabel `personal_access_tokens`.
3. Client mengirim header:

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

4. Middleware `api.auth` memvalidasi token dan mengisi `$request->user()`.

### Role Matrix

| Modul | Admin | Technician | Viewer |
| --- | --- | --- | --- |
| Dashboard | Read | Read | Read dummy/demo data |
| Monitoring | Read | Read | Read dummy/demo data |
| Devices | Read/create/update/delete | Read | Read dummy/demo data |
| Interface discovery | Run | Run/read via UI behavior, write depends endpoint access | Dummy no-write |
| Map | Read/create/update/delete | Read | Read dummy/demo data |
| Users | Read/create/update/delete | Read | Dummy/read-limited |
| Settings | Read/write | Read | Dummy/read-limited |
| Alert logs | Read/delete | Read | Read dummy/demo data |
| SLA report (+ ekspor CSV/PDF) | Read | Read | Read dummy/demo data |
| Security logs | Read | Forbidden | Dummy/read-limited |
| Push alert (FCM) | Diterima (akun aktif) | Diterima (akun aktif) | Tidak — token tidak disimpan |
| Lokasi mobile | Disimpan | Disimpan | Dijawab sukses, tidak disimpan |

Catatan: beberapa tombol create/edit/delete juga disembunyikan dari UI memakai `body[data-role]`, tetapi pembatasan utama tetap di controller/middleware.

## Keamanan

Keadaan setelah pengerasan **10 September 2026**. Bagian ini menjelaskan mekanisme yang
mudah dirusak tanpa sengaja saat menambah fitur.

### CSRF — dua kelompok rute yang perlakuannya berbeda

| Kelompok | Autentikasi | CSRF |
|---|---|---|
| `api/v1/*` | Bearer token, **tanpa sesi** | Dikecualikan |
| `/api/*` (web) | Sesi | **Wajib** `X-CSRF-TOKEN` |

Pengecualian CSRF di `bootstrap/app.php` **hanya** `api/v1/*`. Rute web `/api/*` ber-sesi
wajib mengirim header, dan itu ditangani otomatis oleh pembungkus global `fetch` /
`XMLHttpRequest` di `layouts/app.blade.php` (menyuntik header untuk request same-origin
non-GET), sehingga seluruh JS lama di `public/assets/js/*.js` ikut tercakup tanpa diubah.

Dua konsekuensi yang mudah terlewat saat menambah endpoint:

- **Endpoint yang menulis DB tidak boleh `GET`.** `discover_interfaces` dan
  `huawei_discover_optics` sudah dipindahkan ke `POST` karena keduanya menulis.
- **Logout adalah `POST`**, bukan `GET` — sebuah form ber-`@csrf`. `GET /logout` sekarang
  membalas 405.

### Pembatasan laju

| Limiter | Batas | Dipasang di |
|---|---|---|
| `login` | 5/menit per `username\|ip` **dan** 20/menit per IP (anti password spraying) | `POST /login`, `POST /api/v1/auth/login` |
| `optical-snmp` | 6/menit per admin | Uji profil optik & deteksi ulang vendor |
| `api` | 120/menit per user/token/IP | Seluruh grup `routes/api.php` |

Kegagalan dan pembatasan dicatat ke `storage/logs/security.log` lewat
[`app/Support/SecurityLog.php`](../app/Support/SecurityLog.php) — event `LOGIN_THROTTLED`,
`API_LOGIN_FAILED`, `API_LOGIN_SUCCESS`.

### Rahasia at-rest

[`app/Support/Secret.php`](../app/Support/Secret.php) mengenkripsi `snmp_devices.community`
dan `settings.bot_token` memakai `Crypt`. Sifatnya idempoten, dan `reveal()` punya fallback
plaintext sehingga baris lama yang belum sempat terenkripsi tetap terbaca.

Pembacanya: `InterfaceDiscovery` (poller & Telegram), `DevicesApiController::testSnmp`, dan
`telegramTest`. **Selalu lewat `Secret::reveal()`** — jangan membaca kolomnya langsung.

Di sisi keluaran:

- `GET /api/devices` memakai `select` eksplisit **tanpa** `community`, `snmp_user`, maupun
  `telnet_*`; yang dikirim hanya flag `community_set` / `snmp_user_set`. UI menampilkan `••••`.
- `bot_token` diredaksi lewat `Secret::redactSettings()` — non-admin menerima `''`, admin
  menerima placeholder `••••` plus flag `bot_token_set`.
- **Form yang dikosongkan berarti "pertahankan nilai lama".** Saat menyimpan, placeholder
  diabaikan oleh `Secret::prepareSettingsForSave()`; nilai baru dienkripsi.

### Password & token

- Fallback `hash_equals($input, $user->password)` **sudah dihapus** dari kedua
  `AuthController`. Tidak ada lagi jalur yang menerima password plaintext.
- Perintah `users:hash-plaintext-passwords` (punya `--dry-run`) tersedia untuk berjaga; saat
  dijalankan di produksi hasilnya **0 dari 5** baris — semuanya sudah bcrypt.
- Token API punya `expires_at`, **default 90 hari**. Respons login v1 menyertakannya.
- `AuthenticateApiToken` menolak akun `is_active=0`.
- [`app/Support/UserState.php`](../app/Support/UserState.php) memuat ulang `role` dan
  `is_active` dari DB (cache 60 detik), dipakai `EnsureAuthenticated` dan `EnsureRole`.
  Artinya **akun yang dinonaktifkan kehilangan sesinya dalam ≤60 detik**, tidak perlu
  menunggu logout. `UsersApiController` mem-flush cache itu saat user diubah atau dihapus.
- Sejak 26 Sep 2026 `UserState` juga membawa sidik sandi; sesi web menyimpan `auth.pw` saat
  login dan `EnsureAuthenticated` memutus sesi yang sidiknya berbeda — **reset sandi oleh admin
  ikut memutus sesi web** user itu (admin yang mengganti sandinya sendiri tidak tertendang).
- Ganti sandi, peran, atau status aktif — dan hapus user — mencabut semua token API & token
  push user itu.
- Push alert otomatis (poller & `optical:degradation`) hanya dikirim ke token milik akun
  `is_active = 1` ber-peran `admin`/`technician` (`InterfaceDiscovery::PUSH_ROLES`, sejak 9 Okt 2026).
  Viewer adalah akun demo: semua endpoint baca menyajikan data dummy (`ViewerDummyData`), token
  FCM & lokasinya tidak disimpan.
- Login mengecek kata sandi **sebelum** status aktif; username tak dikenal tetap menjalankan
  `Hash::check` tiruan supaya waktu respons tidak membocorkan keberadaannya.

### Konfigurasi & berkas

- `SESSION_SECURE_COOKIE=true` — cookie sesi ber-flag `Secure`.
- `storage/app/firebase/service-account.json` dan `storage/logs/*.log` ber-mode **0640**.
- Produksi memakai `route:cache` + `config:cache`; **keduanya wajib diperbarui setelah
  mengubah rute atau config**, kalau tidak perubahan tidak akan berlaku.
- Header keamanan (HSTS, `X-Frame-Options`, CSP) dipasang di nginx, bukan di aplikasi. CSP
  **ditegakkan** sejak 24 Sep 2026 (sebelumnya Report-Only). Skrip/stylesheet/sumber dari origin
  yang belum diizinkan akan diblokir browser sampai CSP di nginx disesuaikan.

### Catatan tentang test

Sejak 24 Sep 2026 test dijalankan **hanya** lewat `bash scripts/test.sh` (atau `composer test`).
Skrip itu mengalihkan `APP_CONFIG_CACHE`/`APP_ROUTES_CACHE`/`APP_EVENTS_CACHE` ke path yang tidak
ada, mengalihkan `FIREBASE_SERVICE_ACCOUNT_JSON`, lalu menolak jalan bila koneksi tidak resolve ke
sqlite `:memory:`. `phpunit.xml` memuat pengalihan yang sama sebagai lapis kedua.

**Jangan `php artisan test` polos** di server produksi: config cache menang atas `<env>` phpunit dan
test ber-`RefreshDatabase` akan menjalankan `migrate:fresh` di MariaDB `netpulse`. Tabel inti lama
(`snmp_devices`, `interfaces`, `users` berkolom username/role/is_active) tidak dibuat migrasi —
test yang membutuhkannya menyiapkan sendiri (contoh `tests/Feature/SecurityHardeningTest::setUp`).

### Dependensi

Sejak 24 Sep 2026 `laravel/framework` **^12.0** (upgrade dari 11.56; cabang 11 sudah EOL) dan
`composer audit` bersih. `composer.json` masih memasang `config.audit.block-insecure=false` —
sisa masa 11.x, ketika Composer 2.9 memblokir seluruh 11.x akibat dua advisori framework.

## UI Web

### Layout Utama

File:

- `resources/views/layouts/app.blade.php`
- `public/assets/css/style.min.css`
- `public/assets/js/theme.js`
- `public/assets/js/script.js`

Elemen layout:

- Desktop sidebar: logo, navigasi, user info, logout.
- Mobile header: brand ringkas.
- Mobile bottom navigation: Dashboard, Monitor, Devices, Map, Settings.
- Topbar: title halaman, action slot, live clock, user chip.
- Global delete modal.
- Footer.

Navigasi desktop:

- `/dashboard`
- `/monitoring`
- `/devices`
- `/interfaces`
- `/sla`
- `/map`
- `/users`
- `/settings`
- Logout (form `POST /logout` ber-CSRF)

### Login

Route:

- `GET /login`
- `POST /login`

File:

- `resources/views/auth/login.blade.php`
- `app/Http/Controllers/AuthController.php`

Fungsi:

- Login berbasis username/password (bcrypt; tidak ada lagi jalur plaintext).
- Kata sandi dicek dulu, baru status aktif; akun `is_active = 0` ditolak.
- Dibatasi limiter `login` (lihat [Pembatasan laju](#pembatasan-laju)).
- Mencatat `LOGIN_SUCCESS` dan `LOGIN_FAILED`.

### Dashboard

Route:

- `GET /dashboard`

File:

- `resources/views/dashboard/index.blade.php`
- `app/Http/Controllers/DashboardController.php`

Data utama:

- Device aktif dari `snmp_devices`.
- Total interface dari `interfaces`.
- Jumlah SFP/optical aktif.
- Optical critical dari `alert_logs` dan status interface.
- Total user.
- Device health.
- Interface up/down.
- Trend alert 7 hari.
- Worst optical ports.
- Recent alerts.

API pendukung:

- `GET /api/v1/dashboard` untuk refresh KPI mobile/API.

### Monitoring

Route:

- `GET /monitoring`

File:

- `resources/views/monitoring/index.blade.php`
- `public/assets/js/monitoring.js`
- `app/Http/Controllers/MonitoringApiController.php`

Fungsi UI:

- Pilih device aktif.
- Pilih interface SFP.
- Pilih range chart: `1h`, `1d`, `3d`, `7d`, `30d`, `1y`.
- Chart RX/TX/loss memakai Chart.js.
- Statistik RX: sekarang, rata-rata, minimum, maximum.

API pendukung:

- `GET /api/monitoring_devices`
- `GET /api/monitoring_interfaces?device_id=...`
- `GET /api/interface_chart?device_id=...&if_index=...&range=...`

### Devices

Route:

- `GET /devices`

File:

- `resources/views/devices/index.blade.php`
- `public/assets/js/devices.js`
- `app/Http/Controllers/DevicesApiController.php`
- `app/Http/Controllers/InterfacesApiController.php`
- `app/Http/Controllers/DiscoverInterfacesController.php`

Tab/Fungsi:

- SNMP Devices: list, add, edit, delete, test SNMP.
- Interface Discovery: pilih device, discover interface, tampilkan interface/SFP.
- Huawei discovery endpoint memakai controller yang sama dengan discovery umum.

API pendukung:

- `GET /api/devices`
- `GET /api/devices?test=<id>`
- `POST /api/devices`
- `DELETE /api/devices?id=<id>`
- `GET /api/interfaces?device_id=<id>`
- `POST /api/discover_interfaces` (`device_id`)
- `POST /api/huawei_discover_optics` (`device_id`)

### Map

Route:

- `GET /map`

File:

- `resources/views/map/index.blade.php`
- `public/assets/js/map.js`
- `app/Http/Controllers/MapApiController.php`

Fungsi UI:

- Leaflet network map.
- Tambah/edit/hapus node.
- Lock/unlock posisi node.
- Tambah/hapus link antar node/interface.
- Edit path link.
- Filter status/device.
- Detail sidebar node dan interface.

API pendukung:

- `ANY /api/map_nodes`
- `ANY /api/map_links`
- `GET /api/map_devices`

Tabel `map_nodes` dan `map_links` dibuat otomatis oleh `MapApiController` jika belum ada, tetapi lebih baik disiapkan lewat schema/migration saat deployment production.

### Users

Route:

- `GET /users`

File:

- `resources/views/users/index.blade.php`
- `public/assets/js/users.js`
- `app/Http/Controllers/UsersApiController.php`

Fungsi UI:

- List user.
- Add/edit user.
- Reset password saat edit.
- Aktif/nonaktif user.
- Delete user.

Proteksi:

- Read: admin/technician, viewer memakai dummy data.
- Write/delete: admin.
- Admin tidak bisa delete akun sendiri.
- Sistem mencegah delete admin terakhir.

### Settings

Route:

- `GET /settings`

File:

- `resources/views/settings/index.blade.php`
- `public/assets/js/settings.js`
- `app/Http/Controllers/SettingsApiController.php`

Tab/Fungsi:

- Telegram: `bot_token`, `chat_id`, test Telegram.
- Alert: channel toggle, event toggle, threshold RX.
- Theme: light/dark dan warna primary/sidebar.
- Logs: security log viewer.
- Alert logs: filter, refresh, clear.
- Vendor & Optik (admin saja): vendor per perangkat, override driver, profil OID optik — lihat
  [Dukungan Vendor & Driver Optik](#dukungan-vendor--driver-optik).

API pendukung:

- `GET|POST /api/settings`
- `POST /api/telegram_test`
- `GET /api/logs?type=security`
- `GET /api/alert_logs`
- `DELETE /api/alert_logs`

## Mobile App

Path:

- `mobile/`

Versi:

- `2.1.2+8` (24 Sep 2026) — ditandatangani kunci rilis sendiri. Pengguna APK lama (bertanda
  tangan kunci debug) wajib menghapus aplikasi lalu memasang ulang sekali.

Fitur:

- Login API v1.
- Dashboard (Beranda).
- Monitoring chart.
- Daftar interface + riwayat trafik.
- Network map.
- Account/settings.
- Alert log access.
- Device token registration.
- FCM push notification.
- Send current location.

Default API base URL:

```text
https://netpulse.kusumavision.net
```

Di build **rilis** base URL terkunci ke nilai bawaan (nilai tersimpan dari versi lama diabaikan);
hanya build debug yang bisa mengubahnya dari screen account. Token Bearer disimpan di
`flutter_secure_storage`, dan cleartext HTTP dimatikan di build rilis.

Build rilis (yang dibagikan ke pengguna):

```bash
bash bin/build-apk.sh
```

Hasilnya APK split-per-abi di `public/downloads/`: `netpulse.apk` (arm64, yang disajikan
`GET /download/app`) dan `netpulse-arm32.apk`. Build rilis gagal keras bila berkas kunci rilis
tidak ada (path dari env `NETPULSE_KEY_PROPERTIES` atau `mobile/android/key.properties`, keduanya
di luar git) — tidak ada lagi jatuh diam-diam ke kunci debug.

Build debug:

```bash
cd mobile
flutter pub get
flutter build apk --debug
```

Firebase file lokal yang diperlukan:

```text
mobile/android/app/google-services.json
```

File tersebut tidak boleh di-commit.

## Web Routing

Route list diverifikasi dengan `php artisan route:list`.

### Public Route

| Method | Path | Controller | Fungsi |
| --- | --- | --- | --- |
| GET | `/` | closure | Redirect ke `/login`. |
| GET | `/login` | `AuthController@showLogin` | Form login. |
| POST | `/login` | `AuthController@login` | Proses login (limiter `login`). |
| POST | `/logout` | `AuthController@logout` | Logout session (form ber-CSRF; `GET /logout` membalas 405). |
| GET | `/healthz` | closure | Health check JSON (`status`, `database`); 503 bila MariaDB tidak terjangkau. Dipakai monitoring uptime. |
| GET | `/download/app` | closure | Unduh APK terbaru (`public/downloads/netpulse.apk`, tanpa cache). `/downloads/netpulse.apk` sama. |
| GET | `/up` | Laravel health route | Health check bawaan Laravel. |

### Protected Page Route

Semua route berikut memakai middleware `legacy.auth`.

| Method | Path | Controller | Fungsi |
| --- | --- | --- | --- |
| GET | `/dashboard` | `DashboardController@index` | Dashboard NOC. |
| GET | `/monitoring` | `MonitoringController@index` | Monitoring optical chart. |
| GET | `/devices` | `DevicesController@index` | Device management dan discovery. |
| GET | `/interfaces` | `InterfacesController@index` | Daftar interface lintas perangkat, status pantau, trafik. |
| GET | `/sla` | `SlaController@index` | Laporan SLA + ekspor, kandidat port tidak dipakai. |
| GET | `/map` | `MapController@index` | Interactive network map. |
| GET | `/users` | `UsersController@index` | User management. |
| GET | `/settings` | `SettingsController@index` | Telegram, alert, theme, logs, Vendor & Optik. |

### Legacy Web API

Semua legacy endpoint berikut berada dalam group `legacy.auth` dan dipakai oleh halaman web.

| Method | Path | Role | Fungsi |
| --- | --- | --- | --- |
| GET | `/api/users` | admin/technician/viewer | List user, viewer mendapat dummy data. |
| POST | `/api/users` | admin | Create/update user. |
| DELETE | `/api/users?id=...` | admin | Delete user. |
| GET | `/api/devices` | logged-in | List SNMP device. |
| GET | `/api/devices?test=...` | logged-in | Test SNMP device. |
| POST | `/api/devices` | admin | Create/update device. |
| DELETE | `/api/devices?id=...` | admin | Delete device dan data terkait. |
| GET | `/api/interfaces?device_id=...` | logged-in | List interface device. |
| GET | `/api/monitoring_devices` | logged-in | List device aktif untuk monitoring. |
| GET | `/api/monitoring_interfaces?device_id=...` | logged-in | List interface SFP. |
| GET | `/api/interface_chart?device_id=...&if_index=...&range=...` | logged-in | Chart history interface. |
| ANY | `/api/map_nodes` | read semua role, write admin | CRUD node map. |
| ANY | `/api/map_links` | read semua role, write admin | CRUD link map/path. |
| GET | `/api/map_devices` | logged-in | Device yang belum terpasang di map. |
| POST | `/api/discover_interfaces` (`device_id`) | admin/technician/viewer | Discovery SNMP interface (viewer mendapat hasil dummy). |
| POST | `/api/huawei_discover_optics` (`device_id`) | admin/technician/viewer | Alias discovery untuk Huawei. |
| GET | `/api/settings` | admin/technician/viewer | Read settings, viewer dummy. |
| POST | `/api/settings` | admin | Upsert settings. |
| POST | `/api/telegram_test` | admin | Kirim pesan test Telegram. |
| GET | `/api/logs?type=security` | admin/viewer | Security log, viewer dummy. |
| GET | `/api/alert_logs` | admin/technician/viewer | List alert log. |
| DELETE | `/api/alert_logs` | admin | Clear alert log. |
| GET | `/api/dashboard/summary` | logged-in | Refresh KPI dashboard web. |
| GET | `/api/interfaces/all` · `/api/interfaces/traffic_history` | logged-in | Daftar interface lintas perangkat & riwayat trafik (viewer: dummy). |
| GET | `/api/interfaces/thresholds` | admin/technician/viewer | Ambang RX per interface (viewer: dummy). |
| POST · DELETE | `/api/interfaces/thresholds` | admin | Simpan/hapus ambang RX per interface. |
| POST | `/api/interfaces/monitoring` | admin | Tandai port tidak dipakai / pantau lagi (lihat [Port Tidak Dipakai](#port-tidak-dipakai)). |
| GET | `/api/interfaces/monitoring/history` | admin/technician/viewer | Riwayat perubahan status pantau (viewer: kosong). |
| GET | `/api/sla` · `/api/sla/events` · `/api/sla/candidates` | admin/technician/viewer | Ringkasan SLA, kejadian down, kandidat port tidak dipakai (viewer: dummy). |
| GET | `/api/sla/export` · `/export-pdf` · `/interface/export` · `/interface/export-pdf` | admin/technician/viewer | Ekspor SLA CSV/PDF (ringkasan & per interface; viewer: dari data dummy). |
| GET | `/api/alert_mutes` | admin/technician/viewer | Daftar mute alert (jendela pemeliharaan; viewer: dummy). |
| POST · DELETE | `/api/alert_mutes` | admin | Atur/hapus mute per perangkat atau global. |
| GET | `/api/mobile_devices` · `/api/mobile_push_targets` | admin | Perangkat mobile terdaftar & target push. |
| POST | `/api/mobile_push_send` | admin | Kirim push manual. |
| DELETE | `/api/mobile_devices/{id}` | admin | Cabut perangkat mobile. |
| GET · POST · DELETE | `/api/optical/*` | admin | Vendor & Optik (lihat [Dukungan Vendor & Driver Optik](#dukungan-vendor--driver-optik)). |
| GET | `/api/data` | logged-in | Legacy placeholder realtime. |
| GET | `/api/test_connection?id=...` | logged-in | Legacy placeholder test connection. |
| GET | `/api/export?format=csv|json` | logged-in | Legacy placeholder export. |

## API v1

Base path:

```text
/api/v1
```

Response umum:

```json
{
  "success": true,
  "data": {}
}
```

Endpoint auth dapat mengembalikan `error` langsung untuk status 401/403.

### Public API

| Method | Path | Body/Query | Fungsi |
| --- | --- | --- | --- |
| GET | `/api/v1/ping` | none | Health check API JSON. |
| POST | `/api/v1/auth/login` | `username`, `password`, optional `device_name` | Login dan buat bearer token. |

Contoh login:

```bash
curl -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"secret","device_name":"android"}'
```

### Authenticated API

Semua endpoint berikut memakai middleware `api.auth`.

| Method | Path | Role | Body/Query | Fungsi |
| --- | --- | --- | --- | --- |
| POST | `/api/v1/auth/logout` | authenticated | optional `fcm_token` | Hapus token aktif; `fcm_token` milik user dilepas. |
| GET | `/api/v1/dashboard` | admin/technician/viewer | none | KPI dashboard (viewer: dummy). |
| GET | `/api/v1/monitoring/devices` | admin/technician/viewer | none | Device aktif. |
| GET | `/api/v1/monitoring/interfaces` | admin/technician/viewer | `device_id` | Interface SFP. |
| GET | `/api/v1/monitoring/chart` | admin/technician/viewer | `device_id`, `if_index`, `range` | Chart optical history. |
| GET | `/api/v1/interfaces` | admin/technician/viewer | `page`, `per_page`, `device_id`, `status`, `q`, `sort` | Daftar interface (viewer: dummy). |
| GET | `/api/v1/interfaces/traffic-history` | admin/technician/viewer | `device_id`, `if_index`, `range` | Riwayat trafik interface (viewer: dummy). |
| GET | `/api/v1/map/nodes` | admin/technician/viewer | optional `with_interfaces=1` | Node map. |
| GET | `/api/v1/map/links` | admin/technician/viewer | none | Link map. |
| GET | `/api/v1/alert-logs` | admin/technician/viewer | `limit`, `type`, `severity`, `q` | Alert log. |
| DELETE | `/api/v1/alert-logs` | admin | none | Clear alert log. |
| GET | `/api/v1/logs` | admin/viewer | `type=security` | Security log, viewer dummy. |
| GET | `/api/v1/settings` | admin/technician/viewer | none | Read settings. |
| POST | `/api/v1/settings` | admin | arbitrary key/value JSON | Upsert settings. |
| GET | `/api/v1/alert-preferences` | authenticated | none | Mobile alert preference per user. |
| POST | `/api/v1/alert-preferences` | authenticated | `push_enabled`, `severity_min` | Update preference push. |
| POST | `/api/v1/device-token` | authenticated | `token`, optional `platform`, `device_name` | Register FCM token (viewer: sukses tanpa disimpan; baris lama token itu dilepas). |
| POST | `/api/v1/location` | authenticated | `latitude`, `longitude`, optional `accuracy`, `recorded_at` | Simpan lokasi user (viewer: sukses tanpa disimpan). |
| POST | `/api/v1/push/test` | authenticated | optional `title`, `body` | Kirim test FCM ke token milik pemanggil sendiri (`token` di body diabaikan). |

Range chart valid:

```text
1h, 1d, 3d, 7d, 30d, 1y
```

Severity alert valid:

```text
info, warning, critical
```

## Settings

Settings disimpan di tabel `settings` dengan kolom `name` dan `value`.

| Key | Default | Fungsi |
| --- | --- | --- |
| `bot_token` | empty | Telegram bot token. |
| `chat_id` | empty | Telegram chat/channel ID. |
| `alert_telegram_enabled` | `1` | Enable alert Telegram. |
| `alert_webui_enabled` | `1` | Enable simpan alert ke Web UI log. |
| `alert_interface_down` | `1` | Alert saat interface optical down. |
| `alert_interface_up` | `1` | Alert saat interface kembali up. |
| `alert_interface_warning` | `1` | Alert saat RX masuk warning/critical threshold. |
| `alert_device_down` | `1` | Alert saat device unreachable. |
| `alert_device_up` | `1` | Alert saat device kembali reachable. |
| `alert_rx_warning_high` | `-18.0` | Mulai warning jika RX <= nilai ini. |
| `alert_rx_warning_low` | `-25.0` | Batas bawah warning; di bawah ini jadi critical selama masih di atas down threshold. |
| `alert_rx_down_threshold` | `-40.0` | RX <= nilai ini dianggap down. |
| `theme` | `light` | Tema UI web. |
| `primary_color` | `#6366f1` | Warna primary UI. |
| `primary_soft` | `#8b5cf6` | Warna gradient kedua UI. |
| `mobile_alert_pref_user_<id>` | JSON default | Preference push per user mobile. |

Contoh update settings:

```bash
curl -X POST http://localhost/api/v1/settings \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"alert_webui_enabled":"1","alert_rx_warning_high":"-18.0"}'
```

## Scheduler dan Polling SNMP

Scheduler didefinisikan di:

```text
routes/console.php
```

Jadwal aktif:

| Command | Jadwal | Fungsi |
| --- | --- | --- |
| `poll:interfaces --timeout=30` | tiap menit, `withoutOverlapping(10)` | Polling SNMP semua device, statistik, alert. |
| `stats:rollup` | tiap jam (menit ke-5) | Agregasi sampel mentah ke rollup per jam. |
| `stats:prune` | harian 03:30 | Hapus sampel mentah yang lewat masa simpan. |
| `optical:degradation` | harian 06:00 | Deteksi link dengan daya optik menurun. |

Command:

```bash
php artisan poll:interfaces
php artisan poll:interfaces --device=1
php artisan schedule:list
php artisan schedule:run
php artisan sla:reconcile            # dry run; --apply untuk menutup kejadian SLA basi
```

Produksi menjalankan scheduler lewat `/etc/cron.d/netpulse` (user `www-data`, `schedule:run` tiap
menit, lalu detak `kv-beat netpulse-schedule` untuk pemantauan server). Script cron di bawah adalah
alternatif untuk instalasi lain.

Script cron:

```text
scripts/cron/laravel_schedule_run.sh
```

Contoh crontab:

```cron
* * * * * /var/www/NetpulseMultiOptical/scripts/cron/laravel_schedule_run.sh
```

Script cron:

- Pindah ke root project.
- Membuat `storage/logs` dan `storage/app` jika belum ada.
- Memakai `flock` jika tersedia.
- Menulis output ke `storage/logs/schedule-run.log`.

Polling melakukan:

1. Ambil semua device aktif dari `snmp_devices`.
2. Jalankan `InterfaceDiscovery::discover($deviceId, true)`.
3. Walk IF-MIB untuk index, name, description, alias, oper status.
4. Ambil optical power lewat driver optik (lihat [Dukungan Vendor & Driver Optik](#dukungan-vendor--driver-optik)).
5. Upsert snapshot ke `interfaces`.
6. Insert history ke `interface_stats`.
7. Deteksi transisi device/interface up/down dan RX warning.
8. Simpan Web UI alert, kirim FCM push, dan kirim Telegram jika aktif.
9. Simpan state alert ke `storage/app/alert_state.json`.

SNMP function yang dibutuhkan:

- `snmp2_walk`
- `snmp2_get`
- `snmp2_real_walk`
- `snmp3_get` untuk test SNMP v3 sederhana.

## Dukungan Vendor & Driver Optik

Status/trafik interface dibaca lewat IF-MIB standar untuk semua perangkat SNMP v2c. Daya optik
(DDM RX/TX) butuh MIB vendor, dan sejak 24 Sep 2026 dibaca lewat lapisan driver di
`app/Services/Optical/`:

| Kelas | Isi |
|---|---|
| `VendorDetector` | Baca `sysObjectID` (1.3.6.1.2.1.1.2.0) + `sysDescr` sekali, simpan di tabel `optical_device_vendors`, baca ulang tiap 7 hari (1 jam bila gagal). Enterprise 14988 → mikrotik, 2011 → huawei; sysDescr VRP/Huawei/Quidway/CloudEngine atau RouterOS juga dikenali. |
| `OpticalDriverResolver` | Urutan: override admin → profil aktif yang cocok → driver bawaan vendor → (vendor tak terdeteksi) perilaku lama persis: MikroTik untuk semua + Huawei bila nama perangkat memuat huawei/quidway/cloudengine. Driver yang lebih dulu menang per ifName. Kegagalan resolver/driver dicatat `Log::warning` dan tidak menghentikan polling. |
| `MikrotikDriver` | `mtxrOpticalTable` 1.3.6.1.4.1.14988.1.1.19.1.1 (.2 nama, .9 TX, .10 RX, 0,001 dBm). Terverifikasi. |
| `HuaweiDriver` | `hwEntityOpticalRxPower/TxPower` 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.8/.9 via `entAliasMappingIdentifier`; 0,01 dBm bila ≤ 0, µW bila > 0. Terverifikasi. |
| `EntitySensorDriver` | ENTITY-SENSOR-MIB (1.3.6.1.2.1.99.1.1.1) lalu CISCO-ENTITY-SENSOR-MIB (1.3.6.1.4.1.9.9.91.1.1.1.1): sensor `watts(6)` saja (enumerasi tipe di kedua MIB tidak memuat dBm), RX/TX dari nama sensor, ifIndex lewat alias map sensor/induknya atau nama port di label. **Berbasis standar, belum diverifikasi di perangkat BMKV.** |
| `CustomProfileDriver` | Profil admin di tabel `optical_profiles`: OID kolom RX/TX, indeks (`ifIndex` / `entPhysicalIndex`), satuan, nilai tak sah. |

Templat bawaan (nonaktif sampai lulus uji), OID dicocokkan dengan teks MIB resmi:
Juniper JUNIPER-DOM-MIB `1.3.6.1.4.1.2636.3.60.1.1.1.1.5`/`.7` dan H3C/HPE Comware
HH3C-TRANSCEIVER-INFO-MIB `1.3.6.1.4.1.25506.2.70.1.1.1.12`/`.9`, keduanya 0,01 dBm per ifIndex.

**UI:** Pengaturan → tab **Vendor & Optik** (admin saja): tabel vendor per perangkat + override
driver + deteksi ulang, daftar profil + Uji (pratinjau mentah → dBm) + Aktifkan. Menyunting profil
membatalkan hasil uji dan menonaktifkannya. Rute `/api/optical/*` memakai `legacy.role:admin` dan
pemeriksaan ulang di controller; Uji & deteksi ulang dibatasi 6/menit (`throttle:optical-snmp`).

**Keamanan:** host & community selalu dari `snmp_devices` — pengguna tidak pernah memasok alamat.
OID wajib numerik minimal 9 komponen (tidak bisa walk subtree besar seperti `1.3.6.1.2.1`).

**Uji paritas / diagnosa:** `php artisan optical:probe` membaca optik semua perangkat tanpa menulis
statistik/alert. `--mode=legacy` (jalur lama) vs `--mode=driver`, lalu
`--compare lama.json baru.json` (toleransi default 0,5 dB karena pembacaan hidup berfluktuasi).

## Port Tidak Dipakai

Kolom lama `interfaces.is_monitored` (bawaan `1`) kini dipakai sungguhan:

| | Port dipantau (`1`) | Port tidak dipakai (`0`) |
|---|---|---|
| Status, RX/TX, `last_seen` di `interfaces` | diperbarui | **tetap diperbarui** (untuk lencana "Aktif kembali") |
| Alert down/up/warning, degradasi | ya | tidak |
| `interface_down_events` (SLA) | ya | tidak; kejadian terbuka ditutup saat ditandai |
| `interface_stats` / `interface_traffic_stats` / rollup | ya | tidak |
| Laporan SLA + ekspor, hitungan dashboard, daftar interface & API v1 | ikut | disembunyikan (kecuali `include_unmonitored=1` / filter "Pantau") |

- Ubah: `POST /api/interfaces/monitoring` `{items:[{device_id,if_index}], monitored, reason?}` — khusus
  admin (middleware + cek ulang di controller). Riwayat: `GET /api/interfaces/monitoring/history`.
- Riwayat perubahan di tabel `interface_monitoring_changes` (siapa, kapan, alasan).
- Kandidat: `GET /api/sla/candidates` — port dipantau yang kejadian down-nya terbuka lebih dari
  `SlaController::UNUSED_CANDIDATE_DAYS` (7) hari; tampil sebagai panel di halaman SLA.
- Poller membuang state alert port yang tidak dipakai, sehingga saat dipantau lagi ia mulai bersih
  (tanpa alert transisi dari keadaan lama).
- Port yang **tidak lagi dilaporkan perangkat** lebih dari 24 jam (setelah walk lengkap) ditandai
  tidak dipakai oleh `system` (`InterfaceDiscovery::reconcileVanishedPorts()`); bila dilaporkan lagi,
  otomatis dipantau kembali. Port yang ditandai admin tetap nonaktif. Barisnya tidak dihapus.
- Kejadian down yang masih terbuka ditutup poller begitu link terlihat up, tanpa menunggu transisi
  di state alert. Riwayat lama yang basi diperbaiki dengan `php artisan sla:reconcile`.

## Database

### Tabel Inti yang Dipakai Aplikasi

Repo ini dapat berjalan di atas database monitoring existing. Beberapa migration hanya membuat tabel pendukung Laravel/mobile/alert, sedangkan tabel monitoring inti perlu tersedia pada database deployment.

Tabel inti yang dipakai kode:

| Tabel | Fungsi | Kolom penting yang dibaca/ditulis |
| --- | --- | --- |
| `users` | Login web/API dan role | `id`, `username`, `full_name`, `password`, `role`, `is_active`, `created_at` |
| `snmp_devices` | Inventory SNMP | `id`, `device_name`, `ip_address`, `snmp_version`, `community`, `snmp_user`, `is_active`, `last_status`, `last_error` |
| `interfaces` | Snapshot interface | `id`, `device_id`, `if_index`, `if_name`, `if_alias`, `if_description`, `if_type`, `optical_index`, `rx_power`, `tx_power`, `oper_status`, `last_seen`, `is_sfp`, `is_monitored`, `interface_type`, `updated_at` |
| `interface_stats` | History chart | `device_id`, `if_index`, `tx_power`, `rx_power`, `loss`, `created_at` |
| `settings` | Key/value settings | `name`, `value` |
| `map_nodes` | Node map | `id`, `device_id`, `node_name`, `node_type`, `x_position`, `y_position`, `icon_type`, `is_locked` |
| `map_links` | Link map | `id`, `node_a_id`, `node_b_id`, `interface_a_id`, `interface_b_id`, `attenuation_db`, `notes`, `path_json` |
| `alert_logs` | Web UI alert feed | `event_type`, `severity`, `device_*`, `if_*`, `rx_power`, `tx_power`, `message`, `context`, `fingerprint` |
| `personal_access_tokens` | API bearer token | Custom Sanctum-style token table. |
| `device_tokens` | FCM token mobile | `user_id`, `token`, `platform`, `device_name`, `last_seen_at` |
| `user_locations` | Mobile location report | `user_id`, `latitude`, `longitude`, `accuracy`, `recorded_at` |

Catatan penting untuk fresh install:

- Pastikan schema inti `users`, `snmp_devices`, `interfaces`, `interface_stats`, dan `settings` tersedia sebelum aplikasi dipakai penuh.
- Migration bawaan Laravel di repo ini memakai guard `Schema::hasTable(...)` supaya aman untuk database existing.
- Jangan mengandalkan seeder default Laravel untuk membuat akun NetPulse production. Buat user admin sesuai schema NetPulse (`username`, `full_name`, `role`, `is_active`).

## Instalasi Backend

### Requirement

- PHP 8.2 atau lebih baru.
- Composer.
- MySQL/MariaDB.
- Node.js dan npm untuk build asset jika diperlukan.
- PHP extension: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `xml`, `zip`, `snmp`.
- SNMP tools/library pada server.
- Web server Nginx/Apache.

Contoh package Ubuntu/Debian:

```bash
sudo apt update
sudo apt install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-snmp snmp composer unzip git nginx mysql-server
```

### Setup Project

```bash
cd /var/www
git clone <repo-url> NetpulseMultiOptical
cd NetpulseMultiOptical
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Konfigurasi `.env` minimal:

```env
APP_NAME="NetPulse MultiOptical"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=netpulse
DB_USERNAME=netpulse
DB_PASSWORD=strong-password

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

FIREBASE_SERVICE_ACCOUNT_JSON=/var/www/NetpulseMultiOptical/storage/app/firebase-service-account.json
```

Jalankan migration pendukung:

```bash
php artisan migrate
```

Permission storage/cache:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rw storage bootstrap/cache
```

Build asset jika memakai Vite pipeline:

```bash
npm install
npm run build
```

Optimasi production:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Nginx Contoh

```nginx
server {
    listen 80;
    server_name your-domain.example;
    root /var/www/NetpulseMultiOptical/public;

    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### Cron Scheduler

```bash
chmod +x /var/www/NetpulseMultiOptical/scripts/cron/laravel_schedule_run.sh
crontab -e
```

Isi crontab:

```cron
* * * * * /var/www/NetpulseMultiOptical/scripts/cron/laravel_schedule_run.sh
```

Verifikasi:

```bash
php artisan schedule:list
tail -f storage/logs/schedule-run.log
tail -f storage/logs/laravel.log
```

## Instalasi Mobile Android

```bash
cd mobile
flutter pub get
flutter build apk --debug
```

Output:

```text
mobile/build/app/outputs/flutter-apk/app-debug.apk
```

Untuk Firebase:

- Web/backend membutuhkan `FIREBASE_SERVICE_ACCOUNT_JSON` di `.env`.
- Android app membutuhkan `mobile/android/app/google-services.json`.
- Jangan commit file Firebase secret.

## Operasional

### Menambah Device

1. Login sebagai admin.
2. Buka `/devices`.
3. Tambahkan SNMP device: name, IP, SNMP version, community/user.
4. Test SNMP.
5. Jalankan discovery interface.
6. Pastikan interface SFP muncul.
7. Scheduler akan mulai menyimpan `interface_stats`.

### Menyiapkan Alert

1. Buka `/settings`.
2. Isi Telegram bot token dan chat ID.
3. Test Telegram.
4. Buka tab Alert.
5. Atur channel, event type, dan threshold RX.
6. Jalankan `php artisan poll:interfaces --device=<id>` untuk verifikasi.

### Menyiapkan Map

1. Buka `/map`.
2. Tambahkan node dari device yang tersedia.
3. Pilih interface untuk link antar node.
4. Atur path link bila perlu.
5. Gunakan lock agar posisi node tidak berubah.

## Log dan File Runtime

| Path | Fungsi |
| --- | --- |
| `storage/logs/laravel.log` | Error aplikasi Laravel. |
| `storage/logs/security.log` | Login success/failed web. |
| `storage/logs/schedule-run.log` | Output scheduler cron. |
| `storage/app/alert_state.json` | State transisi alert polling. |
| `storage/app/firebase-service-account.json` | Contoh lokasi Firebase service account lokal. |

## Troubleshooting

### `SNMP extension not installed`

Install PHP SNMP extension dan restart PHP-FPM:

```bash
sudo apt install php8.2-snmp snmp
sudo systemctl restart php8.2-fpm
php -m | grep snmp
```

### Chart kosong

Cek:

- Device aktif di `snmp_devices`.
- Interface terdeteksi di `interfaces`.
- Polling mengisi `interface_stats`.
- Query memakai `device_id`, `if_index`, dan `range` yang benar.

Command:

```bash
php artisan poll:interfaces --device=1
php artisan schedule:list
tail -f storage/logs/schedule-run.log
```

### Telegram tidak terkirim

Cek:

- `bot_token` dan `chat_id` di Settings.
- Server bisa akses `https://api.telegram.org`.
- `alert_telegram_enabled = 1`.

### FCM push gagal

Cek:

- `.env` punya `FIREBASE_SERVICE_ACCOUNT_JSON`.
- File service account ada dan readable oleh user web server.
- Mobile app sudah register token via `/api/v1/device-token`.
- User preference `mobile_alert_pref_user_<id>` tidak mematikan push.

### 401 API mobile

Cek:

- Header `Authorization: Bearer <token>`.
- Token masih ada di `personal_access_tokens`.
- User masih aktif.

### 403 Web/API

Cek role user:

- Write action umumnya butuh `admin`.
- `technician` banyak mendapat read-only.
- `viewer` memakai dummy/demo data di banyak endpoint.

## Checklist Release

```bash
php artisan route:list
php artisan schedule:list
bash scripts/test.sh      # JANGAN `php artisan test` polos — lihat Catatan tentang test
npm run build
git status --short
```

Pastikan file berikut tidak ikut commit:

- `.env`
- Firebase service account JSON.
- `mobile/android/app/google-services.json`
- `storage/logs/*`
- `storage/app/alert_state.json`

