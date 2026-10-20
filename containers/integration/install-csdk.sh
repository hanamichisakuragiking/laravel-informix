#!/bin/bash
set -euo pipefail

if [[ "${ACCEPT_CSDK_LICENSE:-false}" != true ]]; then
    echo 'CSDK license acceptance is required: --build-arg ACCEPT_CSDK_LICENSE=true' >&2
    exit 1
fi
if [[ "$(uname -m)" != x86_64 ]]; then
    echo 'This CSDK archive requires Linux x86-64.' >&2
    exit 1
fi
if [[ ! -r /run/secrets/csdk ]]; then
    echo 'CSDK archive is missing: pass --secret id=csdk,src=../ibm.csdk.4.50.FC8.LNX.tar' >&2
    exit 1
fi

mkdir -p /tmp/csdk
tar -xf /run/secrets/csdk -C /tmp/csdk
cd /tmp/csdk

# Keep prerequisite checking enabled. Print diagnostics before the failed build
# discards the container, including output from the native installer launcher.
status=0
./installclientsdk -i silent \
    -DLICENSE_ACCEPTED=TRUE \
    -DUSER_INSTALL_DIR=/opt/informix \
    -DLOG_FILE=/tmp/csdk-install.log \
    -DDEBUG_LEVEL=9 \
    -DDEBUG_FILE=/tmp/csdk-debug.log \
    > /tmp/csdk-launcher.log 2>&1 || status=$?

cat /tmp/csdk-launcher.log
if [[ "$status" -ne 0 ]] || \
    [[ ! -f /opt/informix/incl/cli/infxcli.h ]] || \
    [[ ! -d /opt/informix/lib/cli ]]; then
    echo "CSDK installation failed; installer exit status: $status" >&2
    for log in /tmp/csdk-install.log /tmp/csdk-debug.log \
        /tmp/iad_act /tmp/iad_act.log \
        /tmp/bundle_install.stdout /tmp/bundle_install.stderr; do
        if [[ -f "$log" ]]; then
            printf '\n--- %s (last 120 lines) ---\n' "$log"
            tail -n 120 "$log"
        fi
    done
    # InstallAnywhere can also write logs in the install directory or HOME.
    for directory in /opt/informix "${HOME:-/root}" /tmp/csdk; do
        if [[ -d "$directory" ]]; then
            find "$directory" -maxdepth 2 -type f -name '*Install*.log' \
                -print -exec tail -n 120 {} \;
        fi
    done
    printf '\n--- OS and registered shared libraries ---\n'
    cat /etc/os-release
    ldconfig -p || true
    if [[ "$status" -eq 0 ]]; then
        exit 1
    fi
    exit "$status"
fi

rm -rf /tmp/csdk
