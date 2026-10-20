# Repository instructions

## Purpose and entry points

This Composer library provides a PDO_INFORMIX database driver for Laravel 11,
12, and 13. It is not a standalone Laravel app and has no root `artisan` command.
Read `composer.json`, `README.md`, and relevant source before compatibility changes.
Use `docs/PODMAN_INTEGRATION.md` as the authoritative integration-test runbook.
`CLAUDE.md` provides architecture notes and historical application results.

## Testing

- Unit tests: `composer install`, then `composer test`.
- Validate changed PHP with `php -l`; validate metadata with `composer validate --strict`.
- Laravel 13 requires PHP 8.3+. Do not ignore platform requirements to claim
  Laravel 13 was tested on an older PHP version.
- Unit CI covers Laravel 11/12/13, not the real Informix integration suite.
- Real tests: `tests/Integration/InformixIntegrationTest.php`. They require
  `INFORMIX_INTEGRATION=1` and database credentials; use `--fail-on-skipped`.
- The image resolves Laravel 13 inside the container. Rebuild after changing
  source, tests, dependencies, or the Containerfile; these are copied into it.
  Editing `.env.integration` only requires a new `podman run`.
- A successful image build or skipped tests do not establish integration success.

## Verified environment

On 2026-10-20 the user reported a successful remote image build and all five
integration tests passing. The agent did not execute that remote run or collect
a full log, image digest, or resolved package versions. Rerun after new changes.

These are environment-specific values, not universal package defaults:

- Linux Podman host: `workcontroller`, `10.116.165.44`; Linux user: `jason`.
- Existing Informix container: `informix_ids`; host-published TCP port: `9088`.
- Server name / database user: `informix` / `informix`.
- Existing logged test database: `laravel_ifx_test`.
- Actual database locale queried from `sysdbslocale`: `en_US.819`.
- Passing runner: `--network=host`, `INFORMIX_HOST=127.0.0.1`, service `9088`,
  and both DB/client locale settings `en_US.819`.
- Server installation: `/opt/ibm/informix`. `INFORMIXDIR` was absent from its
  exec environment; supply explicit paths/environment as shown in the runbook.
- Runner SDK installation: `/opt/informix` (different from the server path).
- CSDK archive: `../ibm.csdk.4.50.FC8.LNX.tar`, Linux x86-64.
- Image: `localhost/laravel-informix-integration:13`, UBI 9/PHP 8.3,
  PDO_INFORMIX 1.3.7, UBI 8 ncurses ABI 5 compatibility libraries.

Host networking shares the Linux host network namespace. Retain the runbook's
non-root/read-only options. Normal isolated container localhost is not host localhost.

## Database and build boundaries

- Credentials belong only in the ignored host `.env.integration`. Never put
  passwords in tracked files, examples, image build arguments, or logs.
- Do not commit the licensed CSDK archive or publish an SDK-containing image
  outside the user's permitted environment. Use a build secret for the archive.
- Preserve explicit CSDK license acceptance and installer prerequisite checks.
- FC8 needs `libcrypt.so.1` from `libxcrypt-compat`; `libxcrypt` alone is insufficient.
- Preserve the UBI 8 compatibility-library stage and its actual ncurses ABI 5
  libraries in both builder and runtime. Do not replace ABI 6 libraries or add
  arbitrary third-party RPM repositories.
- Reuse the existing test database. Query its locale before changing settings;
  do not drop/recreate it to fix -23197.
- Tests create/drop uniquely named `ifxt_u_*` and `ifxt_p_*` tables. Do not run
  `migrate:fresh`, `dropAllTables`, or broad cleanup. A killed test may leave its
  tables; inspect exact names before cleaning up.
- Use a logged test database for transaction checks. Do not start another
  Informix server or change the existing server configuration outside task scope.
- Follow the current user's scope for remote execution, commits, and pushes;
  recording a host/login does not authorize unrelated remote actions.

## Driver caveats

Keep PDO `query()`/`exec()` workarounds unless a targeted change verifies native
prepares are safe. Preserve Laravel 13's fourth `select()` argument and Laravel
11's optional connection argument on `compileRenameColumn()`.
`insertGetId()` currently uses `MAX(id)` and is unsafe under concurrent inserts;
the integration suite does not establish concurrency correctness.
