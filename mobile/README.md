# Secure Health Mobile Preview

This is a small Expo Go companion for the existing PHP/MySQL Secure Health Management System. It displays the existing site inside a React Native WebView; the backend, role permissions, login, and patient/accountant/admin workflows remain in the PHP project.

## Prerequisites

- Node.js LTS with npm installed.
- Expo Go installed on an Android or iOS phone.
- Apache and MySQL running through XAMPP.
- The phone and computer connected to the same trusted Wi-Fi network.

Node.js LTS and npm are required. Install them from [nodejs.org](https://nodejs.org/en/) if they are not available in your terminal.

## Configure the PHP server address

Expo Go runs on the phone, so `localhost` would point to the phone, not the computer. The local `.env.local` file currently points to:

```text
http://192.168.100.126/secure_health_system/login.php
```

Change this URL if the computer's Wi-Fi address changes. You can also tap **Server** in the app to configure the address on that phone; the app saves this setting locally. To use ngrok, paste the HTTPS forwarding URL plus `/secure_health_system/login.php` into the **Server** setting.

Before opening Expo Go, test the URL in the phone's browser. If it does not load there, check that Apache is running, both devices are on the same network, and Windows Firewall allows Apache on the private network.

## Start Expo Go

From PowerShell:

```powershell
cd C:\xampp\htdocs\secure_health_system\mobile
npm install
npx expo start --lan
```

Scan the terminal QR code with Expo Go on Android. On iOS, scan it with the Camera app and open the link in Expo Go. Keep Apache/MySQL running while using the preview.

If the Expo development server itself cannot be reached, try:

```powershell
npx expo start --tunnel
```

The tunnel only carries the Expo JavaScript bundle. It does not expose or tunnel the PHP backend; the phone must still be able to reach the configured Apache URL.

## Use an ngrok URL

Use ngrok when the phone cannot reach Apache over the same Wi-Fi. This creates a public HTTPS endpoint that forwards to the local XAMPP Apache server.

1. Install the ngrok agent from [ngrok's official download page](https://download.ngrok.com/) and create/sign in to an ngrok account.
2. In a PowerShell window, add your account token by running `ngrok config add-authtoken YOUR_TOKEN`. Get the token from the ngrok dashboard and enter it directly in your terminal; do not paste it into this project or a chat.
3. Make sure XAMPP Apache and MySQL are running, then start a second tunnel terminal with `ngrok http 80`.
4. Copy the HTTPS forwarding address printed by ngrok, for example `https://example-name.ngrok-free.app`, and open **Server** in the Expo wrapper. Enter the full PHP route, such as `https://example-name.ngrok-free.app/secure_health_system/login.php`. The app saves that address on the device. Alternatively, put it in `.env.local` as `EXPO_PUBLIC_WEB_APP_URL` and restart Expo for it to take effect.

The ngrok URL is public while the tunnel is running and may change when restarted. Do not use real patient data or expose this development XAMPP configuration publicly; the project still needs production database credentials, a unique secret pepper, and production hardening before public use. Never forward the MySQL port.

## Security notes

- This is a development preview, not a production mobile release.
- The app contains no database credentials and must never connect directly to MySQL.
- `EXPO_PUBLIC_WEB_APP_URL` is bundled into the app and is not a secret; only put the server address there.
- The current XAMPP setup uses HTTP. Use it only on a trusted private network for local testing. Use HTTPS and production credentials before exposing the PHP system beyond your development network.
- The `mobile/.htaccess` file denies Apache access to the Expo source folder when Apache permits per-directory overrides. Do not rely on that as the only protection for secrets; no secrets belong in the mobile project.

## Project structure

- `App.tsx`: native header, WebView, connection/error states, server settings, and safe navigation handling.
- `app.json`: Expo app identity and platform metadata.
- `.env.local`: local server URL; excluded from Git.
- `.env.example`: example configuration for another developer.
