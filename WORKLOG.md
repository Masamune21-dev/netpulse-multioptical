# WORKLOG — Netpulse Multi Optical

Sistem pemantauan status antarmuka fiber optik, redaman/DDM optical power, dan SLA jaringan ISP PT BERKAH MEDIA KUSUMA VISION (BMKV).

---

## 2026-10-09 — Fixed: Kebocoran Data Produksi ke Role `viewer` (Akun Demo)

- **Fixed (laporan SLA)**: `/api/sla`, `/api/sla/events`, `/api/sla/candidates`, ekspor CSV/PDF ringkasan
  dan per-interface tidak punya cabang viewer — akun demo membaca seluruh `interface_down_events` produksi
  (ribuan kejadian, alias berisi nama mitra) dan menu "SLA Report" tampil untuk semua peran. Kini viewer
  mendapat kejadian dummy dari `ViewerDummyData::slaDownEvents()` (perangkat 101–104 & port yang sama
  dengan layar dummy lain, waktu relatif terhadap sekarang); agregasi meniru query asli (jendela, potong
  awal jendela, `HAVING down_count > 0`, urutan), filter perangkat/pencarian tetap jalan, dan ekspor
  CSV/PDF dibuat dari data itu (cakupan PDF tidak lagi membaca `snmp_devices`). `SlaController`: query
  MySQL dipindah ke `summaryRows()`/`eventRows()` (yang kedua juga dipakai `interfacePayload()`, dulu
  SQL-nya tergandakan); perhitungan ketersediaan tetap satu kode untuk semua peran.
- **Fixed (`/api/interfaces/traffic_history`)**: cek viewer ada di bawah query interface, dan jalur "tabel
  statistik belum ada" kembali lebih dulu — meta (nama/IP perangkat, alias, status) yang dikirim tetap
  asli untuk `device_id` produksi mana pun. Cek dipindah ke awal; viewer memakai
  `ViewerDummyData::apiTrafficHistory()` (sama dengan API mobile, kini meta mengikuti perangkat/port dummy
  yang diminta lewat `interfaceMeta()`). Daftar interface dummy web memakai perangkat 101–104 (dulu
  "Demo Device 1–3", tidak cocok dengan filter perangkat & modal).
- **Fixed (GET tanpa cabang viewer)**: `/api/interfaces/thresholds` (meta, ambang global, override, saran
  baseline → semua dummy), `/api/interfaces/monitoring/history` (dulu berisi alasan + username
  `changed_by` → kosong), `/api/alert_mutes` (catatan pemeliharaan → satu mute rekaan).
- **Fixed (push alert)**: `InterfaceDiscovery::sendMobilePush()` mengirim ke **semua** baris
  `device_tokens`. Kini JOIN `users`: hanya akun `is_active = 1` ber-peran `admin`/`technician`
  (`InterfaceDiscovery::PUSH_ROLES`) — sekaligus menutup push ke akun nonaktif dan token yatim (user
  terhapus). Berlaku juga untuk alert degradasi harian (`optical:degradation`).
- **Fixed (API mobile)**: `POST /api/v1/device-token` untuk viewer membalas sukses tanpa menyimpan (APK
  mendaftar otomatis saat login); baris lama token HP itu — milik viewer sendiri atau akun lain yang sudah
  logout di semua perangkat (HP bersama) — dilepas. Penjaga 409 token milik akun lain yang masih login
  tetap berlaku. `POST /api/v1/location` untuk viewer: sukses tanpa menyimpan.
- **Fixed (sapuan)**: `/api/v1/dashboard` & `/api/v1/interfaces` viewer memakai ambang RX dummy
  (`ViewerDummyData::globalRxThresholds()`), bukan nilai `settings` produksi. Sapuan semua rute GET web &
  API v1 lainnya: sudah bercabang viewer / khusus admin / tanpa data.
- **Changed (`docs/NETPULSE_DOCUMENTATION.md`)**: matriks peran (baris SLA, push, lokasi), tabel API web &
  v1 ditandai perilaku viewer, bagian Keamanan mencatat penerima push alert.
- **Notes**: test baru `ViewerDummyIsolationTest` (14) — fixture "produksi" bertanda, viewer diuji tak
  menerima penanda itu, admin/teknisi diuji tetap mendapat data asli; 13 di antaranya gagal pada kode
  sebelum perbaikan. `InterfaceMonitoringTest` kandidat SLA kini memakai teknisi (viewer = dummy). Jalur
  admin ringkasan/kejadian SLA memakai SQL MySQL dan tetap tak teruji di sqlite. Suite 67 lulus. Tidak ada
  migrasi/rute baru; poller jalan per menit lewat cron (`schedule:run`), tidak ada daemon untuk di-restart.

## 2026-10-04 — Docs: Dokumentasi Disinkronkan dengan Keadaan Produksi

- **Changed (`docs/NETPULSE_DOCUMENTATION.md`)**: Laravel 11 → 12; catatan test lama ("phpunit tidak
  diarahkan ke sqlite") diganti `scripts/test.sh`, dan **checklist rilis tidak lagi menyuruh
  `php artisan test` polos**; bagian Dependensi mengikuti upgrade 24 Sep; limiter login 20/menit per IP +
  `optical-snmp`; reset sandi memutus sesi web (26 Sep), pencabutan token, urutan cek sandi; CSP ditegakkan
  di nginx; login tidak lagi "auto re-hash plaintext"; navigasi + rute halaman `/interfaces` & `/sla`;
  rute publik `POST /logout`, `/healthz`, `/download/app`; discovery `GET` → `POST`; ±15 baris API web yang
  belum tercatat (SLA, thresholds, monitoring, alert mutes, mobile push, optical); API v1 `/interfaces` &
  `traffic-history`, `push/test` mengabaikan `token`, logout menerima `fcm_token`; tabel empat jadwal
  scheduler + cron produksi `/etc/cron.d/netpulse`; port hilang otomatis & `sla:reconcile`; aplikasi
  mobile 2.1.2+8, base URL `netpulse.kusumavision.net` terkunci di rilis, build lewat `bin/build-apk.sh`.
- **Changed (`docs/SNMP_HUAWEI_OPTICAL_OID_MAP.md`)**: catatan keberlakuan di atas — rujukan kode
  (`SNMPManager`, `snmp_optical`, `snmp:poll-optical-due`) berasal dari modul lain dan tidak ada di repo ini;
  OID & normalisasi daya tetap berlaku, implementasi Netpulse = `HuaweiDriver` + `EntityAliasMap`.
- **Changed (`mobile/README.md`)**: versi 2.0.0+2 → 2.1.2+8, build rilis wajib kunci sendiri (split-per-abi),
  base URL terkunci di rilis, token di secure storage.
- **Changed (`CLAUDE.md`)**: cron + `kv-beat`, daftar jadwal, tanpa Redis (file/sync), CSP di snippet nginx,
  branch `main` ↔ `origin/main` publik, lokasi & versi APK.
- **Notes**: hanya dokumen; tidak ada kode, config, atau test yang disentuh. Tidak ada IP internal, nama
  perangkat, atau kredensial yang ditambahkan (repo publik).

## 2026-10-03 — Notes: `fix/up-alert-cooldown` Digabung ke `main`

- **Notes**: produksi berjalan di branch `fix/up-alert-cooldown` (= `main` + `670d3ef` reset sandi memutus sesi
  web). Digabung fast-forward: `origin/main` dimajukan ke `670d3ef`, checkout produksi dipindah ke `main`
  (upstream `origin/main`) tanpa satu berkas pun berubah. Branch fix lama dibiarkan di remote. Kit DR SSO
  (`tools/dr/lib/apps.sh`) kini mengklon `main`. Ditemukan kit DR: database MariaDB `netpulse` (±2,3 GB) belum
  pernah dicadangkan — sejak 3 Okt masuk `kv-backup-all.sh` (harian tanpa data `interface_stats` &
  `interface_traffic_stats`, mingguan/bulanan penuh), bersama `storage/app` dan `storage/app/firebase` (blob rahasia).

## 2026-09-26 — Fixed: Reset Sandi oleh Admin Memutus Sesi Web

- **Fixed**: sesi web tetap hidup (sliding 120 menit lewat polling dashboard) setelah admin mereset sandi
  user. `UserState` kini membawa sidik sandi (`pw`), sesi menyimpan `auth.pw = [uid, fp]` saat login, dan
  `EnsureAuthenticated` memutus sesi yang sidiknya berbeda (entri cache lama tanpa `pw` dilewati; admin yang
  mengganti sandinya sendiri tidak tertendang).
- **Notes**: server — `bootstrap/cache` & `storage` kini `o-rwx` (config cache & 148 berkas sesi dulu bisa
  dibaca user lain). Test `SecurityHardeningTest` +1; suite 53 lulus.

## 2026-09-24 — Created: Pilih Banyak Port di Halaman Interfaces

- **Created**: kotak centang per baris + "pilih semua di halaman ini" (hanya admin) di `/interfaces`.
  Begitu ada port terpilih muncul bilah aksi lengket: **"N port dipilih · Tandai tidak dipakai (x) ·
  Pantau lagi (y) · Batal pilih"** — tombol hanya tampil bila ada port yang relevan (yang masih dipantau
  untuk ditandai, yang nonaktif untuk dipantau lagi). Keduanya memakai dialog `portMonitoring.open()`
  yang sama (alasan opsional) dan endpoint `POST /api/interfaces/monitoring` (maks. 500 port, sudah ada).
- **Notes**: pilihan disimpan per `device:ifIndex` dan bertahan saat pindah halaman / ganti filter,
  jadi port dari beberapa halaman bisa ditandai sekaligus; status pantau yang tersimpan diperbarui tiap
  render. Setelah sukses, port yang diproses dilepas dari pilihan. `colspan` baris kosong/muat/galat kini
  mengikuti jumlah kolom (11 admin, 10 lainnya). Diverifikasi di 1440/1280/390 px (tanpa scroll
  horizontal halaman, tanpa galat konsol) memakai akun admin sementara yang sudah dihapus; dialog
  dibuka lalu dibatalkan, tidak ada port yang diubah. Test: 52 lulus.

## 2026-09-24 — Docs: Screenshot README (Data Demo)

- **Created**: bagian **Screenshots** di `README.md` — dashboard web (`readme-dashboard.webp`) dan galeri
  5 layar aplikasi Android (`mobile-{login,beranda,monitoring,interface,peta}.webp`), dikompres ke WebP
  (±5 MB → ±570 KB).
- **Notes — hanya data demo**: screenshot pertama yang diserahkan memuat IP manajemen, nama switch,
  nama mitra/pelanggan, dan lokasi node asli di peta — tidak di-commit (repo publik) dan dipindah dari
  `public/` ke luar webroot (tadinya bisa diakses lewat URL). Semua screenshot di README diambil dengan
  akun **viewer** sementara, yang selalu menerima `ViewerDummyData` (perangkat `*-DEMO`, IP `10.10.x`,
  node peta rekaan); diverifikasi lewat API tanpa IP/nama asli. Akun demo beserta token API & token push
  sudah dihapus (sisa 0).

## 2026-09-24 — Fixed: Port "Hantu" yang Tak Lagi Dilaporkan Perangkat

- **Fixed**: satu switch 4 port SFP (CRS305-1G-4S+) tampil dengan 5 port SFP — `sfp-sfpplus8`
  terakhir terlihat 13 Mar 2026 dengan RX beku. Poller hanya memperbarui port yang masih dilaporkan
  perangkat; baris port yang hilang (hardware diganti, ifIndex MikroTik bernomor ulang setelah
  reboot/upgrade) dibiarkan selamanya. Terukur 12 baris basi di 5 switch (6/2/2/1/1). Switch lain yang
  dilaporkan "8 port" memang CRS309-1G-8S+ (8 port; 2 slot kosong = not present).
- **Changed (poller)**: `InterfaceDiscovery::reconcileVanishedPorts()` — setelah walk lengkap, port yang
  tidak dilaporkan > 24 jam (`VANISHED_AFTER_HOURS`) ditandai "tidak dipakai" oleh `system` (alasan
  "Tidak lagi dilaporkan perangkat (otomatis)"), kejadian SLA terbukanya ditutup. Baris TIDAK dihapus
  karena tabel lama (`alerts`, `sfp_optical_logs`) merujuk `interfaces.id`. Port yang ditandai sistem
  otomatis dipantau lagi bila dilaporkan perangkat kembali; port yang ditandai admin tetap nonaktif.
  Walk parsial tidak memicu apa pun karena `last_seen` port yang ada selalu baru.
- **Notes**: produksi — ke-12 port basi tertandai dalam dua siklus poll; switch 4 port kini 4 SFP; 0 kejadian
  terbuka tersisa di port tak dipantau. Penutupan kejadian dihitung di PHP (bukan `TIMESTAMPDIFF`) supaya
  portabel. Test baru `tests/Feature/VanishedPortsTest.php` (4); suite 52 lulus.

## 2026-09-24 — Created: Port "Tidak Dipakai" (keluar dari SLA, alert, dan dashboard)

- **Created**: fitur menandai port tidak dipakai di atas kolom lama `interfaces.is_monitored` (bawaan 1,
  sebelumnya hanya dibaca peta). Tabel baru `interface_monitoring_changes` (riwayat: siapa, kapan,
  alasan). `InterfaceMonitoringController`: `POST /api/interfaces/monitoring` (khusus admin — middleware
  `legacy.role:admin` + cek ulang `UserState`, maks 500 port, alasan ≤ 255) dan
  `GET /api/interfaces/monitoring/history`. Mematikan pantauan menutup kejadian SLA yang masih terbuka
  pada saat itu; perubahan ke status yang sama tidak dicatat dua kali.
- **Changed (poller)**: `InterfaceDiscovery` membaca `is_monitored` dari query `prevRows` yang sudah ada
  (tanpa query tambahan). Port tidak dipakai: tanpa alert, tanpa kejadian SLA, tanpa `interface_stats` /
  `interface_traffic_stats` (rollup ikut bersih), state alert-nya dibuang supaya saat dipantau lagi
  tidak memicu alert transisi lama; baris `interfaces` (status, RX/TX, last_seen) tetap diperbarui.
  `optical:degradation` mengecualikan port tidak dipakai (rollup harian lamanya masih ada).
- **Changed (laporan & API)**: ringkasan SLA + ekspor CSV/PDF mengecualikan port tidak dipakai kecuali
  `include_unmonitored=1`; riwayat per-interface tetap bisa dibuka. Endpoint baru `GET /api/sla/candidates`
  (port dipantau yang down tanpa henti > 7 hari, `SlaController::UNUSED_CANDIDATE_DAYS`). KPI dashboard
  web & API v1, daftar interface web (`monitored=monitored|all|unmonitored`, `meta.unmonitored_total`,
  field `is_monitored`, alasan, `active_again`), monitoring web & API v1, dan `/api/v1/interfaces`
  menyembunyikan port tidak dipakai secara bawaan (bentuk respons lama tetap, hanya menambah field).
- **Created (UI)**: `public/assets/js/port-monitoring.js` — modal alasan milik tema (dipasang di `<body>`,
  Escape/klik luar menutup, tanpa `window.confirm`), lencana "Tidak dipakai" / "Aktif kembali".
  Interfaces: filter "Pantau", tombol per port untuk admin, baris redup + alasan, jumlah yang
  disembunyikan. Monitoring & Devices: kotak centang "Tampilkan port tidak dipakai". SLA: kotak centang
  "Sertakan port tidak dipakai" + panel "Kandidat tidak dipakai" (per port / tandai semua; kartu di HP).
- **Fixed (sempat terjadi)**: saat penyuntingan, komentar SQL berkutip ganda di `CheckOpticalDegradation`
  memutus string PHP beberapa detik; langsung dibetulkan, tidak ada parse error tercatat di log dan
  polling tetap berjalan. Kotak centang baru sempat melebarkan halaman Monitoring di HP (gaya input
  global tema) — kini `.pm-toggle` memulihkan ukuran kotak centang. Tombol per port di Interfaces sempat
  ikut membuka modal trafik (selektor lama `.if-action-btn`) — dikecualikan.
- **Notes**: test baru `tests/Feature/InterfaceMonitoringTest.php` (8): admin-only, tutup kejadian +
  riwayat, anti-duplikat, validasi, kandidat, daftar interface web, monitoring web, monitoring API v1.
  Tidak teruji otomatis: jalur poller (fungsi SNMP global) dan ringkasan SLA (fungsi khusus MySQL) —
  diperiksa lewat review kode. Suite 48 lulus. Migrasi dijalankan di produksi; route/view/config cache
  diperbarui. Diverifikasi visual (1440/1280/390, tanpa galat konsol, tanpa gulir samping) dengan akun
  admin sementara acak yang sudah dihapus; **tidak ada port asli yang ditandai** (is_monitored=0: 0,
  riwayat: 0).

## 2026-09-24 — Fixed: SLA Report Menghitung Interface yang Sudah UP sebagai Down

- **Fixed (akar masalah)**: `interface_down_events` hanya ditutup saat poller melihat transisi
  "tadinya down → sekarang up" di state alert. Bila state itu hilang selagi link down (mis. gangguan
  massal 8 Sep 03:41–03:46), transisinya tak pernah terlihat, kejadian terbuka selamanya, dan SLA
  (`COALESCE(up_at, NOW())`) terus menghitung port itu down sampai detik ini. Terukur: 44 kejadian
  terbuka, **26 milik interface yang jelas UP** (oper 1, RX normal), sebagian terbuka sejak 29 Jul.
- **Fixed (poller)**: `InterfaceDiscovery` kini memuat kejadian terbuka per perangkat sekali per siklus
  (`loadOpenDownEvents`) dan menutupnya begitu link terlihat up, tanpa menunggu transisi.
- **Created**: `php artisan sla:reconcile [--apply] [--ids=snapshot.json]` — memperbaiki riwayat dengan
  waktu pulih SEBENARNYA: alert `interface_up` pertama → sampel RX mentah pertama di atas ambang /
  rollup per jam mulai JAM BERIKUTNYA (jam tempat link turun masih berisi sampel bagus sebelum
  gangguan), diambil yang paling awal. Port yang masih down dibiarkan terbuka; perangkat yang sudah
  dihapus ditutup di sampel terakhirnya; gangguan yang berlangsung SETELAH pemulihan yang terlewat
  dibuka ulang dari sampel buruk pertama. Dry run bawaan.
- **Changed (data produksi)**: dijalankan pada snapshot 44 kejadian
  (`storage/app/sla-open-events-20260924.json`, termasuk yang sempat ditutup poller dengan "sekarang"):
  27 ditutup dengan waktu pulih dari riwayat (21 data mentah, 6 rollup — mis. port 10GE perangkat 50
  ternyata down ±21 menit, bukan 57 hari), 5 milik perangkat terhapus ditutup, 12 tetap terbuka
  (memang down), 1 gangguan yang sedang berjalan sejak 19 Sep 23:37 dibuka ulang. Sesudahnya: 13
  kejadian terbuka, **0 milik interface yang UP**.
- **Notes**: test baru `tests/Feature/SlaReconcileTest.php` (6).

## 2026-09-24 — Fixed: Tab Vendor & Optik bertabrakan dan terpotong

- **Fixed**: kartu "Profil OID Optik" menabrak kartu "Vendor per Perangkat" — tab ini satu-satunya
  yang menumpuk beberapa kartu, dan bayangan offset tema menimpa kartu berikutnya. Kini ada jarak
  28 px, padding untuk teks bantuan & tabel, dan lebar minimum kolom nama.
- **Fixed**: di HP tabel terpotong (kolom Driver & tombol aksi tak terlihat). Di bawah 720 px tiap
  baris kini jadi kartu berlabel (`data-label` diisi `optical-profiles.js`).
- **Changed**: tabel profil dipadatkan dari 6 ke 4 kolom (Profil + aturan cocok · OID RX/TX + satuan ·
  Status · Aksi) supaya OID terbaca utuh di 1280 px; kolom aksi tak lagi dipaksa satu baris.
- **Fixed**: modal "Profil baru" tertutup footer dan navigasi bawah HP — modal berada di dalam wadah
  konten yang punya konteks tumpukan sendiri, jadi z-index 2000-nya kalah. Modal kini dipindah ke
  `<body>` saat dibuka.
- **Notes**: diverifikasi dengan screenshot 1440/1280/390 px (tabel pas: lebar gulir = lebar wadah)
  memakai akun admin sementara acak yang sudah dihapus lagi. Test 34 lulus.

## 2026-09-24 — Created: Dukungan Optik Multi-Vendor (driver, deteksi vendor, profil OID)

Sebelumnya daya optik hanya terbaca untuk MikroTik (walk MIKROTIK-MIB ke SEMUA perangkat) dan
Huawei (hanya bila **nama** perangkat memuat huawei/quidway/cloudengine). Switch vendor lain
tetap terpantau status & trafiknya lewat IF-MIB, tetapi DDM-nya kosong.

- **Created — lapisan driver `app/Services/Optical/`**: `OpticalDriver` (kontrak), `MikrotikDriver`
  dan `HuaweiDriver` (logika lama dipindahkan apa adanya), `EntitySensorDriver` (ENTITY-SENSOR-MIB
  RFC 3433 + CISCO-ENTITY-SENSOR-MIB, sensor `watts(6)` → dBm; berbasis standar, **belum diverifikasi
  di perangkat BMKV**), `CustomProfileDriver` (profil OID admin), `VendorDetector` (sysObjectID +
  sysDescr, cache 7 hari), `OpticalDriverResolver`, `SnmpSession`, `EntityAliasMap`, `OpticalUnits`.
- **Changed — `InterfaceDiscovery`**: blok MikroTik + Huawei (±70 baris) diganti satu panggilan
  resolver; bentuk peta hasil tetap `[ifName => [rx, tx]]` sehingga penyimpanan statistik & alert
  tidak berubah. Metode Huawei privat dipindah ke `HuaweiDriver`.
- **Notes — keputusan desain**: vendor tak terdeteksi → perilaku lama persis (tidak ada perangkat
  yang kehilangan bacaan); profil aktif hanya dipakai bila lulus uji dan cocok sysObjectID/sysDescr;
  kegagalan resolver/driver hanya `Log::warning`, polling jalan terus. Enumerasi tipe sensor di
  RFC 3433 dan CISCO-ENTITY-SENSOR-MIB rev. 2020-09-22 **tidak memuat dBm**, jadi hanya `watts` yang
  didukung — tidak ditebak.
- **Created — migrasi (tabel baru saja)**: `optical_device_vendors` (hasil deteksi + override driver)
  dan `optical_profiles` + 2 templat **nonaktif**: Juniper JUNIPER-DOM-MIB dan H3C/HPE Comware
  HH3C-TRANSCEIVER-INFO-MIB. OID, indeks (ifIndex) dan satuan (0,01 dBm) dicocokkan dengan teks MIB
  resmi. Migrasi sudah dijalankan di produksi.
- **Created — Pengaturan → tab Vendor & Optik (admin)** + `OpticalProfilesController` + rute
  `/api/optical/*` (`legacy.role:admin` + cek ulang di controller): vendor per perangkat, override
  driver, deteksi ulang, CRUD profil, **Uji** (pratinjau mentah → dBm) sebelum **Aktifkan**, sunting =
  hasil uji batal. Host/community selalu dari `snmp_devices` (tanpa SSRF); OID wajib numerik ≥ 9
  komponen; Uji & deteksi ulang `throttle:optical-snmp` 6/menit; semua nilai di-`escHtml`; hapus
  memakai konfirmasi dua klik, bukan `window.confirm()`.
- **Created — `php artisan optical:probe`**: baca optik tanpa menulis statistik/alert, mode
  `legacy`/`driver` + `--compare`.
- **Notes — paritas (produksi, 23 perangkat: 20 MikroTik + 3 Huawei hasil deteksi sysObjectID)**:
  baseline diambil dengan kode ASLI sebelum refactor, lalu dibandingkan dengan driver:
  250 interface optik di kedua jalur, **0 hilang, 0 tambahan**; 135 identik, 113 selisih ≤ 0,5 dB,
  2 selisih lebih besar. Kedua port itu dibaca ulang bergantian lama/baru dalam hitungan detik:
  **nilainya identik**, selisih berasal dari redaman yang bergerak antar menit (satu port bergeser
  -11,8…-12,6 dBm). Setelah poller dialihkan: siklus berikutnya memperbarui 268 port SFP di 23
  perangkat, 141 bacaan hidup semuanya cocok dengan probe, tanpa peringatan fallback/galat.
- **Verifikasi**: `bash scripts/test.sh` 34 lulus (20 baru: konversi satuan, klasifikasi vendor,
  parsing tiap driver dengan fixture rekaan, pencocokan profil, validasi OID/SSRF, akses admin,
  aturan aktivasi & override, cache detektor). `route:cache` + `view:cache`; `storage`/`bootstrap/cache`
  dikembalikan ke www-data. UI tab baru belum dilihat di browser (tidak ada akun uji aktif) —
  diverifikasi lewat test API dan kompilasi view.

## 2026-09-24 — Docs: README ditulis ulang dalam bahasa Inggris

- **Changed**: `README.md` ditulis ulang dalam bahasa Inggris untuk repo publik — ringkasan, tabel fitur
  (monitoring optik, status interface, deteksi degradasi, SLA + ekspor CSV/PDF, peta, alert, discovery,
  peran, aplikasi Android), stack, instalasi, tabel jadwal scheduler (`poll:interfaces`, `stats:rollup`,
  `stats:prune`, `optical:degradation`), push, build APK dengan kunci sendiri, tabel API v1, test aman,
  bagian keamanan + pelaporan kerentanan lewat GitHub Security Advisories, tata letak projek.
- **Fixed**: README lama menyebut lisensi **MIT**, padahal `LICENSE` berisi **CC BY-NC 4.0** — kini
  disamakan. Versi yang usang (Laravel 11, app 2.0.0+2) dan perintah `php artisan test` polos dihapus.
- **Changed**: `public/assets/img/loginpage.png` diganti tangkapan layar halaman login yang sekarang
  (halaman publik, tanpa data). Tidak ada host internal, IP, atau kredensial di README.
- **Changed**: `CLAUDE.md` — stack kini Laravel 12 + Blade/vanilla JS (dulu tertulis Laravel 11 + Bootstrap),
  peringatan bahwa repo publik, dan lokasi kunci rilis APK (`/root/.kv-keystores/`, di luar repo).

## 2026-09-24 — Tindak Lanjut Keamanan: CSP Ditegakkan, Verifikasi Browser, Cache Root

- **Changed (server, di luar repo)**: CSP di `snippets/netpulse-headers.conf` diubah dari
  `Content-Security-Policy-Report-Only` menjadi **`Content-Security-Policy`** setelah 8 halaman ber-login
  (dashboard, devices, interfaces, map, monitoring, settings, sla, users) × desktop/HP diperiksa: 0 galat
  konsol, 0 pelanggaran, ubin peta tetap tampil. Cadangan snippet Report-Only:
  `/root/netpulse-headers.conf.bak-reportonly`.
- **Fixed**: setelah upgrade Laravel 12, artisan yang dijalankan sebagai root meninggalkan 5 folder
  `storage/framework/cache/data/*` milik root → `fopen … Permission denied` di `laravel.log` (13:05 WIB).
  `chown -R www-data:www-data storage bootstrap/cache` + `cache:clear`; tidak ada galat sesudahnya.
- **Notes — verifikasi browser**: memakai akun admin sementara bernama acak (`kvaudit_*`, sandi acak yang
  tidak pernah ditampilkan), dibuat khusus untuk pemeriksaan ini lalu **dihapus** beserta token API-nya
  (sisa 0). Diperiksa sebelum upgrade, sesudah upgrade Laravel 12, dan sesudah CSP ditegakkan.
- **Notes**: `laravel.log` berisi peringatan `FCM push failed … NotRegistered` untuk token HP lama — wajar
  selama staf berpindah ke APK 2.1.2+8 (kunci baru = pasang ulang = token baru). Token mati itu belum
  dibersihkan otomatis; layak ditambahkan penghapusan token saat FCM membalas `NotRegistered`.

## 2026-09-24 — Changed: upgrade Laravel 11.56 → 12.69 (cabang 11 sudah EOL)

- **Changed**: `composer.json` — `laravel/framework` ^12.0 (terpasang 12.69.2), `laravel/tinker` ^2.10.1,
  `laravel/pail` ^1.2.2, `nunomaduro/collision` ^8.6, `phpunit/phpunit` ^11.5.3. `composer audit` kini
  bersih; dua advisori framework 11 (CRLF aturan `email`, path confusion URL bertanda tangan) gugur.
- **Notes — tidak ada perubahan kode aplikasi**: Carbon sudah 3.x sejak Laravel 11, disk `local` sudah
  eksplisit ke `storage/app/private`, tidak ada `HasUuids`, validasi gambar push memakai daftar ekstensi
  sendiri, dan `Schema::hasTable/getColumnListing` tidak terdampak di MariaDB satu skema.
- **Notes — cara menerapkan**: dilatih dulu di git worktree terpisah (sqlite, tanpa `.env` produksi) —
  `bash scripts/test.sh` 14 lulus, 78 rute, `php -l` bersih — baru di-fast-forward ke checkout produksi,
  `composer install`, lalu `config:cache` + `route:cache` + `view:cache` dan kepemilikan
  `storage`/`bootstrap/cache` dikembalikan ke `www-data`. Cadangan untuk rollback di `/root/`:
  `netpulse-composer.{json,lock}.bak-l11-20260924` dan `netpulse-vendor-backup-l11-20260924.tgz`.
- **Verifikasi**: `/login` & `/healthz` 200; `php artisan about` → 12.69.2, config/rute/view CACHED;
  `schedule:list` utuh dan detak `netpulse-schedule` terus berjalan setelah upgrade; tidak ada galat baru
  di `laravel.log`; 8 halaman ber-login × desktop/HP dibuka dengan akun uji sementara → semua 200,
  0 galat konsol, 0 pelanggaran CSP (sama dengan sebelum upgrade).

## 2026-09-24 — Created: Netpulse Mobile 2.1.2+8 ditandatangani kunci rilis sendiri

- **Changed (penandatanganan)**: build rilis dulu memakai **kunci debug** server ini
  (`signingConfig = debug`). Kini `mobile/android/app/build.gradle.kts` membaca kunci rilis dari berkas
  properti di LUAR repo — path dari env `NETPULSE_KEY_PROPERTIES` (disetel `bin/build-apk.sh`) atau
  `mobile/android/key.properties` (di-.gitignore). Build rilis **gagal keras** bila berkas itu tidak ada;
  tidak ada lagi jatuh diam-diam ke kunci debug. Keystore & kata sandinya tidak pernah masuk git (repo publik).
- **Created (server, di luar repo)**: keystore rilis RSA 4096 (alias `netpulse`, berlaku 10.000 hari) di
  direktori root 700 / berkas 600, dan ikut **cadangan terenkripsi harian** `kv-backup-all.sh` bagian 3b
  (daftar berkas rahasia GPG AES256; sudah diuji enkripsi → dekripsi). Kunci ini tak tergantikan: APK
  bertanda tangan lain tidak bisa meng-update aplikasi yang sudah terpasang.
- **Changed**: `pubspec.yaml` 2.1.1+7 → **2.1.2+8** (membawa tambalan keamanan mobile: token di secure
  storage, cleartext mati di rilis, base URL terkunci). Dibangun `bash bin/build-apk.sh` (split-per-abi,
  arm64 20,9 MB + arm32 18,5 MB) ke `public/downloads/`; `apksigner` memastikan kedua APK memakai
  sertifikat rilis baru (SHA-256 `a67c2cfe…3630`), bukan sertifikat debug.
- **Notes — wajib pasang ulang sekali**: karena tanda tangan berubah, Android menolak memperbarui
  instalasi lama. Pengguna harus **menghapus aplikasi lama lalu memasang APK baru** dari tombol
  "Download APK"; setelah itu update berikutnya normal. Tidak ada endpoint/versi yang diiklankan server
  (`/download/app` selalu menyajikan berkas terbaru), jadi tidak ada config yang diubah.

## 2026-09-24 — Fixed: Temuan Review Keamanan setelah Repo Dijadikan Publik

Kode kini bisa dibaca siapa pun, jadi celah yang tadinya "tersembunyi" diperlakukan sebagai
terbuka. Tidak ada Kritis/Tinggi di kode; yang ditambal:

- **Fixed (S1, stored XSS)**: nilai dari server/perangkat — nama perangkat, IP, `ifName`/`ifAlias`
  (deskripsi port bisa ditulis siapa pun yang punya akses switch), pesan error — disisipkan ke
  `innerHTML`/template literal tanpa escape di `map.js`, `monitoring.js`, `devices.js`, `users.js`,
  toast `script.js`, dan tooltip Leaflet (string tooltip = HTML). Helper global `escHtml()` di
  `script.js` (dimuat layout sebelum skrip halaman) kini membungkus setiap nilai itu.
  `onclick='editDevice(${JSON.stringify(d)})'` & `editUser(...)`/`deleteUser(..., 'username')`
  diganti `data-*` + satu event listener (JSON/kutip di atribut membuka celah dan pecah oleh `'`).
  **Log keamanan di Pengaturan** juga dirender mentah: username yang diketik di form login ikut
  tercatat, jadi siapa pun dari internet bisa menyisipkan HTML ke halaman admin lewat login
  gagal — kini di-escape.
- **Fixed (S3)**: `POST /api/v1/push/test` mengirim ke token FCM dari body → siapa pun (termasuk
  viewer) bisa mengirim notifikasi bebas ke HP orang lain. Kini hanya ke token milik pemanggil;
  parameter `token` diabaikan (aplikasi memang tidak mengirimnya).
- **Fixed (S2)**: disk `local` `'serve' => false` — rute publik `GET storage/{path}` hilang (tak
  ada kode yang memakainya; gambar push lewat disk `public`/symlink). Laravel 11 sudah EOL:
  upgrade ke 12 dicatat sebagai pekerjaan terpisah.
- **Fixed (R1)**: `device-token` tidak lagi mengalihkan token FCM milik user lain yang masih
  punya sesi API aktif (409). Token boleh pindah bila pemilik lama sudah logout di semua
  perangkat (HP bersama). Logout API kini menerima `fcm_token` dan melepasnya; aplikasi mengirimnya.
- **Fixed (R2)**: ganti kata sandi, peran, atau status aktif — dan hapus user — mencabut semua
  token API & token push user itu. Sesi web sudah ikut lewat `UserState`.
- **Fixed (R3)**: viewer mendapat data dummy di `/api/v1/interfaces` dan `traffic-history`
  (`ViewerDummyData::apiInterfaces/apiTrafficHistory`), sama seperti halaman web.
- **Changed (R4)**: `discover_interfaces` & `huawei_discover_optics` kini ber-`legacy.role:admin,
  technician,viewer` — technician memang boleh discovery (matriks peran di docs), viewer dapat
  dummy; peran lain/tak dikenal ditolak.
- **Fixed (R5)**: kata sandi dicek SEBELUM status aktif (web & API) — "akun nonaktif" hanya
  diungkap kepada pemegang kata sandi benar; username tak dikenal menjalankan `Hash::check`
  tiruan (waktu respons setara). Limiter login kedua: 20/menit per IP (anti password spraying),
  di samping 5/menit per username+IP.
- **Fixed (R8)**: ekspor CSV SLA menetralkan sel berawalan `= + - @ \t \r` (injeksi formula).
- **Fixed (R10)**: peran user wajib `admin|technician|viewer`, kata sandi minimal 8 karakter.
- **Changed (R7)**: `LOG_LEVEL=warning` (dari `debug`); `.env` tetap `640 root:www-data`;
  `config:cache` + `route:cache` dijalankan (`routes-v7.php` dikembalikan ke `www-data 664`).
- **Changed (R9, aplikasi)**: `usesCleartextTraffic="false"` di rilis (debug tetap boleh lewat
  manifest debug); token Bearer pindah ke `flutter_secure_storage` (migrasi otomatis dari
  SharedPreferences, lalu dihapus dari sana); API Base URL hanya bisa diubah di build debug dan
  nilai tersimpan diabaikan di rilis. **Perlu rilis APK baru (naikkan dari 2.1.1+7).**
- **Changed**: `.gitignore` + `*.jks`, `*.keystore`, `*.p12`, `key.properties` (APK rilis masih
  ditandatangani kunci debug — penyiapan keystore rilis menunggu).
- **Fixed (test)**: migrasi rollup `2026_06_15_000001/000002` memakai nama indeks yang sama untuk
  dua tabel — sah di MariaDB (per tabel), bentrok di SQLite (per database). Awalan nama tabel kini
  dipasang **hanya di SQLite**; skema MariaDB produksi & instalasi baru tidak berubah. Test
  ber-`RefreshDatabase` jadi bisa dipakai. Test juga tidak lagi menulis ke `laravel.log`
  produksi (`LOG_CHANNEL=null` di `phpunit.xml` + `scripts/test.sh`).

### Verifikasi

- `bash scripts/test.sh`: **14 lulus** (57 assertion) — 12 test baru di
  `tests/Feature/SecurityHardeningTest.php` (S3, R1, logout, R2, R10, R3, R4, R5, limiter per IP,
  R8). Dua di antaranya dibuktikan gagal terhadap kode lama. Tabel `users` produksi berskema lama
  (username/role/is_active) — kolomnya ditambahkan di `setUp` test.
- `node --check` semua JS yang diubah; `escHtml` diuji dengan payload `<img onerror>`.
- `flutter analyze lib`: bersih. APK tidak dibangun.
- Situs: `/login` 200, `/healthz` 200, `/storage/*` kini 404, `script.js` baru tersaji.
- Nginx (HSTS, CSP, `client_max_body_size`) **tidak** diubah — usulan ada di laporan sesi.
- **Changed (repo publik)**: 6 tangkapan layar lama di `public/assets/img/` dihapus (dashboard, map, monitoring,
  olt, seting-tema, setting-logs) — tidak dirujuk kode maupun README, dan sebagian memuat data operasional
  asli (lokasi node di peta, daftar MAC ONU). `loginpage.png` (dipakai README) dipertahankan.
- **Changed (server, di luar repo)**: nginx vhost memakai snippet baru `snippets/netpulse-headers.conf`
  (header bersama `kv-security-headers.conf` + HSTS + `X-Frame-Options` + **CSP mode Report-Only**) di
  server block DAN kedua location `.apk` (add_header di location dulu menghapus header warisan);
  `client_max_body_size` 64M → 8M. Cadangan vhost lama: `/root/netpulse.kusumavision.net.bak-20260924`.
  CSP dijadikan penegak (ganti nama header) setelah konsol browser bersih dari pelanggaran.

## 2026-09-24 — Fixed: test PHP bisa mengenai MariaDB produksi → `scripts/test.sh`

- **Fixed**: `php artisan test` di checkout ini resolve ke MariaDB `netpulse` produksi — config cache
  (`bootstrap/cache/config.php`) aktif dan menang atas `<env>` phpunit, dan env sqlite di `phpunit.xml`
  bahkan dikomentari. Test ber-`RefreshDatabase` akan menjalankan `migrate:fresh` di produksi.
- **Created**: `scripts/test.sh` — pola yang sama dengan kelima app KusumaVision: `APP_CONFIG_CACHE` /
  `APP_ROUTES_CACHE` / `APP_EVENTS_CACHE` dialihkan ke path tak-ada, `FIREBASE_SERVICE_ACCOUNT_JSON`
  dialihkan (QUEUE sync bisa mengirim push sungguhan), lalu probe koneksi harus `sqlite|:memory:` atau
  skrip abort. `composer test` memanggil skrip ini.
- **Changed**: `phpunit.xml` — env sqlite `:memory:` dihidupkan + pengalihan cache & FCM yang sama
  (lapis kedua, supaya runner IDE ikut aman).
- **Notes**: suite sekarang 2 test (`ExampleTest`), lulus lewat skrip. **Migrasi belum kompatibel
  sqlite**: `2026_06_15_000001_create_interface_stats_rollup_tables.php` memakai nama indeks
  `uniq_dev_if_bucket` di lebih dari satu tabel — sah di MariaDB (nama indeks per tabel), bentrok di
  sqlite (global). Test ber-`RefreshDatabase` baru bisa dipakai setelah nama indeks itu dibuat unik
  per tabel lewat migrasi baru; migrasi lama tidak disentuh.

## 2026-09-18 — Fixed: Beranda kosong di 2.1.0, kartu dempet, APK 56 MB → rilis 2.1.1+7 split-per-abi

Umpan balik user dari HP setelah memasang 2.1.0+6: Beranda hanya menampilkan hero dan dua ubin
KPI (kadang kosong sama sekali), kartu di Monitoring/Akun/Alert saling menempel, dan APK 56 MB
padahal Billing/NMS ±20 MB.

- **Fixed — `home_screen.dart` (`_KpiGrid`)**: dua `Row(crossAxisAlignment: stretch)` berada di
  dalam `ListView` yang tingginya tak terbatas, sehingga `stretch` memaksa tinggi tak hingga →
  layout gagal dan **seluruh sisa halaman tidak digambar** (di rilis tidak ada tanda error,
  hanya kosong). Kedua baris dibungkus `IntrinsicHeight`.
- **Fixed — `app_theme.dart` (`cardTheme.margin`)**: saya menolkan margin kartu saat membangun
  tema baru, padahal layar lama menumpuk `Card` tanpa `SizedBox` dan mengandalkan margin tema.
  Kini `EdgeInsets.only(bottom: 12)`; jarak samping tetap dari padding ListView (16).
- **Changed — `account_screen.dart`**: teks "Versi saat ini" yang dulu hardcode `v2.0.3 (Build 5)`
  kini dibaca dari `PackageInfo` (versi + build number aktual).
- **Changed — `bin/build-apk.sh`**: `flutter build apk --release --split-per-abi
  --target-platform android-arm,android-arm64`, pola yang sama dengan Billing/NMS. APK universal
  lama membawa `lib/x86_64` (18,9 MB), `lib/arm64-v8a` (17,5 MB), dan `lib/armeabi-v7a` (15,2 MB)
  sekaligus — x86_64 tidak dipakai HP mana pun. Hasil: `netpulse.apk` (arm64) **20,8 MB** dan
  `netpulse-arm32.apk` **18,4 MB** untuk HP 32-bit lama. Skrip menghapus APK lama, menyalin
  keduanya, dan `chown www-data`.
- **Notes — versionCode**: `--split-per-abi` menambahkan offset ABI ke versionCode (arm32 =
  1000+N, arm64 = 2000+N). `pubspec` 2.1.1+7 → arm64 terpasang sebagai versionCode **2007**;
  ini normal (Billing sama) dan tetap dianggap pembaruan dari 6.
- **Notes — verifikasi**: `flutter analyze` bersih; `/download/app`, `/downloads/netpulse.apk`,
  dan `/downloads/netpulse-arm32.apk` menjawab HTTP 200. Uji visual di HP menunggu user.

---

## 2026-09-18 — Fixed: Perangkat down tetap tampil "online" di web & peta sampai Test SNMP manual

- **Gejala** (laporan user): Telegram dan APK sudah menerima "DEVICE DOWN", tetapi halaman
  Devices dan Map di web masih menampilkan perangkat online; status baru berubah setelah tombol
  **Test SNMP** ditekan pada perangkat itu.
- **Akar masalah**: poller `poll:interfaces` (`InterfaceDiscovery::discover`) hanya mengirim
  alert dan menyimpan `storage/app/alert_state/<id>.json`; ia **tidak pernah menulis**
  `snmp_devices.last_status`. Satu-satunya penulis kolom itu adalah
  `DevicesApiController::testSnmp()`. Padahal `public/assets/js/devices.js:122-125`,
  `MapApiController`, dan `Api/V1/MapController` (peta di APK) semuanya membaca `last_status`.
  Kolom `last_monitor_status`/`last_monitor_time` bahkan tidak pernah diisi (semua `unknown`/NULL).
- **Fixed — `app/Services/InterfaceDiscovery.php`**: helper baru `markDeviceStatus(id, up, error)`
  menulis `last_status` (OK/FAILED), `last_error`, `last_monitor_status` (up/down), dan
  `last_monitor_time` = now(). Dipanggil di tiga titik: ifIndex gagal → FAILED; ifName gagal →
  FAILED; keduanya terbaca → OK. Berlaku untuk jalur CLI (cron) maupun tombol Discover di web.
  Gagal tulis hanya dicatat ke log, tidak menghentikan polling.
- **Notes — verifikasi**: `poll:interfaces --device=23` sebagai `www-data` → `OK / up /
  15:26:55`. Jalur down diuji dengan baris perangkat sementara `ZZ-UJI-DOWN-SEMENTARA`
  (IP 192.0.2.1, `is_active=0`) → `FAILED / down / "SNMP tidak menjawab (IF-MIB ifIndex) saat
  polling"`, tanpa alert terkirim (state sebelumnya tidak dikenal), lalu baris dan berkas
  state-nya dihapus. Siklus cron berikutnya mengisi `last_monitor_time` untuk seluruh perangkat
  aktif.
- **Notes**: alert transisi down/up tetap bertumpu pada berkas state per perangkat; yang
  berubah hanya tabel kini ikut mencerminkannya, sehingga Dashboard web/mobile (hitungan
  `last_status='FAILED'`), Devices, dan kedua peta akurat tanpa campur tangan manual.

---

## 2026-09-18 — Changed: Desain ulang Netpulse Mobile v2.1.0+6 (token seragam, widget bersama, riwayat 24 jam)

Latar: audit 4 aplikasi mobile ekosistem menemukan Netpulse satu-satunya tanpa lapisan
sistem desain — 32 varian padding, 11 nilai radius, 98 pemakaian warna hardcode (28 warna
unik, lima hijau dan empat merah untuk arti yang sama), dan **ambang RX yang berbeda antar
layar** (beranda −25, monitoring −35, peta/interface −40). Arah yang disepakati user:
permukaan slate netral gaya Ubiquiti, teal sebagai satu-satunya aksen, status hanya lewat
badge + kata. Mockup HTML disetujui sebelum kode disentuh.

- **Created — `mobile/lib/src/theme/tokens.dart`**: `Np` (ThemeExtension) dengan palet terang
  & gelap (bg/surface/surface2/border, ink×3, accent, ok/warn/bad/info masing-masing
  `mark`/`text`/`bg`, track, shadow), `NpRadius` (pill/12/16/24), `NpSpace` (skala 4 pt:
  4·8·12·16·20·24), `NpFont` (Sora/Inter/JetBrainsMono), `NpText.mono()`, `NpMotion`.
  Diakses lewat `context.np`.
- **Created — `mobile/lib/src/theme/status.dart`**: `NetStatus {up, warn, down, none}` dengan
  label Indonesia (Up/Marjinal/Down/Tanpa DDM), `RxThresholds` (dari JSON server, `adopt()`
  menyimpan sebagai `RxThresholds.current`), `statusOf(rx, operStatus)` — **satu aturan** untuk
  semua layar: oper≠1 → down; rx null → tanpa DDM; rx ≤ ambang down → down; rx < ambang
  peringatan → marjinal. Ekstensi `Np.status()` dan `Np.severity()` untuk warna.
- **Changed — `mobile/lib/src/theme/app_theme.dart`** dibangun ulang dari token: ColorScheme,
  skala tipe 7 langkah (KPI Sora 24/800 · judul layar 18/700 · judul kartu 14/700 · nama baris
  Inter 13.5/600 · keterangan 12/500 · badge 11/700 · mono 14/600), tema AppBar/Card/Chip/
  Segmented/NavigationBar/Input/Sheet/Dialog/Switch/Snackbar dari satu sumber. Nama fungsi
  `buildNetpulseTheme()`/`buildNetpulseDarkTheme()` dipertahankan.
- **Changed — `theme_helper.dart`** kini hanya pemetaan ke token (`cardBg→surface`,
  `textMuted→ink2`, dst.) supaya layar yang belum ditulis ulang ikut palet yang sama.
- **Changed — `pubspec.yaml`**: font Sora/Inter/JetBrainsMono dibundel dari `assets/fonts/`
  (disalin dari NMS mobile); dependensi `google_fonts` **dilepas** — sebelumnya font diunduh
  saat aplikasi dibuka, tampilan pertama bisa memakai fallback saat sinyal buruk. Versi
  dinaikkan ke **2.1.0+6**.
- **Created — `mobile/lib/src/ui/widgets/`**: `SectionCard` (+`RowDivider`), `StatTile`,
  `StatusBadge` (+`StatusDot`, konstruktor `.severity`), `HeartbeatBar` (bar riwayat per jam
  ala Uptime Kuma, huruf u/w/d/n), `RxValue` + `RxMeter` (mono berwarna status, meter skala
  tetap −40..−10 dengan garis patokan ambang peringatan; −40/null tampil "—"), `ScreenHeader`
  + `HeaderIconButton` + `BrandMark`, `AsyncState` (loading/error/empty seragam).
- **Changed — `home_screen.dart`** ditulis ulang: header dengan tombol alert (titik merah bila
  ada critical) dan muat ulang; hero kesehatan (ring + kalimat keadaan + badge Aktif/Gagal/
  Nonaktif) menggantikan gradien teal→sky; 4 ubin KPI; "RX terendah" (device · port mono ·
  alias, RxValue dengan meter); "Alert terbaru" dengan garis severity 3 px. Skor kesehatan
  tetap rumus lama (tiap 1 % port bermasalah −3 poin).
- **Changed — `interfaces_screen.dart`** ditulis ulang: kotak cari (debounce 400 ms →
  `q`), chip perangkat horizontal, segmen Semua/Marjinal/Down (`status=all|warn|down`), toggle
  urutan perangkat/RX terendah (`sort=device|rx`), daftar dalam satu kartu dengan baris:
  nama perangkat + badge · port mono + alias · **bar riwayat 24 jam** + RX + kecepatan.
  Kartu per-baris lama dengan tiga kotak RX/TX/Speed dan dua kotak In/Out dihapus (detail tetap
  di layar traffic).
- **Changed — `home_shell.dart`**: label tab Beranda / Monitoring / Interface / Peta / Akun.
- **Changed — sapuan token di layar lain** (`map`, `monitoring`, `interface_traffic`,
  `alerts`, `settings`, `about`, `login`): seluruh warna hardcode diganti token (kini **0** hex
  di `lib/src/ui`); `_rxColor`, `_linkLevel`, `_MetricChip._dotColor` memakai
  `RxThresholds.current` — pita "warning −25..−18" di peta yang terbalik ikut hilang; hero
  gradien monitoring jadi kartu permukaan; seri grafik traffic In/Out memakai aksen/info (bukan
  hijau status); legenda peta "Warning" → "Marjinal"; layar Tentang berbahasa Indonesia.
- **Created — `app/Support/RxThresholds.php`** (sisi Laravel): `global()` membaca
  `settings.alert_rx_warning_low` / `alert_rx_down_threshold` (default −25 / −40, logika sama
  dengan `InterfaceThresholdsController::globalThresholds()`); `history24h(pairs, thresholds)`
  membangun 24 bucket per (device_id, if_index) dari `interface_stats_hourly` (rx_min ≤ down →
  d, rx_avg < warn → w, ada sampel → u, tanpa sampel → n). Terukur **7 ms** untuk 4 interface,
  ~100 ms untuk halaman 25 baris termasuk kueri utama; indeks `idx_dev_if_bucket` sudah ada.
- **Changed — `Api/V1/DashboardController`**: respons membawa `thresholds`; `worst_ports` kini
  **hanya port hidup dengan pembacaan nyata** (`oper_status = 1 AND rx_power > ambang down`) —
  sebelumnya enam teratas selalu slot SFP kosong bernilai −40, sehingga daftar tak berguna.
- **Changed — `Api/V1/InterfacesController::index`**: parameter baru `status=warn` (up tapi RX
  di antara ambang down dan peringatan) dan `sort=rx` (port hidup RX terlemah di atas, port
  down/tanpa DDM di bawah); tiap baris membawa `history_24h`; `meta.thresholds` ditambahkan.
  Tidak ada rute baru → `route:cache` tidak perlu disegarkan.
- **Notes — verifikasi**: `flutter analyze` bersih; `flutter build apk --release` sukses →
  `mobile/build/app/outputs/flutter-apk/app-release.apk` (56,6 MB). Uji controller sebagai admin:
  dashboard 82 ms; `/interfaces?sort=rx` 100 ms (269 port); `status=down` 128 port;
  `status=warn` 0 port saat ini; `q=uplink` 7 hasil dengan riwayat 24 jam terisi.
- **Notes — rilis**: atas permintaan user, `bash bin/build-apk.sh` dijalankan langsung tanpa
  uji HP dulu → `public/downloads/netpulse.apk` kini **2.1.0 (versionCode 6)**, 56,6 MB, milik
  `www-data`. Tombol unduh di topbar/sidebar web sudah tidak membawa teks versi, jadi tidak ada
  badge yang perlu diubah. Layar Monitoring, Peta, Traffic, dan Akun baru disapu warnanya, belum
  ditata ulang ke bahasa widget bersama; 32 varian padding lama di layar-layar itu masih ada.

---

## 2026-09-17 — Created: Endpoint `/healthz` untuk Monitoring Uptime

- **Created — `routes/web.php`: `GET /healthz`** (tanpa auth, bernama `healthz`). Membalas
  `{status, app, database, timestamp}` dengan HTTP **200/503**, bentuk yang sama dengan
  `/healthz` milik SSO, NMS, MikroTik, dan Billing supaya satu pemeriksa kata kunci
  (`"status":"ok"`) berlaku untuk seluruh ekosistem. Disiapkan untuk Uptime Kuma yang akan
  dipasang di `uptime.kusumavision.net`.
- **Notes — hanya MariaDB yang diperiksa, Redis sengaja tidak.** App ini satu-satunya di
  ekosistem yang tidak memakai Redis sama sekali (`QUEUE_CONNECTION=sync`, `CACHE_STORE=file`,
  `SESSION_DRIVER=file`), jadi melaporkan status Redis di sini hanya akan menciptakan sinyal
  palsu. Pemeriksaannya `getPdo()` **plus** `select 1` — membuka koneksi saja belum membuktikan
  server menjawab kueri.
- **Notes — kenapa `/api/v1/ping` yang sudah ada tidak dipakai.** `routes/api.php:17` membalas
  `{ok:true, ts}` **tanpa menyentuh database sama sekali**, jadi ia tetap hijau saat MariaDB
  mati — persis kondisi yang paling perlu terdeteksi. Ia tetap dibiarkan untuk aplikasi mobile.
- **Changed — `use Illuminate\Support\Facades\DB;`** ditambahkan ke daftar impor, mengikuti
  pola `KusumaVisionSSO/routes/web.php:17`. `config/app.php` app ini tidak punya blok `aliases`,
  jadi memakai `DB::` tanpa impor bertumpu pada alias bawaan framework — eksplisit lebih aman.
- **Notes — `php artisan route:cache` WAJIB dijalankan ulang.** `bootstrap/cache/routes-v7.php`
  aktif di produksi; tanpa menyegarkannya rute baru membalas **404**. Berkas cache lama ternyata
  milik `root:root` (jejak artisan yang pernah dijalankan sebagai root); sesudah disegarkan
  sebagai `www-data` kepemilikannya kembali benar.
- **Notes — verifikasi**: `curl https://netpulse.kusumavision.net/healthz` → **HTTP 200 dalam
  0,82 detik**, `{"status":"ok","database":true,"timestamp":"2026-09-17T17:52:48+07:00"}`.
  Timestamp ber-offset `+07:00` sesuai `DB_TIMEZONE`, jadi tidak perlu diterjemahkan lagi oleh
  pembacanya.

## 2026-09-12 — Dokumentasi: Bagian Keamanan di NETPULSE_DOCUMENTATION.md

- **Created — bagian `## Keamanan`** (disisipkan setelah Role Matrix): seluruh pengerasan 10 Sep (NP-1, NP-4, NP-5, NP-6) sebelumnya hanya tercatat di WORKLOG, padahal ia **keadaan yang berlaku** dan sebagian besar mudah dirusak tanpa sengaja saat menambah fitur.
- **Notes — ditulis sebagai aturan kerja, bukan riwayat**. Yang ditekankan: (1) hanya `api/v1/*` yang dikecualikan dari CSRF, rute web `/api/*` wajib `X-CSRF-TOKEN`; (2) **endpoint yang menulis DB tidak boleh `GET`** — `discover_interfaces` & `huawei_discover_optics` sudah dipindah ke POST karena itu; (3) rahasia **selalu** dibaca lewat `Secret::reveal()`, jangan langsung dari kolom; (4) form yang dikosongkan berarti "pertahankan nilai lama"; (5) `route:cache` + `config:cache` wajib diperbarui setelah mengubah rute/config karena produksi memakainya.
- **Notes — satu konsekuensi yang belum pernah ditulis di mana pun**: `UserState` mem-cache role & `is_active` selama 60 detik, jadi **akun yang dinonaktifkan kehilangan sesinya dalam ≤60 detik** tanpa perlu menunggu logout. Itu perilaku yang perlu diketahui admin, bukan detail implementasi.
- **Notes — utang test dicatat terang-terangan**: `phpunit.xml` belum diarahkan ke sqlite, sehingga menjalankan test di server ini berisiko menyasar DB produksi — itu sebabnya verifikasi pengerasan dilakukan manual lewat curl. Ditulis berikut arah perbaikannya (pola `scripts/test.sh` di keempat app Laravel lain), supaya tidak terlupa.
- **Notes — tidak ada perubahan kode**, murni penyelarasan dokumentasi.

---

## 2026-09-10 — Perbaikan Temuan Audit Keamanan (NP-1, NP-4, NP-5, NP-6, deps)
1. **Fixed — NP-6 CSRF & metode aman**: `bootstrap/app.php` pengecualian CSRF kini hanya `api/v1/*` (Bearer, tanpa sesi); rute web `/api/*` ber-sesi wajib `X-CSRF-TOKEN`. `layouts/app.blade.php` menambah `<meta name="csrf-token">` + pembungkus global `fetch`/`XMLHttpRequest` yang menyuntik header untuk request same-origin non-GET (semua JS lama di `public/assets/js/*.js` otomatis tercakup). Logout diubah dari `GET /logout` menjadi form `POST /logout` + `@csrf` (sidebar & mobile header; CSS `.logout-form { display: contents }`). `GET /api/discover_interfaces` & `huawei_discover_optics` (menulis DB) menjadi `POST` — pemanggil `devices.js` & `map.js` disesuaikan; `DiscoverInterfacesController` membaca `device_id` via `input()`.
2. **Fixed — NP-1 throttle login**: limiter `login` 5/menit per `strtolower(username)|ip` (`AppServiceProvider::configureRateLimiting`) dipasang di `POST /login` dan `POST /api/v1/auth/login`; limiter `api` 120/menit per user/token/IP via `$middleware->throttleApi()` untuk grup `routes/api.php`. Kegagalan & pembatasan dicatat ke `storage/logs/security.log` lewat `app/Support/SecurityLog.php` (event `LOGIN_THROTTLED`, `API_LOGIN_FAILED`, `API_LOGIN_SUCCESS`; `AuthController::writeSecurityLog` kini delegasi ke kelas yang sama). Verifikasi: 6× POST `/api/v1/auth/login` salah → ke-6 = 429.
3. **Fixed — NP-5 password plaintext**: command baru `users:hash-plaintext-passwords` (`--dry-run`), idempoten. Dijalankan di produksi: **0 dari 5** baris `users.password` plaintext (semua sudah bcrypt). Fallback `hash_equals($input, $user->password)` dihapus dari `AuthController.php` dan `Api/V1/AuthController.php`.
4. **Fixed — NP-4 kebocoran kredensial**: `GET /api/devices` kini `select` eksplisit tanpa `community`/`snmp_user`/`telnet_*`, hanya flag `community_set`/`snmp_user_set`; `devices.js` menampilkan `••••` dan form edit mengosongkan input (kosong/placeholder = nilai lama dipertahankan, ditangani `DevicesApiController::store`). `SettingsApiController` & `Api/V1/SettingsController`: `bot_token` diredaksi (non-admin `''`, admin placeholder `••••`, plus `bot_token_set`) via `Secret::redactSettings()`; saat simpan placeholder diabaikan dan nilai baru dienkripsi (`Secret::prepareSettingsForSave()`). Enkripsi at-rest `snmp_devices.community` & `settings.bot_token` memakai `app/Support/Secret.php` (`Crypt`, idempoten, `reveal()` fallback plaintext) — migrasi `2026_09_10_000001_encrypt_secrets_and_token_expiry` melebarkan `community` ke `TEXT` dan mengenkripsi 23 device + 1 bot_token. Pembaca (`InterfaceDiscovery` untuk poller/Telegram, `DevicesApiController::testSnmp`, `telegramTest`) memakai `Secret::reveal()`; poller cron tetap menghasilkan data setelah migrasi (`interface_stats` 04:50).
5. **Fixed — NP-6 sisa (token & role)**: `HasApiTokens::createToken()` mengisi `expires_at` (default 90 hari), respons login v1 menyertakan `expires_at`; 22 token lama tanpa `expires_at` diberi 90 hari dari sekarang oleh migrasi; `AuthenticateApiToken` juga menolak akun `is_active=0`. `app/Support/UserState.php` memuat ulang `role` & `is_active` dari DB (cache 60 dtk) — dipakai `EnsureAuthenticated` (sesi diinvalidasi bila akun nonaktif/hilang, role sesi disinkronkan) dan `EnsureRole`; `UsersApiController` mem-flush cache saat update/hapus user.
6. **Changed — konfigurasi & izin**: `.env` `SESSION_SECURE_COOKIE=true` (Set-Cookie kini `Secure`); `chmod 640 storage/app/firebase/service-account.json storage/logs/*.log`. `route:cache` + `config:cache` diperbarui (cache dipakai produksi).
7. **Changed — dependensi**: `composer update --with-all-dependencies` laravel/framework 11.48.0→11.56.1, symfony/* 7.4.4/5→7.4.18, guzzle 7.10.0→7.15.5, commonmark 2.8.0→2.10.1, dompdf 3.1.5→3.1.6 (tanpa naik mayor). `composer audit`: **42 → 3** advisori, sisa semuanya `laravel/framework` yang hanya diperbaiki di 12.x (CRLF injection rule `email` — CVE-2026-48019 & PKSA-3r5d, Temporary Signed URL Path Confusion — PKSA-m5cs; app tidak memakai `signedRoute`/`temporarySignedRoute`; rule `email` hanya di validasi internal). Composer 2.9 memblokir seluruh 11.x karena advisori, jadi `composer.json` menambah `config.audit.block-insecure=false` (wajib agar update dalam `^11` bisa berjalan). `npm run build` (Vite) dijalankan ulang.
8. **Notes**: test otomatis tidak dijalankan (`phpunit.xml` tidak memakai sqlite → berisiko menyasar DB produksi); verifikasi manual via curl: `/login` 200, login→dashboard 200, POST `/api/*` ber-sesi tanpa token 419 / dengan token 400·403 (bukan 419), `GET /logout` 405, `POST /logout` 302→/login, `/api/settings` teknisi `bot_token=''`, `/api/devices` tanpa kolom rahasia, throttle API v1 ke-6 = 429. Tidak ada layanan yang di-restart; belum di-commit.

## 2026-09-10 — Audit Keamanan (baca-saja)
1. **Notes**: Audit keamanan & cakupan role ekosistem (baca-saja, tanpa perubahan kode). Hasil lengkap, bukti `file:baris`, runbook, dan prioritas ada di `/var/www/DOKUMENTASI_EKOSISTEM_KUSUMAVISION.md` §13; koreksi klaim dokumentasi diterapkan di §1.2, §2.3, §3.B, §4.A–§4.G, §6, §10.A dan `DOKUMENTASI_SISTEM_TRIAD.md` §7.
2. **Notes — temuan Netpulse** (kode & server tidak diubah): **Tinggi** tidak ada throttle/lockout pada `POST /login` maupun `POST /api/v1/auth/login` (`throttleApi()` tidak dipanggil di `bootstrap/app.php`); `.env` `644 root:root` world-readable (runbook `chmod 640 root:www-data` di §13.K4, belum dijalankan). **Sedang** `storage/app/firebase/service-account.json` `775` & log `775`/`644`; SNMP community plaintext dan `GET /api/devices` mengembalikan seluruh kolom ke technician; token bot Telegram plaintext dan `GET /api/settings` mengembalikannya ke technician; fallback login menerima password plaintext tersimpan (`AuthController.php:41-45`, perlu cek isi tabel `users`); `SESSION_SECURE_COOKIE` tidak diset; vhost tanpa HSTS. Rendah: CSRF dimatikan untuk `api/*` yang ber-cookie (`GET /logout`, `GET /api/discover_interfaces` menulis DB); token v1 tanpa `expires_at`; role disalin ke sesi saat login. `composer audit`: **42 advisori** (laravel/framework 11.48.0, guzzle 7.10.0, commonmark 2.8.0, symfony/http-kernel — high). `npm audit`: 0.

## 2026-09-07 — Pemulihan Interval Polling 1 Menit & Eliminasi Flapping Alert
1. **Stabilisasi Parameter SNMP Timeout & Retries**:
   - Menyetel timeout SNMP ke **2.0 detik** (`2000000` microsecond) dengan **2 kali retries** (toleransi ~6 detik) pada probe awal `$ifIndex` di `InterfaceDiscovery.php`.
   - Timeout ini memberikan toleransi yang cukup untuk link wireless/lossy (latency 15-30ms) sehingga perangkat online tidak akan pernah salah dideteksi sebagai offline (menghilangkan false-alarm down/up flapping).
   - Pada saat yang sama, jika perangkat benar-benar offline (seperti `SW-BMKV-DAMARWULAN`), probe gagal dalam tepat 6 detik dan langsung me-return status `SKIP` tanpa mencoba 9 pemanggilan walk berikutnya yang membuang waktu 50+ detik.
2. **Kinerja & Hasil Verifikasi**:
   - Waktu polling paralel seluruh 23 perangkat turun dari 67 detik menjadi **7.1 detik**.
   - Jadwal `routes/console.php` dipasang `poll:interfaces --timeout=30` dengan `withoutOverlapping(10)`.
   - **Hasil di Database**: Timestamp `interface_stats` terverifikasi masuk **setiap 60-61 detik (1 menit persis)** tanpa pernah ter-skip lagi, dan log alert tetap stabil tanpa duplikasi alert palsu.

---

## 2026-09-07 — Perataan Dark Mode Mobile & Perampingan Kartu Filter Peta
1. **Perataan Tema Gelap Menyeluruh (Fixed)**:
   - Melengkapi `buildNetpulseDarkTheme()` (`navigationBar`, `bottomSheet`, `dialog`, `switch`, `dropdown`, divider) + helper baru `theme_helper.dart` (`cardBg/cardBorder/subtleBg/textPrimary/textMuted/chartGrid`).
   - `HomeScreen`: teks grow menjadi adaptif (`textPrimary/textMuted/textFaint`), skeleton mengikuti tema.
   - `InterfaceTrafficScreen`: kartu header/chart/summary, chip range, grid & label chart mengikuti tema.
   - `MonitoringScreen`: grid & label chart, chip range mengikuti tema.
   - `InterfacesScreen`: teks counter + empty-state mengikuti tema.
   - `MapScreen`: background mengikuti tema.
2. **Perampingan Kartu Line Filter Peta (Fixed)**:
   - `_TopPanel` diubah dari kartu besar bertumpuk (judul + Wrap 2 baris) menjadi satu baris horizontal scrollable yang ramping (ikon filter + 4 chip compact), tinggi kartu turun drastis sehingga peta terlihat lega.
   - `_LegendCard` diubah menjadi pill ramping satu baris yang bisa di-tap untuk expand/collapse legenda, margin overlay diperketat (10px).
3. **Rilis APK v2.0.3 (Build 5)**:
   - Bump `mobile/pubspec.yaml` ke `2.0.3+5`, teks versi di `account_screen.dart` diselaraskan.
   - Build release sukses via `bin/build-apk.sh`, `aapt` terverifikasi `versionCode=5 versionName=2.0.3`, tersedia di `/download/app`.

---

## 2026-09-07 — Endpoint Unduh APK Kanonis Tunggal & Otomasi Replace
1. **Endpoint Unduh Dinamis `/download/app`**:
   - Menyediakan rute download dinamis di `routes/web.php` (`/download/app`) yang menyajikan binary APK langsung dari backend dengan header `Content-Disposition: attachment; filename="netpulse.apk"` dan `Cache-Control: no-cache, no-store`.
   - Mengatasi issue caching Cloudflare Edge: Cloudflare memperlakukan rute ini sebagai `cf-cache-status: DYNAMIC`, sehingga pengguna selalu mendapatkan APK versi paling baru yang ada di disk tanpa perlu mengganti nama file atau URL unduhan.
2. **Automasi Script `bin/build-apk.sh`**:
   - Memperbarui skrip `bin/build-apk.sh`: setiap build APK selesai, skrip otomatis membersihkan APK lama di `public/downloads/` dan menaruh binary baru menggantikan `public/downloads/netpulse.apk` secara atomik, lalu memverifikasi versi dengan `aapt`.
   - Menghubungkan seluruh tombol unduh di web topbar, sidebar nav, dan layar Akun mobile ke endpoint kanonis `/download/app`.

---

## 2026-09-07 — Pembaruan Menyeluruh Dark Mode, Cache-Busting APK v2.0.2
1. **Bypass Cache Cloudflare untuk File APK**:
   - Menambahkan konfigurasi Nginx `location ~* \.apk$` dengan header `Cache-Control: no-cache, no-store, must-revalidate, max-age=0` agar Cloudflare dan browser tidak menyajikan file APK versi lama (`cf-cache-status: BYPASS`).
   - Menyediakan link unduh langsung versi spesifik `/downloads/netpulse-v2.0.2.apk` dan `/downloads/netpulse.apk?v=2.0.2`.
2. **Penyelarasan Tampilan Dark Mode Dashboard & Monitoring**:
   - Memperbarui seluruh komponen card container (`_QuickMetric`, `_ActionPanel`, `_SectionBox`) di `HomeScreen` agar adaptif mengikuti tema aktif (menghapus hardcode `Colors.white` dan border abu-abu terang).
   - Menambahkan dot indikator warna status redaman optik pada `_MetricChip` di `MonitoringScreen` (Normal/Warning/Critical/LOS).
   - Memastikan seluruh teks, background, dan border di `HomeScreen`, `MonitoringScreen`, dan `InterfacesScreen` memiliki kontras yang tajam dan nyaman di mode malam.
3. **Kompilasi Ulang APK v2.0.2 (Build 4)**:
   - Bump versi aplikasi menjadi `v2.0.2+4`.
   - Menghasilkan binary APK rilis terbaru di `public/downloads/netpulse-v2.0.2.apk` dan `netpulse.apk` (56.1 MB).

---

## 2026-09-07 — Perbaikan Notifikasi Push Bergambar (FCM Image Notification)
1. **Pembersihan Token Kedaluwarsa & Auto-Prune**:
   - Membersihkan token perangkat basi (`UNREGISTERED`/`NotRegistered`) yang tertinggal dari instalasi APK lama di tabel `device_tokens`.
   - Menambahkan mekanisme auto-prune pada `SettingsApiController::sendManualPush`: saat FCM mengembalikan status `NotRegistered`, token otomatis dihapus dari database agar pengiriman berikutnya tidak terhambat.
2. **Pengiriman & Penanganan Gambar (BigPictureStyleInformation)**:
   - Menyertakan field `image` pada payload `data` di `SettingsApiController` dan `FcmService.php`.
   - Di sisi aplikasi mobile (`mobile/lib/src/push/fcm_service.dart`), fungsi `_showForegroundNotification` kini mengunduh gambar ke cache lokal dan membungkusnya ke dalam `BigPictureStyleInformation` (`FilePathAndroidBitmap`).
   - Notifikasi bergambar kini tampil sempurna di system tray Android baik saat aplikasi sedang dibuka di latar depan maupun saat berada di latar belakang.
   - APK release diperbarui ke `/public/downloads/netpulse.apk`.

---

## 2026-09-07 — Fitur Operasional Web & Peningkatan Mobile App v2.0.2
1. **Fitur Operasional Web (Tema Neo-Brutalism)**:
   - Menambahkan tombol unduh langsung APK Mobile Netpulse di topbar dan sidebar nav (`/downloads/netpulse.apk`) dengan badge versi `v2.0.2`.
   - Menambahkan App Switcher Ekosistem KusumaVision di topbar untuk memudahkan staf/NOC berpindah antar aplikasi (NMS, MikroTik, Billing, SSO, Portal Perusahaan).
   - Memperkaya visualisasi redaman optik di tabel Interfaces (`public/assets/js/interfaces.js` & `interfaces.css`): badge neo-brutalism dengan color-coded indicator (OK/WARN/CRIT/LOS).
   - Menambahkan badge level RX realtime di kartu monitoring (`statNowBadge` di `monitoring.blade.php`).
2. **Peningkatan Mobile App (Flutter v2.0.2)**:
   - Menambahkan dukungan penuh **Dark Mode** (`buildNetpulseDarkTheme()`) dengan tema navy slate yang nyaman untuk teknisi lapangan.
   - Pilihan pengaturan tema (Sistem / Terang / Gelap) di halaman Akun dengan reaktivitas instan via `themeModeNotifier` dan persistence di SharedPreferences.
   - Menambahkan kartu "Pembaruan Aplikasi" di halaman Akun lengkap dengan tombol dialog link unduh APK resmi.
   - Menambahkan visualisasi mini signal progress bar & dot indikator level redaman pada kartu interface (`_InterfaceCard` di `interfaces_screen.dart`), serta penyesuaian kontras warna adaptif untuk mode terang dan gelap.
   - Kompilasi build APK release `v2.0.2+3` sukses (56.1 MB).

---

## 2026-09-07 — Migrasi Server ke Ekosistem KusumaVision & Rilis APK v2.0.1
1. **Migrasi Database & Backend**:
   - Memindahkan database `netpulse` (35 tabel, 398 interfaces, 23 snmp_devices) dari VM mandiri ke MariaDB lokal server ekosistem KusumaVision.
   - Mengalihkan runtime web ke PHP 8.3-FPM di bawah domain kanonis `https://netpulse.kusumavision.net`.
   - Vhost Nginx terpasang dengan SSL Wildcard KusumaVision (`/etc/nginx/ssl/kusumavision.wildcard.pem`).
   - Penjadwal telemetri antarmuka (`poll:interfaces` dan rollup) dipindahkan ke `/etc/cron.d/netpulse` berjalan di bawah user `www-data`.
   - Memperbaiki binding `FIREBASE_SERVICE_ACCOUNT_JSON` di `config/services.php` agar aman dari pemuatan config cache (`php artisan config:cache`), serta membersihkan pemanggilan fungsi deprecated `openssl_free_key()` di PHP 8.3.
2. **Pembaruan Mobile App (Flutter)**:
   - Mengubah default API endpoint di `mobile/lib/src/auth/session_store.dart` dari `netpulse.bmkv.net` menjadi `https://netpulse.kusumavision.net`.
   - Menyesuaikan dependensi `font_awesome_flutter` ke `^11.0.0` untuk kompatibilitas Flutter 3.44 (`IconData` final class).
   - Menambahkan limit alokasi memori compiler di `android/gradle.properties` (`-Xmx2048m -XX:MaxMetaspaceSize=512m`, daemon off, workers 2) untuk mencegah kehabisan memori server saat kompilasi.
   - Bump versi aplikasi menjadi `2.0.1+3`.
   - Kompilasi APK release selesai dan file binary dipublikasikan ke `public/downloads/netpulse.apk`.
   - Menyediakan skrip helper build `bin/build-apk.sh` untuk memudahkan kompilasi di masa depan.
