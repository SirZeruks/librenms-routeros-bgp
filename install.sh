#!/usr/bin/env bash
# Install, update or remove the RouterOS BGP prefixes plugin for LibreNMS.
#
#   sudo bash install.sh              install, or update to the newest release
#   sudo bash install.sh --uninstall  remove the plugin
#
# Options:  --dir <path>   LibreNMS directory (default /opt/librenms)
#
# What it does: runs LibreNMS's own `lnms plugin:add` / `plugin:remove` as the LibreNMS user, refreshes the
# route cache and enables the plugin. It changes nothing else on the system and never touches your routers.
set -euo pipefail

PACKAGE="sirzeruks/librenms-routeros-bgp"
PLUGIN="routeros-bgp"
DIR="/opt/librenms"
ACTION="install"

while [ $# -gt 0 ]; do
    case "$1" in
        --dir) DIR="${2:?--dir needs a path}"; shift 2 ;;
        --uninstall) ACTION="uninstall"; shift ;;
        -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1 (see --help)" >&2; exit 2 ;;
    esac
done

fail() { echo "ERROR: $*" >&2; exit 1; }

[ -f "$DIR/lnms" ] || fail "LibreNMS not found in $DIR (use --dir <path>)."
OWNER="$(stat -c '%U' "$DIR/lnms")"
[ -n "$OWNER" ] && [ "$OWNER" != "root" ] || fail "Could not determine the LibreNMS user (owner of $DIR/lnms)."

if [ "$(id -un)" = "$OWNER" ]; then
    as_lnms() { "$@"; }
elif [ "$(id -u)" -eq 0 ]; then
    if command -v sudo >/dev/null 2>&1; then
        as_lnms() { sudo -u "$OWNER" -H "$@"; }
    elif command -v runuser >/dev/null 2>&1; then
        as_lnms() { runuser -u "$OWNER" -- "$@"; }
    else
        fail "No sudo or runuser here. Run this script as the LibreNMS user '$OWNER' instead (with Docker: docker exec -u $OWNER <container> bash install.sh)."
    fi
else
    fail "Run as root (sudo bash install.sh) or as the LibreNMS user '$OWNER'."
fi

cd "$DIR"
as_lnms ./lnms list plugin 2>/dev/null | grep -q 'plugin:add' \
    || fail "This LibreNMS has no 'lnms plugin:add'. Update LibreNMS first."

if [ "$ACTION" = "uninstall" ]; then
    echo "Removing $PACKAGE ..."
    as_lnms ./lnms plugin:remove "$PACKAGE"
    as_lnms php artisan route:cache >/dev/null
    as_lnms php artisan view:clear >/dev/null
    echo "Removed. Stored prefix counts stay in LibreNMS; see the manual (section 10) to delete them."
    exit 0
fi

if [ -f composer.plugins.json ] && grep -q "\"$PACKAGE\"" composer.plugins.json; then
    echo "Updating $PACKAGE to the newest release ..."
else
    echo "Installing $PACKAGE ..."
fi
as_lnms ./lnms plugin:add "$PACKAGE"

# LibreNMS caches its routes and compiled pages; refresh both so the new version's pages are served.
as_lnms php artisan route:cache >/dev/null
as_lnms php artisan view:clear >/dev/null
as_lnms ./lnms plugin:enable "$PLUGIN" >/dev/null 2>&1 || true

echo
echo "Done. Next steps:"
echo "  1. Create a READ-ONLY user on each MikroTik (manual, section 4.1)."
echo "  2. In LibreNMS: Overview -> Plugins -> Plugin Admin -> $PLUGIN -> Settings."
echo "  3. Enter the login, press Test on a device, then tick 'Collect prefix counts' and save."
echo "Manual: https://github.com/SirZeruks/librenms-routeros-bgp/blob/main/docs/MANUAL.md"
