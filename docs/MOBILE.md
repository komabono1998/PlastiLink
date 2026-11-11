# PlastiLink on phones

## What is built in

- **Phone layout.** Every signed-in page is checked at 375 px wide with no sideways page scroll. A bottom navigation bar (first four menu items plus **Menu**) replaces reaching for the top-left button. Tables scroll sideways with the **Actions** column pinned on the right. Form fields use 16 px text so iPhones do not zoom in.
- **Installable app (PWA).** `manifest.webmanifest`, icons in `public/assets/img/icons/`, and a small service worker (`public/sw.js`). Once the site is on **HTTPS**, Chrome on Android offers *Install app* / *Add to Home screen*, and PlastiLink opens full-screen without the browser bar.
- **Offline.** The service worker only keeps static files (styles, scripts, images). It **never stores pages or records**: signed-in pages hold personal and money data and always come from the server. With no connection a friendly "You're offline" page appears.

## Installing on a phone

1. Deploy to HTTPS (see `docs/DEPLOYMENT.md`). Installation and the service worker do not work on plain `http://` addresses other than `localhost`.
2. Open the site in Chrome on Android, sign in, open the menu (⋮) and choose **Install app**.
3. On iPhone use Safari → Share → **Add to Home Screen**.

## Testing before deployment

- On the PC: open `http://localhost/PlastiLink/` in Chrome, press F12, choose the phone icon (device toolbar).
- On a phone on the same Wi-Fi: Apache must listen on the PC's network address and the Windows firewall must allow port 80. Open `http://<PC address>/PlastiLink/`. The app works, but it will not offer installation (no HTTPS).

## Android test app (APK)

`android/` holds a small native Android shell (a WebView around the website, no third-party libraries). It is for **testing**; the website itself is the product.

- Build: `powershell -ExecutionPolicy Bypass -File android\build.ps1 -ServerUrl http://<PC address>/PlastiLink/` writes `deploy/dist/plastilink-test.apk`.
- Build tools (JDK 17, Android SDK build-tools 34) are expected in `C:\Android-build`; the test signing key is created there and is never kept in the project.
- A plain `http://` address is allowed for that one host only. For the live site pass an `https://` address and the app refuses plain http entirely.
- Report exports download to the phone's Downloads folder (Android 10 and newer).
- The app stores nothing itself; sign-in, data and all rules stay on the server.
- For the Play Store you would sign with a private release key and a live https address. Do not reuse the test key.

Installing the test APK: copy it to the phone, open it, and allow "install unknown apps" for the app you opened it from. Phone and PC must be on the same Wi-Fi, and the PC must be on with XAMPP's Apache and MySQL running.
