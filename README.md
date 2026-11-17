# NIGHTLINE TV

Android TV prototype with a dedicated panel. The TV interface uses the Midnight Blue layout and three selectable palettes. Admin assigns services to viewer accounts. Viewers with multiple assigned services choose a service when entering the app.

## PHP shared hosting

See [installation instructions](php-panel/README.md) for the PHP 8.1 panel, separate Admin and Superadmin access, and how to point the APK at a hosting subfolder. The earlier Python prototype remains available in panel/ for reference.

## Debug APK

Open Actions and run **NIGHTLINE TV debug APK** with the HTTPS base URL of the PHP panel, omitting index.php. For local builds use JDK 17, Gradle 8.9, and Android SDK 35.

```sh
gradle assembleDebug -PnightlineUrl=https://example.com/nightline
```

Each service currently accepts one direct HTTPS test stream. Full IPTV provider integration, native playback, the full EPG, adjustable featured content, Movies and Series catalogues, QR/PIN and MAC sign-in, sports fixtures and notifications, Stremio and My Apps remain pending.
