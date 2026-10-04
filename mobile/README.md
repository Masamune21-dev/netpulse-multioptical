# Netpulse Mobile (Android)

Flutter app for NetPulse MultiOptical.

## Current Version

- `2.1.2+8` (Sep 2026) — the first build signed with a dedicated release key. Installs of
  older, debug-signed builds must be uninstalled once before installing this one.

## Main Features

- Login via backend API (`/api/v1/auth/login`)
- Dashboard and monitoring views
- Interface list with traffic history
- Network map with link color states
- Alert log access
- FCM push notification + tap-to-open alert logs
- Device token registration (`/api/v1/device-token`)
- Send current location (`/api/v1/location`)

## Build APK

```bash
cd mobile
flutter pub get
flutter build apk --debug
```

APK output:
- `build/app/outputs/flutter-apk/app-debug.apk`

Release builds require a signing key: `android/app/build.gradle.kts` reads it from the
properties file named by `NETPULSE_KEY_PROPERTIES` or from `android/key.properties`, and the
release build **fails** instead of falling back to the debug key. Neither the keystore nor the
properties file belongs in version control.

```bash
flutter build apk --release --split-per-abi
```

On the production server, `bash bin/build-apk.sh` (from the repository root) builds the split
APKs and copies them to `public/downloads/` (`netpulse.apk` for arm64, `netpulse-arm32.apk`);
`GET /download/app` always serves the latest `netpulse.apk`.

## Firebase Notes

Required local file:
- `android/app/google-services.json`

This file is ignored by git and should not be committed.

## API Base URL

The default base URL is `https://netpulse.kusumavision.net`. Release builds always use it (a value
saved by an older version is ignored); only debug builds can change it from the account screen.
The bearer token is kept in `flutter_secure_storage`, and cleartext HTTP is disabled in release
builds.

