# InfinityFree Fresh Install Guide

Use this checklist for a clean deployment on InfinityFree.

## 1) Upload
- Upload the full project contents into `htdocs`.
- Confirm key files exist:
  - `setup.php`
  - `infinityfree_preflight.php`
  - `database/schema.sql`
  - `modules/jobs/jumbo/index.php`

## 2) Create Database (InfinityFree panel)
- Create MySQL database from InfinityFree control panel.
- Collect:
  - DB host (example: `sqlXXX.infinityfree.com`)
  - DB name (example: `if0_xxxxx_dbname`)
  - DB user
  - DB password

## 3) Preflight Check
- Open `https://your-domain/infinityfree_preflight.php`
- Ensure all required checks pass.
- Make sure writable paths pass:
  - `config`
  - `data`
  - `uploads`
  - `uploads/company`
  - `uploads/library`

## 4) Run Installer
- Open `https://your-domain/setup.php`
- Fill DB credentials from InfinityFree panel.
- Set `Save Profile To` = `live`.
- Set `Base URL`:
  - Empty if app is in `htdocs` root.
  - `/subfolder-name` if app is inside a subfolder.
- Keep `Create database automatically` unchecked (shared hosting).
- Keep `Git auto-push` disabled.
- Submit installation.

## 5) Verify
- Open login page: `https://your-domain/auth/login.php`
- Open Jumbo jobs page: `https://your-domain/modules/jobs/jumbo/index.php`
- Run optional checks:
  - `https://your-domain/check_tables.php`
  - `https://your-domain/verify_setup.php`

## 6) Hardening After Install
- Delete or rename these files:
  - `setup.php`
  - `infinityfree_preflight.php`
  - `create_tables.php`
  - `check_tables.php`
  - `verify_setup.php`
  - `github_auto_push.php`

## 7) Common Issues
- 404 on one module only:
  - Confirm exact lowercase path on server.
  - Re-upload that module folder.
- Redirect/path problems:
  - Recheck `Base URL` in setup.
- DB errors:
  - Confirm exact DB host/user/password from InfinityFree panel.
  - Re-run installer with `Save Profile To = live`.
