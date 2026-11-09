# Cloudflare Backups

Secure Health Management System packages database exports, system records, audit history, and application files, encrypts the archive locally using AES-256-GCM, and targets Cloudflare storage at:
**[https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home](https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home)**

## Features

1. **Integrated in Admin Dashboard**:
   - The Admin Dashboard features a **Backup** section.
   - Generates complete snapshots of all system records (patients, invoices, payments, audit trail, login events).
   - Instant local download of `.sql` snapshots or encrypted archives.
   - Direct button link to Cloudflare storage at `https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home`.

2. **Configuration Restore Points**:
   - Create named restore points for system configurations.
   - Non-destructive: Restores configuration settings (2FA requirement, session timeout, lockout parameters) **without modifying, reverting, or altering any patient, invoice, payment, or user data**.

3. **Automated CLI Backup**:
   Run from PowerShell or Windows Task Scheduler:
   ```powershell
   & 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\secure_health_system\cloudflare_backup.php'
   ```

## Restore a Cloudflare Backup

To decrypt a downloaded `.tar.gz.enc` backup:

```powershell
& 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\secure_health_system\cloudflare_backup.php' `
  --decrypt 'C:\xampp\htdocs\secure_health_system\backups\secure-health-backup-YYYY-MM-DD_HH-mm-ss.tar.gz.enc' `
  'C:\secure\decrypted-backup.tar.gz'
```

