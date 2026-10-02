# Changelog

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
