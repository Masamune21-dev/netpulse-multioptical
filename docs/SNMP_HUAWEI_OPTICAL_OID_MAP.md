# Peta OID Huawei untuk SNMP Optical Analytics

Terakhir diperbarui: 13 April 2026

> **Catatan keberlakuan (4 Okt 2026).** Dokumen ini ditulis untuk modul `SNMP Monitor` lain.
> Rujukan kodenya — `SNMPManager`, `SnmpController`, perintah `snmp:poll-optical-due`, tabel
> `snmp_optical` / `snmp_optical_history` / `snmp_interfaces` — **tidak ada di repo Netpulse**.
> Yang tetap berlaku: OID Huawei (`hwEntityOpticalRxPower` `.8` / `hwEntityOpticalTxPower` `.9`
> di bawah `1.3.6.1.4.1.2011.5.25.31.1.1.3.1`), sifat indeks `entPhysicalIndex`, pemetaan lewat
> `entAliasMappingIdentifier`, dan aturan normalisasi daya hibrida (≤ 0 → 0,01 dBm, > 0 → µW).
>
> Implementasi Netpulse ada di `app/Services/Optical/HuaweiDriver.php` + `EntityAliasMap.php`, dan
> berbeda dari uraian di bawah: hanya RX/TX yang dibaca (suhu, tegangan, bias tidak); port yang
> `entPhysicalIndex`-nya tak terpetakan ke `ifIndex`/`ifName` dilewati (tanpa fallback
> `entPhysicalName`/`entPhysicalDescr`/`Entity <index>`); nilai di luar -60…10 dBm dibuang; hasilnya
> disimpan ke `interfaces` dan `interface_stats`. Lihat `docs/NETPULSE_DOCUMENTATION.md` bagian
> "Dukungan Vendor & Driver Optik".

Dokumen ini memetakan OID Huawei yang benar-benar dipakai oleh modul `SNMP Monitor` BMKV untuk tab `Optical Analytics`, dengan fokus pada switch Huawei. Tujuannya adalah membuat tim NOC dan developer bisa menelusuri alur data dari `snmpwalk` mentah sampai tampil di UI, tanpa harus menebak unit raw, suffix index, atau relasi `entPhysicalIndex` ke nama port.

## 1. Scope Implementasi di BMKV

Scope dokumen ini mengikuti implementasi aktif di source code:

- collector utama: `app/Services/SNMPManager.php`
- entry vendor collector: `SNMPManager::getSFPDataFromDevice()`
- collector Huawei: `SNMPManager::getHuaweiOpticalData()`
- mapping entity ke nama interface: `SNMPManager::buildEntPhysicalIfNameMap()`
- normalisasi nilai optical Huawei: `normalizeHuaweiTemperature()`, `normalizeHuaweiVoltage()`, `normalizeHuaweiBiasCurrent()`, `normalizeHuaweiOpticalPower()`
- penyimpanan snapshot: `snmp_optical`
- penyimpanan histori RX/TX: `snmp_optical_history`
- consumer UI/API: `SnmpController::getOpticalAnalyticsData()` dan `SnmpController::getOpticalHistoryData()`

Collector Huawei hanya relevan untuk device yang:

- `sysDescr` mengandung `huawei`, `quidway`, atau `cloudengine`
- `type` device mengandung kata `switch` sehingga ikut dipoll oleh worker optical

## 2. Alur Data End-to-End

1. Scheduler menjalankan `php artisan snmp:poll-optical-due --limit=100`.
2. `SNMPManager::pollDeviceOptical()` membuka sesi SNMP dan memanggil `getSFPDataFromDevice()`.
3. Bila `sysDescr` cocok Huawei, collector mencoba subtree `HUAWEI-ENTITY-EXTENT-MIB` untuk DOM optical.
4. Hasil walk dinormalisasi ke payload BMKV per port:
   - `interface_name`
   - `rx_power`
   - `tx_power`
   - `temperature`
   - `voltage`
   - `tx_bias`
5. Payload disimpan ke `snmp_optical`, lalu snapshot `rx_power`/`tx_power` ditambahkan ke `snmp_optical_history`.
6. Tab `Optical Analytics` membaca `snmp_optical`, sedangkan modal chart histori membaca `snmp_optical_history`.

## 3. Base OID Huawei yang Dipakai

Collector optical Huawei memakai base:

```text
1.3.6.1.4.1.2011.5.25.31.1.1.3.1
```

Base ini diperlakukan sebagai tabel optical per `entPhysicalIndex`. BMKV saat ini memakai kolom berikut:

| Objek MIB | OID numerik | Dipakai BMKV | Fungsi di BMKV | Catatan unit/raw |
| --- | --- | --- | --- | --- |
| `hwEntityOpticalMode` | `1.3.6.1.4.1.2011.5.25.31.1.1.3.1.1` | Tidak | Belum dibaca | Tersedia di MIB Huawei, belum dipetakan ke UI |
| `hwEntityOpticalWaveLength` | `1.3.6.1.4.1.2011.5.25.31.1.1.3.1.2` | Tidak | Belum dibaca | BMKV saat ini menyimpan `wavelength = null` untuk Huawei |
| `hwEntityOpticalTransDistance` | `1.3.6.1.4.1.2011.5.25.31.1.1.3.1.3` | Tidak | Belum dibaca | Bisa dipertimbangkan untuk future enhancement |
| `hwEntityOpticalVendorSn` | `1.3.6.1.4.1.2011.5.25.31.1.1.3.1.4` | Tidak | Belum dibaca | Serial number modul belum dibawa ke UI |
| `hwEntityOpticalTemperature` | `1.3.6.1.4.1.2011.5.25.31.1.1.3.1.5` | Ya | Isi `snmp_optical.temperature` | Disimpan setelah sanity check `-20..150` |
| `hwEntityOpticalVoltage` | `1.3.6.1.4.1.2011.5.25.31.1.1.3.1.6` | Ya | Isi `snmp_optical.voltage` | Nilai besar dibagi `1000`, lalu disimpan sebagai volt |
| `hwEntityOpticalBiasCurrent` | `1.3.6.1.4.1.2011.5.25.31.1.1.3.1.7` | Ya | Isi `snmp_optical.tx_bias` | Nilai besar dibagi `1000` sebelum disimpan |
| `hwEntityOpticalRxPower` | `1.3.6.1.4.1.2011.5.25.31.1.1.3.1.8` | Ya | Isi `snmp_optical.rx_power` dan histori RX | Lihat aturan normalisasi power Huawei di bawah |
| `hwEntityOpticalTxPower` | `1.3.6.1.4.1.2011.5.25.31.1.1.3.1.9` | Ya | Isi `snmp_optical.tx_power` dan histori TX | Lihat aturan normalisasi power Huawei di bawah |

Catatan penting:

- BMKV saat ini hanya memakai kolom `.5` sampai `.9` untuk optical vitals.
- Kolom `.1` sampai `.4` masih belum dipakai, walau secara MIB tersedia dan bisa berguna untuk pengayaan data port di masa depan.
- Semua suffix index pada subtree ini dianggap sebagai `entPhysicalIndex`, bukan `ifIndex`.

## 4. OID Pendukung untuk Mapping Port

Karena tabel optical Huawei memakai `entPhysicalIndex`, collector perlu memetakan entity fisik ke nama port switch yang konsisten dengan tabel interface. BMKV memakai OID pendukung berikut:

| Objek | OID numerik | Fungsi di BMKV |
| --- | --- | --- |
| `entPhysicalDescr` | `1.3.6.1.2.1.47.1.1.1.1.2` | Fallback label port bila `ifName` tidak ditemukan |
| `entPhysicalName` | `1.3.6.1.2.1.47.1.1.1.1.7` | Fallback label port sebelum `entPhysicalDescr` |
| `entAliasMappingIdentifier` | `1.3.6.1.2.1.47.1.3.2.1.2` | Menghubungkan `entPhysicalIndex` ke pointer `ifIndex` |
| `ifIndex` | `1.3.6.1.2.1.2.2.1.1` | Menyediakan daftar indeks interface untuk lookup |
| `ifName` | `1.3.6.1.2.1.31.1.1.1.1` | Nama port utama yang dipakai sebagai `interface_name` |
| `ifDescr` | `1.3.6.1.2.1.2.2.1.2` | Fallback nama port bila `ifName` kosong |

Urutan mapping di BMKV:

1. Walk `entAliasMappingIdentifier`.
2. Ekstrak `entPhysicalIndex` dari OID alias table.
3. Ambil pointer `ifIndex` dari value alias table.
4. Lookup `ifName`.
5. Jika `ifName` kosong, fallback ke `ifDescr`.
6. Jika mapping interface tetap gagal, fallback ke `entPhysicalName`.
7. Jika masih kosong, fallback ke `entPhysicalDescr`.
8. Jika semuanya kosong, BMKV memakai label sintetis `Entity <index>`.

Implikasi praktis:

- suffix pada `.8.<index>` atau `.9.<index>` tidak bisa langsung diasumsikan sebagai nomor port user-facing
- keakuratan join ke `snmp_interfaces.alias` bergantung pada hasil akhir `interface_name` yang cocok dengan `snmp_interfaces.name`

## 5. Aturan Normalisasi Nilai Huawei di BMKV

Bagian ini adalah kunci agar pembacaan Huawei tidak salah tafsir.

### Temperature

Aturan normalisasi:

- parse angka mentah SNMP
- tolak bila tidak numerik
- tolak bila di luar rentang `-20` sampai `150`
- simpan apa adanya sebagai derajat Celsius

### Voltage

Aturan normalisasi:

- parse angka mentah SNMP
- bila `abs(raw) > 100`, nilai dibagi `1000`
- bila hasil `<= 0`, nilai dibuang
- hasil akhir dibulatkan `3` digit desimal

Praktiknya, BMKV mencoba menormalisasi platform yang melaporkan tegangan dalam milivolt menjadi volt.

### TX Bias

Aturan normalisasi:

- parse angka mentah SNMP
- bila `abs(raw) > 100`, nilai dibagi `1000`
- bila hasil `<= 0`, nilai dibuang
- hasil akhir dibulatkan `3` digit desimal

Catatan:

- dokumentasi Huawei per platform tidak selalu konsisten soal unit bias current
- BMKV sengaja memakai normalisasi defensif agar nilai besar tidak tampil sebagai angka mentah yang tidak masuk akal

### RX Power dan TX Power

Aturan normalisasi di BMKV bersifat hybrid:

- jika `raw <= 0`, BMKV menganggap nilai sudah berbasis `0.01 dBm`, lalu menyimpan `raw / 100`
- jika `raw > 0`, BMKV menganggap nilai praktis dibaca dalam mikrowatt, lalu mengubahnya ke dBm dengan rumus:

```text
10 * log10(raw / 1000)
```

Alasan aturan hybrid ini dipakai:

- sebagian referensi Huawei menjelaskan `hwEntityOpticalRxPower` dan `hwEntityOpticalTxPower` dalam `0.01 dBm`
- ada juga referensi Huawei yang mencatat bahwa pada platform tertentu nilai aktual yang terbaca adalah mikrowatt walau deskripsi MIB menyebut dBm
- implementasi BMKV dipilih agar collector tetap usable pada kedua perilaku perangkat tersebut

Ini berarti:

- nilai negatif atau nol diperlakukan sebagai nilai dBm mentah
- nilai positif besar diperlakukan sebagai mikrowatt dan dikonversi ke dBm

## 6. Filter Row yang Dianggap Valid

Tidak semua row Huawei dari tabel optical langsung masuk ke UI. BMKV hanya menyimpan row yang dianggap bermakna:

- ada salah satu dari `temperature`, `voltage`, atau `tx_bias`
- atau `rx_power > -49.5`
- atau `tx_power > -49.5`

Jika semua nilai penting kosong atau terlalu dekat ke nilai "tidak ada sinyal", row dibuang agar tab `Optical Analytics` tidak penuh entitas kosong.

Keterbatasan implementasi saat ini:

- `rx_loss` selalu diset `false`
- `tx_fault` selalu diset `false`
- `wavelength` untuk Huawei belum diisi walau kolom database tersedia

## 7. Mapping ke Database dan UI

| Sumber collector | Tabel/kolom tujuan | Dipakai oleh UI |
| --- | --- | --- |
| `interface_name` | `snmp_optical.interface_name` | Label port di tabel optical |
| `rx_power` | `snmp_optical.rx_power` | Kolom RX power dan current value di modal history |
| `tx_power` | `snmp_optical.tx_power` | Kolom TX power dan current value di modal history |
| `temperature` | `snmp_optical.temperature` | Kolom temperature |
| `voltage` | `snmp_optical.voltage` | Kolom voltage |
| `tx_bias` | `snmp_optical.tx_bias` | Kolom TX bias |
| `last_update` | `snmp_optical.last_update` | Freshness badge di tab optical |
| `rx_power`, `tx_power`, `sampled_at` | `snmp_optical_history` | Chart histori RX/TX per port |

Query tab `Optical Analytics` juga menggabungkan:

- `snmp_devices` untuk hostname, status, lokasi, `sysDescr`, dan interval polling
- `snmp_interfaces` untuk `alias` dan `oper_status`

Join alias/comment memakai syarat:

```text
snmp_interfaces.device_id = snmp_optical.device_id
snmp_interfaces.name      = snmp_optical.interface_name
```

Jika nama port hasil mapping Huawei tidak identik dengan `ifName` yang tersimpan di `snmp_interfaces`, alias/comment tidak akan ikut tampil.

## 8. Contoh Verifikasi Manual dengan snmpwalk

### Melihat semua metric optical Huawei

```bash
snmpwalk -v2c -c COMMUNITY -On -OQn -Cc HOST 1.3.6.1.4.1.2011.5.25.31.1.1.3.1
```

### Melihat RX power saja

```bash
snmpwalk -v2c -c COMMUNITY -On -OQn -Cc HOST 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.8
```

### Melihat TX power saja

```bash
snmpwalk -v2c -c COMMUNITY -On -OQn -Cc HOST 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.9
```

### Melihat mapping entity ke interface

```bash
snmpwalk -v2c -c COMMUNITY -On -OQn -Cc HOST 1.3.6.1.2.1.47.1.3.2.1.2
snmpwalk -v2c -c COMMUNITY -On -OQn -Cc HOST 1.3.6.1.2.1.31.1.1.1.1
snmpwalk -v2c -c COMMUNITY -On -OQn -Cc HOST 1.3.6.1.2.1.47.1.1.1.1.7
snmpwalk -v2c -c COMMUNITY -On -OQn -Cc HOST 1.3.6.1.2.1.47.1.1.1.1.2
```

### Langkah korelasi yang disarankan

1. Catat suffix index dari `hwEntityOpticalRxPower` atau `hwEntityOpticalTxPower`.
2. Cari index yang sama di `entPhysicalName` atau `entPhysicalDescr`.
3. Bila tersedia, cocokkan index itu di `entAliasMappingIdentifier` untuk mendapat pointer `ifIndex`.
4. Lookup `ifIndex` tersebut ke `ifName`.
5. Bandingkan hasil akhirnya dengan `snmp_optical.interface_name` dan `snmp_interfaces.name`.

## 9. Batasan Saat Ini dan Arah Pengembangan

Hal yang sudah didukung:

- RX power
- TX power
- temperature
- voltage
- TX bias
- mapping `entPhysicalIndex -> ifName`
- histori RX/TX per port

Hal yang belum dimanfaatkan walau tersedia di keluarga MIB yang sama:

- optical mode
- wavelength Huawei
- transmission distance
- vendor serial number modul
- fault/loss flag khusus Huawei

Saran pengembangan berikutnya:

- isi `wavelength` Huawei dari kolom `.2`
- tambahkan fault/loss state bila ada OID Huawei yang stabil lintas platform
- simpan `entPhysicalIndex` mentah ke tabel agar debugging mapping port lebih mudah
- tambahkan unit/source flag untuk power Huawei agar operator tahu apakah data berasal dari mode `0.01 dBm` atau hasil konversi mikrowatt

## 10. Sumber Kebenaran untuk Dokumen Ini

Sumber utama dokumen ini adalah implementasi aktif di repo:

- `app/Services/SNMPManager.php`
- `app/Http/Controllers/SnmpController.php`
- `docs/SNMP_MONITOR_GUIDE.md`
- `docs/MODULE_GUIDE.md`

Objek MIB dan catatan perilaku Huawei diverifikasi silang dengan:

- referensi `HUAWEI-ENTITY-EXTENT-MIB` dari dokumentasi Huawei
- `ENTITY-MIB` / `entAliasMappingTable` dari standar IETF

Catatan penting:

- bila ada perbedaan antara teks MIB vendor dan perilaku perangkat nyata, BMKV mengikuti perilaku yang paling aman terhadap data lapangan dan sudah dicerminkan oleh kode collector aktif
- untuk itulah normalisasi power Huawei di BMKV dibuat hybrid, bukan satu asumsi unit tunggal
