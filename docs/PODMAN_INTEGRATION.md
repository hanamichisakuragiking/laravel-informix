# Podman integration tests on 10.116.165.44

This setup builds a disposable PHP 8.3 / Laravel 13 test runner and connects it
to your existing Informix container through `10.116.165.44:9088`. It does not
start another Informix server. Run the Linux commands below on that host, as
the same Linux user that manages the existing Informix container.

The base is Red Hat's `registry.access.redhat.com/ubi9/php-83:latest`.
UBI is a Red Hat maintained base, not a certification that this custom image
meets a particular hardening standard. Runtime commands use a non-root user,
a read-only filesystem, dropped capabilities, and no new privileges.
If your organization specifies an approved image digest, supply it through
`--build-arg BASE_IMAGE=registry/image@sha256:...` instead of the default tag.

## 1. Copy the files to the Podman host

The source files added here are local; they have not been pushed to GitHub.
Transfer them with your usual file transfer tool, or use these commands from
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
IFX_CONTAINER=YOUR_EXISTING_CONTAINER_NAME
podman exec "$IFX_CONTAINER" printenv INFORMIXSERVER
```

Create only the new test database, explicitly selecting UTF-8 for this database.
Run this once, not before every test. If `dbaccess` is not on PATH inside the
container, substitute its full path under the server's `INFORMIXDIR/bin`.

```bash
podman exec -i --user informix \
  --env INFORMIXSERVER=informix \
  --env DB_LOCALE=en_US.utf8 --env CLIENT_LOCALE=en_US.utf8 \
  "$IFX_CONTAINER" dbaccess - - <<'SQL'
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
podman exec -i --user informix --env INFORMIXSERVER=informix \
  "$IFX_CONTAINER" dbaccess sysmaster - <<'SQL'
SELECT dbs_dbsname, dbs_collate FROM sysdbslocale
WHERE dbs_dbsname = 'laravel_ifx_test';
SQL
```

For the UTF-8 database just created, the server may show the numeric UTF-8
locale name `en_US.57372`; `en_US.utf8` is its common alias. If the database
already existed with a different locale (for example `en_US.819`), use its
actual locale in `INFORMIX_DB_LOCALE`; do not recreate or drop it to fix a mismatch.

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
The CSDK 4.50.FC8 / UBI 9 combination must still be validated on your host;
these files have not been built remotely.

## 4. Configure the connection locally on the host

```bash
cp containers/integration/integration.env.example .env.integration
chmod 600 .env.integration
vi .env.integration
```

The template already contains:

```dotenv
INFORMIX_INTEGRATION=1
INFORMIX_HOST=10.116.165.44
INFORMIX_SERVICE=9088
INFORMIX_SERVER=informix
INFORMIX_DATABASE=laravel_ifx_test
INFORMIX_USERNAME=informix
INFORMIX_PASSWORD=REPLACE_LOCALLY
INFORMIX_DB_LOCALE=en_US.utf8
INFORMIX_CLIENT_LOCALE=en_US.utf8
```

Replace the password locally with your existing password. For Podman
`--env-file`, values are literal: do not put shell quotes around them.
The env file is ignored by Git and excluded from the image build context.

## 5. Run and remove the test container

```bash
podman run --rm \
  --env-file .env.integration \
  --read-only \
  --tmpfs /tmp:rw,nosuid,nodev,size=256m,mode=1777 \
  --cap-drop=all \
  --security-opt=no-new-privileges \
  localhost/laravel-informix-integration:13
```

No application port needs publishing. The runner makes an outbound connection
to the existing database. It exits with PHPUnit's status; `--rm` removes the
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
  use a supported compatibility package from approved repositories or a compatible
  CSDK version. Do not symlink ABI 6 to ABI 5 or suppress prerequisite checks.
- Connection refused or timeout: check `podman port "$IFX_CONTAINER"`, the
  Informix listener, and host firewall rules for 9088. If the port is published
  only on host loopback, the runner's bridge cannot reach it through the LAN IP.
  For a diagnostic run on the same Linux host, add `--network=host` and
  `--env INFORMIX_HOST=127.0.0.1`; this shares host networking and reduces isolation.
- Error -23197: the database locale and configured `INFORMIX_DB_LOCALE` differ;
  query `sysmaster:sysdbslocale` as above.
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
- [Podman build secrets](https://docs.podman.io/en/latest/markdown/podman-build.1.html)
- [Informix database locales](https://www.ibm.com/docs/en/informix-servers/15.0.x?topic=database-use-create)
- [Informix sysdbslocale table](https://www.ibm.com/docs/sl/SSGU8G_12.1.0/com.ibm.adref.doc/ids_adr_0222.htm)
