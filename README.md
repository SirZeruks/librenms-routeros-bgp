# LibreNMS RouterOS BGP prefixes

[![Total installs](https://img.shields.io/packagist/dt/sirzeruks/librenms-routeros-bgp?label=installs)](https://packagist.org/packages/sirzeruks/librenms-routeros-bgp/stats)
[![Monthly installs](https://img.shields.io/packagist/dm/sirzeruks/librenms-routeros-bgp?label=installs%2Fmonth)](https://packagist.org/packages/sirzeruks/librenms-routeros-bgp/stats)
[![Latest version](https://img.shields.io/packagist/v/sirzeruks/librenms-routeros-bgp)](https://packagist.org/packages/sirzeruks/librenms-routeros-bgp)
[![GitHub stars](https://img.shields.io/github/stars/SirZeruks/librenms-routeros-bgp?style=flat)](https://github.com/SirZeruks/librenms-routeros-bgp/stargazers)
[![Page views](https://hits.sh/github.com/SirZeruks/librenms-routeros-bgp.svg?label=views)](https://hits.sh/github.com/SirZeruks/librenms-routeros-bgp/)
[![License](https://img.shields.io/github/license/SirZeruks/librenms-routeros-bgp)](LICENSE)

**BGP prefix counts for MikroTik RouterOS v7 in LibreNMS.**

LibreNMS shows BGP prefix graphs for Cisco, Juniper, Arista, Huawei and others, but **not for MikroTik**:
RouterOS v7 only publishes a small part of the BGP4-MIB over SNMP, without any prefix counters.
This plugin fetches the counts straight from the router (read-only) and stores them where LibreNMS
keeps prefix counts for every other vendor. The result:

- the normal **Routing → BGP → Prefixes** graphs work for your MikroTiks,
- the LibreNMS API `list_cbgp` returns MikroTik prefix counts,
- a **BGP prefixes** panel on the device overview page.

## Status

Early release. **SSH** is in daily use against 20+ production RouterOS v7 routers. All five connection methods
(SSH, REST over HTTP/HTTPS, RouterOS API, API-SSL) and the IPv6 support are tested against RouterOS 7.23.
Reports (good or bad) are welcome in the issues.

## Features

- **IPv6 BGP peers in LibreNMS**: LibreNMS cannot see MikroTik IPv6 BGP peers over SNMP; the plugin adds them as
  normal BGP peers with state, prefix and update graphs and Session Up/Down events (alert rules work)
- Choose how to reach each router: **REST API (HTTPS or HTTP), RouterOS API, API-SSL or SSH**
- **Custom ports** for networks that move services off the standard ports
- One **default connection** for all routers, plus **per-device overrides** (address, method, port, username, password, SSH key, TLS checking)
- Username/password or **SSH key** login; secrets stored **encrypted** with your LibreNMS key, never shown again
- **Test** button: see the router's BGP sessions and prefix counts before switching anything on
- **Read-only**: the plugin only ever reads from your routers (use a read-only router user to make that a guarantee)
- **Part of the normal LibreNMS device poll**: each router is read at the end of its own poll, queued and spread over the poller workers like everything else (no separate job)
- **Poll all now** button and `lnms routeros-bgp:poll` for on-demand runs, with live progress

## Requirements

| | |
|---|---|
| LibreNMS | a current release with `lnms plugin:add` |
| RouterOS | **v7** (v6 has a different BGP implementation and is not supported) |
| LibreNMS BGP discovery | the MikroTik's BGP peers must already show in LibreNMS (they do by default over SNMP) |
| Router access | a read-only user on each MikroTik and one service reachable from LibreNMS: `www-ssl`, `www`, `api-ssl`, `api` or `ssh` |

## Install / update (2 minutes)

Download the installer, read it, then run it as root on the LibreNMS server:

```bash
curl -fsSLO https://raw.githubusercontent.com/SirZeruks/librenms-routeros-bgp/main/install.sh
less install.sh
sudo bash install.sh            # install, or update to the newest release
sudo bash install.sh --uninstall
```

Or by hand, as the LibreNMS user:

```bash
cd /opt/librenms
sudo -u librenms ./lnms plugin:add sirzeruks/librenms-routeros-bgp
sudo -u librenms php artisan route:cache
```

Then in the web UI: **Overview → Plugins → Plugin Admin → routeros-bgp → Settings**.

The plugin survives LibreNMS's nightly updates: LibreNMS re-installs user plugins after each update.

## Set up (5 minutes)

1. **On each MikroTik** create a read-only user for LibreNMS:
   ```
   /user group add name=librenms-read policy=read,api,rest-api,ssh
   /user add name=librenms group=librenms-read password="CHANGE-ME" address=LIBRENMS-IP/32
   ```
2. **In the plugin settings** enter the username/password and choose how to connect (REST over HTTPS is the default).
3. Press **Test** next to a device. You should see its BGP sessions with prefix counts.
4. Tick **Collect prefix counts** and save. Graphs fill in within 5–10 minutes.

**The full step-by-step guide, router preparation for every connection method, custom ports,
troubleshooting and uninstall are in the [manual](docs/MANUAL.md).**

## Uninstall

Changed your mind? One command removes it, the same way it was installed:

```bash
sudo bash install.sh --uninstall
```

Or by hand, as the LibreNMS user:

```bash
cd /opt/librenms
sudo -u librenms ./lnms plugin:remove sirzeruks/librenms-routeros-bgp
sudo -u librenms php artisan route:cache
sudo -u librenms php artisan view:clear
```

LibreNMS keeps running normally; the plugin's pages and its part of the device poll are gone. Left behind, by design:

| What | How to remove it |
|---|---|
| Stored prefix counts and prefix graphs | [Manual, section 10](docs/MANUAL.md#10-update-disable-uninstall) |
| IPv6 peers the plugin added | removed automatically at LibreNMS's next discovery of each router |
| The plugin's settings, **including the encrypted router password** | in `sudo -u librenms ./lnms db`: `DELETE FROM plugins WHERE plugin_name = 'routeros-bgp';` |
| The read-only user on your routers | `/user remove [find name=librenms]` and `/user group remove [find name=librenms-read]` |

## How it works

```
 LibreNMS poller (dispatcher service / poller-wrapper)
   └─ polls a RouterOS device ──► fires DevicePolled ──► this plugin (same worker, same queue)
        ├─ read /routing/bgp/session (name, remote.address, prefix-count, established)
        │     over REST | API | API-SSL | SSH   (read-only)
        └─ store per peer:  bgpPeers_cbgp  +  rrd/<device>/cbgp-<peer>.<afi>.unicast.rrd
                              (same table and RRD format the LibreNMS core uses for Cisco etc.)
```

## Support

- Questions and bugs: [GitHub issues](https://github.com/SirZeruks/librenms-routeros-bgp/issues)
- LibreNMS community thread: *link added on release*

## Disclaimer

**Use at your own risk.** This plugin is provided "as is", without warranty of any kind (GPL-3.0, sections 15 and 16).
The author accepts no responsibility or liability for any damage, outage or security incident arising from its use,
including when your LibreNMS server, its database or its backups are compromised. Securing your server and routers is
your responsibility. **Always give the plugin a read-only router user limited to the LibreNMS server's address**, so a
leaked password cannot change your routers. Read [Security and your responsibility](docs/MANUAL.md#9-security-and-your-responsibility)
before you deploy it.

## License

GPL-3.0-or-later, the same as LibreNMS. See [LICENSE](LICENSE).
