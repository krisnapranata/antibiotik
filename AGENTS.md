# AGENTS.md

Plain-PHP app (no framework, no autoloader) that reports antibiotic usage from an external Khanza SIMRS MySQL database. All user-facing UI text is Indonesian; commits are written in Indonesian.

## Structure
- `index.php` — home page. `pages/{master,laporan,pengaturan}.php` — one page per feature.
- Every page does `require_once` on `lib/layout.php` (which requires `lib/functions.php`). `lib/functions.php` holds all helpers: `get_config()`, `db_connect()`, `get_master()`, `build_antibiotik_filter()`, flash/redirect.
- `vendor/autoload.php` is loaded lazily, only inside `export_excel()` in `pages/laporan.php` (PhpSpreadsheet is the sole composer dep).
- `assets/` — vendored Bootstrap 5 (no CDN).

## State & config
- `config.json` (DB connection, gitignored) and `master.json` (committed) live at the repo root and are rewritten at runtime via `save_json()` — the web server user (www-data) needs write access to them.
- `get_master()` in `lib/functions.php` hardcodes default items that duplicate `master.json`; update both places when adding defaults. Master IDs: include = 1..16, exclude = 101..102; `next_master_id()` = max+1.
- `entrypoint.sh` copies `config.example.json` → `config.json` on first container start only (if missing).

## Run / verify
- `docker compose up -d --build` → http://localhost:8080 (php:8.3-apache).
- No tests, lint, or CI. Verify with `php -l <file>`; runtime check via the docker container (app must be served through Apache — `lib/layout.php` derives `$BASE_URL` from `SCRIPT_NAME`).

## Gotchas
- `db_connect()` sets `SESSION sql_mode=''` — required for legacy Khanza data (zero dates etc.). Don't remove.
- Laporan queries UNION `resep_obat` (prescriptions) with `detail_pemberian_obat` (administrations); drug matching is `LOWER(b.nama_brng) LIKE %kw%` against master include/exclude keywords. Each UNION branch repeats `[dari, sampai] + filter params (+ obat)` — keep the `array_merge` param order in sync with the SQL if you edit it.
- Date defaults in `pages/laporan.php`: `dari=2025-01-01`, `sampai` = end of current month. `mode=excel` exports all periods (one sheet per quarter/semester); `mode=csv` is per-doctor and requires the `dokter` param.
- Escape all output with `e()`; every file declares `strict_types=1`.
