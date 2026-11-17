# NIGHTLINE TV

First integration milestone. Nightline Admin controls viewer accounts, service assignments, expiry notices and messages. The Android prototype renders the TV interface from the dedicated panel over HTTPS. Midnight Blue is the default and the two additional palettes are saved on the device.

## Run the panel locally

Requires Python 3.10 or newer. From this directory:

```sh
python3 panel/server.py superadmin
NIGHTLINE_LOCAL=1 python3 panel/server.py
```

1. Open http://127.0.0.1:8080/superadmin and sign in with the account you created. Set up the single Admin account.
2. Open http://127.0.0.1:8080/admin and create a viewer account and services. Each service in this milestone accepts a single authorised HTTPS test stream URL. Assign one or more services and optionally set their expiry dates.
3. Open http://127.0.0.1:8080/tv and sign in as the viewer. One assigned service opens automatically. Multiple assignments show the picker on entry. Select Switch Service to return to the picker. Messages and expiry notices appear in the TV interface.

## Try the Android APK

Use a dedicated HTTPS hostname at its root. Run the panel behind the HTTPS reverse proxy with NIGHTLINE_LOCAL unset so its cookies require HTTPS. Set NIGHTLINE_DB to an absolute persistent path outside the public web root. The included Python server is for local development and controlled testing. Use a production WSGI server and login rate limiting before exposing the service to viewers.

Push the project to a GitHub repository. In Actions, run **NIGHTLINE TV debug APK** and pass the panel's HTTPS origin as panel_url. Download the resulting debug APK artifact. For a local build use JDK 17, Gradle 8.9 and Android SDK 35:

```sh
gradle assembleDebug -PnightlineUrl=https://your-nightline-host.example
```

## Scope of this milestone

The TV interface establishes the top navigation, featured area and shortcut row. The current featured area shows the selected test service. Provider integrations, the full EPG, native playback, configurable featured content, Movies and Series catalogues, QR/PIN and MAC sign-in, sports fixtures and notifications, Stremio and My Apps are pending. Playback support in this milestone depends on the device's WebView. Device caps are omitted.
