# Deploying to the live site

The `Deploy to live site` GitHub Actions workflow is a manual, application-code
deployment. It uploads root PHP, CSS, JavaScript, and `.htaccess` files to the
configured FTP directory using explicit FTPS. It does not delete remote files,
replace `config.php`, upload SQL dumps, or copy local database records.

## One-time GitHub setup

1. Keep `config.php`, database exports, credentials, and real user data out of
   the repository. The local `secure_health_system.sql` dump is ignored because
   it contains account details and password/security-answer hashes.
2. In repository **Settings → Secrets and variables → Actions**, add these
   repository secrets using values from the InfinityFree account that owns
   `secure-health-system.rf.gd`:
   - `INFINITYFREE_FTP_HOST` — FTP hostname only, without a scheme or path.
   - `INFINITYFREE_FTP_USERNAME`
   - `INFINITYFREE_FTP_PASSWORD`
   - `INFINITYFREE_FTP_REMOTE_DIR` — normally `/htdocs`.
3. In **Actions → Deploy to live site → Run workflow**, select `main`, type
   `DEPLOY`, and run it. Review the workflow log; each uploaded filename is
   listed, and a failed FTPS transfer fails the job.

## Database updates

This workflow deliberately does not upload or execute SQL. InfinityFree
shared-hosting deployments must not assume that a GitHub runner can connect to
the hosted MySQL server directly. For a database schema change, prepare and
review an incremental migration, back up the live database first, and apply
that migration to `if0_43097404_health_demo` through the correct account's
phpMyAdmin. Do not import the local full database dump over the live database:
that can overwrite live account, patient, and invoice records.

After a deployment, verify the login page and affected pages over HTTPS. Keep
the browser Safe Browsing warning in mind: an Actions deployment cannot clear
or override a browser reputation warning.
