# Automatic Backups (Migrated to Cloudflare)

> **Migration Notice:** Backups have been migrated from Google Drive to **Cloudflare Storage**:
> **[https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home](https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home)**
> Please refer to [CLOUDFLARE_BACKUPS.md](CLOUDFLARE_BACKUPS.md) for current configuration and usage.

The backup command exports the MySQL database and packages the application
source, encrypts the archive locally, and uploads only the encrypted file to
Google Drive. It runs from PHP CLI, not from a browser. On first run it creates
a `Secure Health System Backups` folder in the authorized Google account's
Drive.

## One-time setup

1. In Google Cloud, create or select a project, enable the Google Drive API,
   and configure the OAuth consent screen. Create an OAuth client ID for a
   **Web application** with this authorized redirect URI:
   `https://developers.google.com/oauthplayground`.
2. Open the Google OAuth 2.0 Playground. In its settings, enable **Use your
   own OAuth credentials** and enter the client ID and client secret. In Step
   1, authorize this scope:
   `https://www.googleapis.com/auth/drive.file`.
   In Step 2, exchange the authorization code and copy the returned refresh
   token. Use the Google account where backups should be stored. Publish the
   consent screen to **Production** before relying on a scheduled refresh
   token; Google may expire tokens issued while an external app is in Testing.
3. Generate a unique 32-byte encryption key and retain it in a separate,
   secure location. Losing the key makes every backup unrecoverable:

   ```powershell
   $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
   $bytes = New-Object byte[] 32
   $rng.GetBytes($bytes)
   $rng.Dispose()
   [Convert]::ToBase64String($bytes)
   ```

4. Set these environment variables for the Windows account that will run the
   task, then sign out and back in so Task Scheduler can read them:

   - `GOOGLE_OAUTH_CLIENT_ID` — OAuth client ID.
   - `GOOGLE_OAUTH_CLIENT_SECRET` — OAuth client secret.
   - `GOOGLE_OAUTH_REFRESH_TOKEN` — refresh token from OAuth Playground.
   - `BACKUP_ENCRYPTION_KEY` — the generated base64 key.

   Protect these values as credentials. Do not put them in a PHP file under
   the web root, commit them, or send the encryption key to Google Drive.
5. Test a backup from PowerShell, replacing the example values:

   ```powershell
   $env:GOOGLE_OAUTH_CLIENT_ID = 'your-client-id'
   $env:GOOGLE_OAUTH_CLIENT_SECRET = 'your-client-secret'
   $env:GOOGLE_OAUTH_REFRESH_TOKEN = 'your-refresh-token'
   $env:BACKUP_ENCRYPTION_KEY = 'your-base64-32-byte-key'
   & 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\secure_health_system\google_drive_backup.php'
   ```

   PHP CLI must have `curl`, `openssl`, `phar`, and `pdo_mysql` enabled.
   Confirm that an encrypted `.tar.gz.enc` file appears in the new Drive
   folder.
6. In Windows Task Scheduler, create a task that runs daily at the desired
   time (2:00 AM is a suggested default). Run it as the same Windows account
   whose environment variables you configured. Set the action to:

   - **Program/script:** `C:\xampp\php\php.exe`
   - **Add arguments:** `"C:\xampp\htdocs\secure_health_system\google_drive_backup.php"`
   - **Start in:** `C:\xampp\htdocs\secure_health_system`

   Enable task history and configure failure notifications or monitoring
   appropriate for your deployment. A failed run writes a non-zero exit code
   and an error to standard error.

## Restore

Download an encrypted backup and decrypt it on a trusted machine with the
same `BACKUP_ENCRYPTION_KEY`:

```powershell
& 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\secure_health_system\google_drive_backup.php' `
  --decrypt 'C:\secure\secure-health-backup-YYYY-MM-DD_HH-MM-SS.tar.gz.enc' `
  'C:\secure\restore.tar.gz'
```

Verify the decrypted archive before extracting it. It contains table and view
definitions, table data in `database.sql`, and application source files.
Restore the SQL into a fresh database, and
restore `config.php` separately from your protected configuration copy:
`config.php`, `.env` files, OAuth credentials, dependency folders, and
generated/runtime data are deliberately excluded from the archive.

Backups include sensitive health information. Keep access to the Drive folder
restricted, retain the encryption key separately, periodically test a restore,
and set a retention policy for old Drive backups.
