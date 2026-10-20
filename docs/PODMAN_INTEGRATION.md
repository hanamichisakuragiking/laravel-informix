# Podman integration tests on 10.116.165.44

This setup builds a disposable PHP 8.3 / Laravel 13 test runner and connects it
to your existing Informix container's host-published TCP port `9088`. It does not
start another Informix server. Run the Linux commands below on that host, as
the same Linux user that manages the existing Informix container.

## Verified setup and result (2026-10-20)

The user reported a successful image build and all five integration tests
passing on `workcontroller` (`10.116.165.44`). The agent did not execute the
remote run or collect a full test log, image digest, or resolved package versions.
Rerun after code changes; this record does not cover every feature or concurrency.

| Setting | Verified value |
| --- | --- |
| Linux login | `jason` |
| Existing database container | `informix_ids` |
| Server installation | `/opt/ibm/informix` |
| Informix server name / database user | `informix` / `informix` |
| Existing logged test database | `laravel_ifx_test` |
| Database locale from `sysdbslocale` | `en_US.819` |
| Runner network | `--network=host` |
| Runner host / service | `127.0.0.1` / `9088` |
| DB / client locale in passing run | `en_US.819` / `en_US.819` |

For this existing environment, skip database creation and go straight to
steps 4 and 5. Rebuild in step 3 if source, tests, or image dependencies changed.
Preserve the host's existing `.env.integration` when updating source files.

The base is Red Hat's `registry.access.redhat.com/ubi9/php-83:latest`.
UBI is a Red Hat maintained base, not a certification that this custom image
meets a particular hardening standard. Runtime commands use a non-root user,
a read-only filesystem, dropped capabilities, and no new privileges.
If your organization specifies an approved image digest, supply it through
`--build-arg BASE_IMAGE=registry/image@sha256:...` instead of the default tag.

## 1. Copy the files to the Podman host

Ensure the host has the source version you intend to test.
Transfer it with your usual file transfer tool, or use these commands from
PowerShell in this repository. Replace `YOUR_SSH_USER` with your Linux login
(the SSH user is not necessarily the database user `informix`).

```powershell
ssh YOUR_SSH_USER@10.116.165.44 'mkdir -p ~/laravel-informix'
scp -r composer.json phpunit.xml.dist .containerignore src config tests containers docs YOUR_SSH_USER@10.116.165.44:laravel-informix/
scp ../ibm.csdk.4.50.FC8.LNX.tar YOUR_SSH_USER@10.116.165.44:./
ssh YOUR_SSH_USER@10.116.165.44
```

Expected remote layout:

```text
~/ibm.csdk.4.50.FC8.LNX.tar
~/laravel-informix/composer.json
~/laravel-informix/containers/integration/Containerfile
```

## 2. Create a dedicated, logged database

Find your existing Informix container name and verify the published port:

```bash
podman ps --format 'table {{.Names}}\t{{.Ports}}'
uname -m
```

The supplied CSDK archive is Linux x86-64. The host should report `x86_64`.
Set the actual container name below; `INFORMIXSERVER=informix` is the server
name inside Informix, which can differ from the Podman container name.

```bash
IFX_CONTAINER=informix_ids
podman port "$IFX_CONTAINER"
```

Only create a database when provisioning a new test environment; the verified
`laravel_ifx_test` already exists. This command creates a logged database using
the verified environment's locale. Do not rerun before every test or drop an
existing database. Server tools need explicit paths/environment because
`INFORMIXDIR` was absent even when executing as the `informix` user.

```bash
podman exec -i --user informix \
  --env INFORMIXDIR=/opt/ibm/informix \
  --env INFORMIXSERVER=informix \
  --env INFORMIXSQLHOSTS=/opt/ibm/informix/etc/sqlhosts \
  --env LD_LIBRARY_PATH=/opt/ibm/informix/lib:/opt/ibm/informix/lib/esql \
  --env DB_LOCALE=en_US.819 --env CLIENT_LOCALE=en_US.819 \
  "$IFX_CONTAINER" /opt/ibm/informix/bin/dbaccess - - <<'SQL'
CREATE DATABASE laravel_ifx_test WITH LOG;
CLOSE DATABASE;
SQL
```

`WITH LOG` enables the transaction rollback tests. The suite uses the owner
`informix` and creates/drops its own randomly named `ifxt_u_*` / `ifxt_p_*`
tables. It never runs `migrate:fresh` or drops the database. A killed process
may leave its test tables behind.

Query the actual database locale rather than guessing it:

```bash
podman exec -i --user informix \
  --env INFORMIXDIR=/opt/ibm/informix \
  --env INFORMIXSERVER=informix \
  --env INFORMIXSQLHOSTS=/opt/ibm/informix/etc/sqlhosts \
  --env LD_LIBRARY_PATH=/opt/ibm/informix/lib:/opt/ibm/informix/lib/esql \
  "$IFX_CONTAINER" /opt/ibm/informix/bin/dbaccess sysmaster - <<'SQL'
SELECT dbs_dbsname, dbs_collate FROM sysdbslocale
WHERE dbs_dbsname = 'laravel_ifx_test';
SQL
```

The actual result for this database was `en_US.819`. Use that value in the
runner settings. A different database may use another locale; query it instead
of assuming UTF-8. Do not recreate or drop a database to fix a locale mismatch.

## 3. Build the test runner

```bash
cd ~/laravel-informix
podman build --pull=always \
  --secret id=csdk,src=../ibm.csdk.4.50.FC8.LNX.tar \
  --build-arg ACCEPT_CSDK_LICENSE=true \
  -f containers/integration/Containerfile \
  -t localhost/laravel-informix-integration:13 .
```

Pass `ACCEPT_CSDK_LICENSE=true` only after accepting the IBM CSDK terms.
Podman's build secret supplies the archive without adding it to the source
context or a `COPY` layer. The installed SDK is part of the resulting image;
keep it in your permitted private environment.

The build compiles PECL PDO_INFORMIX 1.3.7 against the supplied CSDK, verifies
that PHP loads it, resolves Laravel 13 dependencies, and runs the unit tests.
The final stage leaves the build toolchain behind. It needs network access to
Red Hat's registry/repositories, PECL, Composer, and Composer package sources.
The user reported a successful build with CSDK 4.50.FC8 and this UBI 9 runner.
Exact resolved package versions and the final image digest were not recorded.

FC8 also requires ncurses ABI 5. A separate Red Hat UBI 8.10 stage installs
`ncurses-compat-libs` and supplies the genuine `libncurses.so.5` and
`libtinfo.so.5` libraries under `/opt/ncurses5/lib` in both UBI 9 stages.
An `ld.so.conf.d` entry registers this location. UBI 9's ABI 6 libraries and
RPM packages are retained. This is a custom compatibility setup,
not a claim of Red Hat or IBM certification of FC8 on UBI 9.

## 4. Configure the connection locally on the host

```bash
test -f .env.integration || cp containers/integration/integration.env.example .env.integration
chmod 600 .env.integration
vi .env.integration
```

The template already contains:

```dotenv
INFORMIX_INTEGRATION=1
INFORMIX_HOST=127.0.0.1
INFORMIX_SERVICE=9088
INFORMIX_SERVER=informix
INFORMIX_DATABASE=laravel_ifx_test
INFORMIX_USERNAME=informix
INFORMIX_PASSWORD=REPLACE_LOCALLY
INFORMIX_DB_LOCALE=en_US.819
INFORMIX_CLIENT_LOCALE=en_US.819
```

Replace the password locally with your existing password. For Podman
`--env-file`, values are literal: do not put shell quotes around them.
The env file is ignored by Git and excluded from the image build context.

## 5. Run and remove the test container

```bash
podman run --rm \
  --network=host \
  --env-file .env.integration \
  --env INFORMIX_HOST=127.0.0.1 \
  --env INFORMIX_SERVICE=9088 \
  --read-only \
  --tmpfs /tmp:rw,nosuid,nodev,size=256m,mode=1777 \
  --cap-drop=all \
  --security-opt=no-new-privileges \
  localhost/laravel-informix-integration:13
```

This is the command used for the passing run on the same Linux host as Informix.
Host networking shares the host network namespace, making its published
`127.0.0.1:9088` reachable. It reduces network isolation; for another topology,
use a reachable address and the appropriate isolated network instead.
No runner application port needs publishing. It exits with PHPUnit's status; `--rm` removes the
runner container after completion. The image and test database remain for reuse.

The suite covers a real Laravel provider connection, schema create/drop and
column discovery, Laravel 13 fetch modes, CRUD, serial IDs, special-character
round trips, boolean conversion, batch inserts, aggregates, pagination,
transaction commit/rollback, Eloquent relationships/eager loading, and a join.
This is a starting integration suite, not exhaustive coverage of every driver
feature or concurrent insert behavior.

For just the unit tests in the same image:

```bash
podman run --rm --read-only \
  --tmpfs /tmp:rw,nosuid,nodev,size=256m,mode=1777 \
  --cap-drop=all --security-opt=no-new-privileges \
  localhost/laravel-informix-integration:13 --exclude-group integration
```

## Troubleshooting

- Prerequisite verification requires `libncurses.so.5`: use the updated
  Containerfile with its UBI 8 compatibility-library stage. UBI 9's
  `ncurses-libs` package alone provides ABI 6. Both the builder and runner need
  the ABI 5 libraries; copying only the installed CSDK is insufficient.
- `Error loading nativeAPI` followed by `libcrypt.so.1: cannot open shared
  object file`: UBI 9 needs `libxcrypt-compat` for this older library ABI.
  `libxcrypt` alone does not provide it. Both image stages explicitly install
  `libxcrypt-compat` and verify `/usr/lib64/libcrypt.so.1` exists. Copy the updated
  Containerfile to the build host and rebuild; the changed package-install layer
  invalidates the dependent cache automatically.
- CSDK build step exits with status 45: this final status alone does not identify
  the missing prerequisite or installer failure. The revised installer step
  prints launcher output, installer/debug log tails, and the library inventory
  before returning the original exit status. Copy both the updated Containerfile
  and `containers/integration/install-csdk.sh` to the host, rebuild, and inspect
  the output immediately above the Podman error. Save the build output with
  `set -o pipefail` followed by `podman build ... 2>&1 | tee csdk-build.log`.
  The image includes `file` for installer architecture/library detection plus
  explicit native-library dependencies. If the log specifically requires
  `libncurses.so.5`, `ncurses-libs` supplies ABI 6 and is not an ABI 5 replacement;
  the updated Containerfile supplies actual ABI 5 compatibility libraries from
  Red Hat UBI 8. Do not suppress prerequisite checks.
- Connection refused or timeout: check `podman port "$IFX_CONTAINER"`, the
  Informix listener, and host firewall rules for 9088. If the port is published
  only on host loopback, the runner's bridge cannot reach it through the LAN IP.
  The verified same-host command above uses `--network=host` and localhost.
  Error -908 is a general connection failure; if it persists, inspect server
  status and sqlhosts with explicit server paths/environment as above.
- Error -23197: the database locale and configured `INFORMIX_DB_LOCALE` differ;
  query `sysmaster:sysdbslocale` as above. This environment returned `en_US.819`;
  configuring both locale values as `en_US.819` resolved the reported error.
- `/bin/onstat` or `/etc/sqlhosts` not found: `INFORMIXDIR` was empty, so paths
  expanded incorrectly. Use `/opt/ibm/informix` explicitly for this server.
  That path differs from the runner's `/opt/informix` SDK installation.
- Error -329: the test database was not created or the name is wrong.
- Transactions unsupported: verify the database was created `WITH LOG`.
- Shared library load failure: run the image with `--entrypoint php` and
  arguments `--ri pdo_informix`; inspect SDK library dependencies in the build log.
- Installer prerequisite failure: inspect the reported missing library/package;
  do not automatically bypass the installer prerequisites check. FC8 is older
  than UBI 9, and may require a newer CSDK or an organization-approved compatible base.
- Permission denied while bootstrapping Laravel: keep `/tmp` writable; caches
  and test storage are redirected there for the read-only runtime.
- Failed tests are compatibility findings. The runner does not skip connection
  failures or turn assertion failures into successful test runs.

## References

- [Red Hat UBI PHP 8.3 image](https://catalog.redhat.com/en/software/containers/ubi9/php-83/673b346e1e5aff63c234f637)
- [PECL PDO_INFORMIX releases](https://pecl.php.net/package/pdo_informix)
- [Red Hat UBI 8 compatibility-library package](https://cdn-ubi.redhat.com/content/public/ubi/dist/ubi8/8/x86_64/baseos/os/Packages/n/)
- [Podman build secrets](https://docs.podman.io/en/latest/markdown/podman-build.1.html)
- [Informix database locales](https://www.ibm.com/docs/en/informix-servers/15.0.x?topic=database-use-create)
- [Informix sysdbslocale table](https://www.ibm.com/docs/sl/SSGU8G_12.1.0/com.ibm.adref.doc/ids_adr_0222.htm)
