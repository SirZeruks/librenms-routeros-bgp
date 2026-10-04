# Changelog

## 0.3.2 — 2026-10-04

- Fix: the plugin could not be installed or updated on LibreNMS master, which now ships phpseclib 4 (via
  laravel/socialite): `lnms plugin:add` and the nightly `daily.sh` stopped with a dependency conflict (issue #1).
  The plugin now accepts phpseclib 3 or 4, and the SSH transport works with either version.

## 0.3.1 — 2026-10-02

- Fix: LibreNMS's *Validate* page reported "database schema may be wrong — extra table (routeros_bgp_managed_peers)",
  and its Fix button would have deleted that table. The plugin no longer creates a table: its record of the IPv6 peers
  it added now lives in its own settings entry. On update, 0.3.0's table is moved over and dropped automatically
  (same peer IDs). Validate is clean again.
- After discovery, IPv6 peers are restored by reading the router again, keeping their IDs and edited descriptions.
- Two reads of the same router at once (Poll all now and the device poll) can no longer add an IPv6 peer twice.
- README: an Uninstall section, including how to delete the stored settings and the encrypted router password.

## 0.3.0 — 2026-10-02

First public release.

- BGP prefix counts for MikroTik RouterOS v7, collected as part of the normal LibreNMS device poll.
- Connection methods: REST over HTTPS/HTTP, RouterOS API, API-SSL, SSH (password or key); custom ports.
- Global defaults plus per-device overrides (address, method, port, login, TLS checking).
- Stored where LibreNMS keeps prefix counts for other vendors: the native BGP prefix graphs and the `list_cbgp` API work.
- Settings page with Test, Poll now and Poll all now (live progress); device overview panel; `lnms routeros-bgp:poll`.
- IPv6 BGP sessions (invisible to LibreNMS over SNMP) added as LibreNMS BGP peers, kept across discovery with the same IDs, with Session Up/Down events.
- Update now button (checks Packagist, installs the newest release in the background).
- Installer / updater script.
