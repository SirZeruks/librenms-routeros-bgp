# RouterOS BGP prefixes for LibreNMS — Manual

This manual takes you from nothing to working MikroTik BGP prefix graphs in LibreNMS, step by step.

**Contents**

1. [What you get](#1-what-you-get)
2. [Before you start](#2-before-you-start)
3. [Install the plugin](#3-install-the-plugin)
4. [Prepare your MikroTik routers](#4-prepare-your-mikrotik-routers)
5. [Configure the plugin](#5-configure-the-plugin)
6. [Check that it works](#6-check-that-it-works)
7. [Routers that are different (device settings)](#7-routers-that-are-different-device-settings)
8. [Troubleshooting](#8-troubleshooting)
9. [Security and your responsibility](#9-security-and-your-responsibility)
10. [Update, disable, uninstall](#10-update-disable-uninstall)
11. [Limitations and FAQ](#11-limitations-and-faq)

---

## 1. What you get

LibreNMS discovers MikroTik BGP sessions over SNMP, but RouterOS v7 does not publish prefix counts over SNMP,
so the **Prefixes** graphs stay empty. After this plugin is set up:

| Where | What you see |
|---|---|
| Device → **Routing → BGP** | the *Prefixes: IPv4 unicast / IPv6 unicast* views and graphs per peer |
| **Routing → BGP** (all devices) | prefix graphs for MikroTik peers, next to your other vendors |
| Device **Overview** | a *BGP prefixes* panel: each peer, its state and its current prefix count (with the change since the last poll) |
| LibreNMS API | `GET /api/v0/routing/bgp/cbgp` returns `AcceptedPrefixes` for MikroTik peers |
| **IPv6 BGP peers** | LibreNMS cannot see MikroTik IPv6 BGP peers over SNMP at all. The plugin adds them as normal LibreNMS BGP peers: state, AS, uptime, prefix and update graphs, and *BGP Session Up/Down* events (section 11, *IPv6 peers*). |

The plugin reads each router **as part of that router's normal LibreNMS poll**, so it follows your poller's queue and interval. It only **reads**; it never changes router configuration.

## 2. Before you start

Check these four things.

**a) LibreNMS version.** Any current release with plugin support. Check you have the plugin command:

```bash
cd /opt/librenms
sudo -u librenms ./lnms list plugin
```

You should see `plugin:add`, `plugin:remove`, `plugin:enable`, `plugin:disable`.

**b) The LibreNMS poller is running.** The plugin collects at the end of each device poll, so it needs nothing
of its own: if your devices are being polled (graphs updating), the plugin will run too. It works with both the
dispatcher service and the classic `poller-wrapper.py` cron.

**c) LibreNMS already shows your MikroTik BGP sessions.** Open a MikroTik device → **Routing → BGP**.
If you see your peers (state, AS, uptime) you're good. If not, enable the *bgp-peers* discovery and
poller modules for the device (device → Settings (cog) → Modules) and wait for the next discovery run.
The plugin only adds prefix counts to peers LibreNMS already knows.

**d) RouterOS v7.** Check with `/system resource print`. RouterOS v6 is not supported.

## 3. Install the plugin

**Easiest: the installer.** On the LibreNMS server, download it, read it, run it as root:

```bash
curl -fsSLO https://raw.githubusercontent.com/SirZeruks/librenms-routeros-bgp/main/install.sh
less install.sh
sudo bash install.sh                       # add --dir /path if LibreNMS is not in /opt/librenms
```

It runs the steps below for you (as the LibreNMS user) and enables the plugin. **By hand** instead:

```bash
cd /opt/librenms
sudo -u librenms ./lnms plugin:add sirzeruks/librenms-routeros-bgp
```

This downloads the plugin with Composer. Then refresh LibreNMS's cached list of web pages, so the plugin's
settings page can save (LibreNMS rebuilds this cache on every update; a new plugin needs it once by hand):

```bash
sudo -u librenms php artisan route:cache
```

Then enable it in the web UI:

1. Go to **Overview → Plugins → Plugin Admin** (you need to be a LibreNMS admin).
2. Find **routeros-bgp** and press **Enable**.
3. Press **Settings** next to it. This is the plugin's settings page; everything else is done there.

> If *routeros-bgp* does not appear in the list, or saving the settings gives *404 Not Found*, run the
> `route:cache` command above again and reload the page. If the settings page still looks like an older version
> after an update, clear LibreNMS's compiled pages: `sudo -u librenms php artisan view:clear`
> (the installer and the **Update now** button do both for you).

## 4. Prepare your MikroTik routers

Each router needs **a read-only user** and **one service LibreNMS can reach**. Do this on every
MikroTik you want prefix counts for (or push the same commands with your usual config tooling).

### 4.1 Create a read-only user

Paste in the RouterOS terminal. Change `CHANGE-ME` and replace `LIBRENMS-IP` with the address the
LibreNMS server connects from:

```
/user group add name=librenms-read policy=read,api,rest-api,ssh
/user add name=librenms group=librenms-read password="CHANGE-ME" address=LIBRENMS-IP/32
```

- `read` lets the user look at configuration and status but not change anything.
- `api` and `rest-api` are needed for the REST and API methods; `ssh` for the SSH method. You may
  remove the ones you don't use.
- `address=` makes the router refuse this user from anywhere else.

### 4.2 Choose a connection method

Pick **one** method for most routers. You can still use another method for individual routers later.

| Method | Router service | Standard port | Encrypted | Notes |
|---|---|---|---|---|
| **REST API over HTTPS** (recommended) | `www-ssl` | 443 | yes | needs a certificate on the router (4.3) |
| REST API over HTTP | `www` | 80 | **no** | only on a trusted management network |
| **RouterOS API-SSL** | `api-ssl` | 8729 | yes | certificate recommended (4.3) |
| RouterOS API | `api` | 8728 | **no** | only on a trusted management network |
| **SSH** | `ssh` | 22 | yes | password or SSH key |

Enable the service you chose and allow only LibreNMS to use it, for example for REST over HTTPS:

```
/ip service set www-ssl disabled=no address=LIBRENMS-IP/32
```

(Use `api-ssl`, `api`, `www` or `ssh` instead of `www-ssl` for the other methods. Check with `/ip service print`.)

If your router's firewall blocks input to that port, add an accept rule for the LibreNMS IP **above** your drop rules:

```
/ip firewall filter add chain=input protocol=tcp src-address=LIBRENMS-IP dst-port=443 action=accept comment="LibreNMS BGP plugin" place-before=0
```

### 4.3 Certificate for HTTPS / API-SSL

`www-ssl` does not start without a certificate. If the router has none, create a small local CA and sign the
router's certificate with it (current RouterOS refuses to sign a server certificate without a CA):

```
/certificate add name=local-ca common-name=local-ca days-valid=3650 key-usage=key-cert-sign,crl-sign
/certificate sign local-ca
/certificate add name=router-mgmt common-name=router-mgmt days-valid=3650 key-usage=digital-signature,key-encipherment,tls-server
/certificate sign router-mgmt ca=local-ca
/ip service set www-ssl certificate=router-mgmt disabled=no
/ip service set api-ssl certificate=router-mgmt disabled=no
```

Signing takes a few seconds. With a self-signed certificate leave **Verify the router's TLS certificate**
**off** in the plugin (the connection is still encrypted). If your routers have certificates from your own
CA that the LibreNMS server trusts, switch verification on.

### 4.4 Custom ports

If your network runs services on non-standard ports, e.g.

```
/ip service set www-ssl port=8443
```

enter the same port in the plugin (**Port** field, in the defaults or per device). Empty means the standard port.

### 4.5 SSH key login (optional)

For SSH you can use a key instead of a password. Create a key pair on any machine (`ssh-keygen -t ed25519`
or `-t rsa -b 4096`; check which key types your RouterOS version accepts), upload the **public** key to the
router (Files) and import it:

```
/user ssh-keys import public-key-file=librenms.pub user=librenms
```

Paste the **private** key into the plugin's *SSH private key* field. If the key has a passphrase, put it in the
*Password* field.

### 4.6 Quick test from the LibreNMS server (optional)

For REST over HTTPS:

```bash
curl -k -u librenms:CHANGE-ME "https://ROUTER-IP/rest/routing/bgp/session?.proplist=name,remote.address,prefix-count"
```

You should get a JSON list with your sessions. Don't leave the password in your shell history on shared servers.

## 5. Configure the plugin

Open **Overview → Plugins → Plugin Admin → routeros-bgp → Settings**.

### 5.1 Default connection

The defaults apply to every RouterOS device unless it has its own settings.

| Field | What to enter |
|---|---|
| **Collect prefix counts** | Leave **off** until a test works (step 6). This is the master switch. |
| **Add IPv6 BGP sessions to LibreNMS** | On by default. Adds the IPv6 sessions SNMP cannot show as LibreNMS BGP peers (section 11). Untick to remove them again on the next poll. |
| **Connect with** | The method you prepared in 4.2. |
| **Port** | Empty = standard port of the method. Fill in for custom ports. |
| **Username** | `librenms` (or the user you created). |
| **Password** | The password. After saving it shows *stored*; leave empty to keep it. |
| **SSH private key** | Only for SSH key login. |
| **Verify the router's TLS certificate** | Off for self-signed certificates. |
| **Timeout** | Seconds to wait for a router (default 10). |

Press **Save**.

### 5.2 Devices list

Below the defaults is the list of RouterOS devices that run BGP as far as LibreNMS knows (it found BGP peers on
them, or at least their BGP local AS, which covers routers with only IPv6 sessions). **Poll all now** at the
top reads every router immediately in the background and shows its progress ("12 of 24 done"); the page reloads when
it finishes. Each row has:

- **Connects with** — the method, address, port and user that will be used (after defaults and device settings are combined),
- **Last result** — the result of the last read, with the error if it failed,
- **Test** — read the router now and show what was found, **without storing** anything,
- **Poll now** — read and store immediately,
- **Device settings** — this router's own settings (section 7).

## 6. Check that it works

1. Press **Test** on one device. A table appears with the router's BGP sessions:

   | Session | Remote | State | Prefixes | In LibreNMS |
   |---|---|---|---|---|
   | peer1-1 | 192.0.2.1 | established | 1200 | yes |

   *In LibreNMS = no* means LibreNMS has not discovered that peer yet; it is skipped until it has.
2. If the test fails, see [Troubleshooting](#8-troubleshooting) — the message says what went wrong.
3. When the test works, tick **Collect prefix counts** in the defaults and **Save**.
4. Press **Poll now** on the device (or **Poll all now** for every router), or wait for the next device poll.
5. Open the device → **Routing → BGP**. A **Prefixes: IPv4 Unicast** (and/or IPv6) view appears. Graphs
   need a few polls (10–15 minutes) before lines show.

You can also run the poller from the command line, which is handy for checking many routers at once:

```bash
cd /opt/librenms
sudo -u librenms ./lnms routeros-bgp:poll --test            # every device, read only, show sessions
sudo -u librenms ./lnms routeros-bgp:poll --test 16         # one device by ID or hostname
sudo -u librenms ./lnms routeros-bgp:poll -v                # read and store every router now (what Poll all now runs)
```

## 7. Routers that are different (device settings)

Press **Device settings** next to a device. Every field left empty uses the default.

| Field | Use it when |
|---|---|
| **Collect from this device** | Untick to skip this router. |
| **Address** | The router's API/SSH is on another IP or name than the one LibreNMS polls with SNMP. |
| **Connect with** | This router uses another method (e.g. SSH because `www-ssl` is not set up). |
| **Port** | This router uses a different port. |
| **Username / Password / SSH key** | This router has a different login. |
| **Verify TLS certificate** | This router differs from the default. |

Routers with their own settings show a **custom** label. **Use defaults** removes the device's settings.

## 8. Troubleshooting

| Message | Cause and fix |
|---|---|
| `No username configured` | Fill in *Username* in the defaults (or device settings). |
| `REST login refused (401)` | Wrong username/password, or the user group lacks `rest-api` — see 4.1. Also check the user's `address=` allows the LibreNMS IP. |
| `REST request failed (HTTP 500) ... not allowed` | The user group lacks the `api` policy (REST needs both `api` and `rest-api`). |
| `REST connection failed: ... Connection refused` / `timed out` | Service disabled, wrong port, or blocked: check `/ip service print` (enabled? `address=` includes LibreNMS?) and the router firewall. |
| `REST connection failed: ... SSL` / `certificate` | `www-ssl` has no certificate (4.3), or *Verify TLS* is on with a self-signed certificate. |
| `REST reply was not a JSON list` | Not RouterOS v7, or something else answers on that port. |
| `API login refused` / `invalid user name or password` | Wrong login or the group lacks `api`. |
| `API connection ... failed` | `api`/`api-ssl` disabled, wrong port or firewalled. |
| `API read timed out` with API-SSL | Give `api-ssl` a certificate (4.3) and try again. |
| `SSH login refused` | Wrong login/key, or the group lacks `ssh`. |
| `RouterOS rejected the read command` | RouterOS v6 — not supported. |
| Test works but **In LibreNMS = no** | LibreNMS has not discovered that peer: check the *bgp-peers* module (2c) and run discovery: `sudo -u librenms ./lnms device:discover <device> -m bgp-peers`. |
| Device not in the plugin's list | LibreNMS has no BGP peers for it, or its OS is not detected as `routeros`. |
| Graphs empty after **Poll now** | RRD graphs need several 5-minute points. Check *Last result* is OK and wait 15 minutes. |
| Nothing updates by itself, but **Poll now** works | *Collect prefix counts* is off, the device is down in LibreNMS, or the device is not being polled. Check the poller log: each collection writes a `routeros-bgp:` line. |
| Passwords stopped working after moving LibreNMS | Secrets are encrypted with the LibreNMS `APP_KEY`. On a new key, enter the passwords again. |

More detail is in `logs/librenms.log`. If a plugin page errors, LibreNMS disables the plugin and shows a
notification; set `lnms config:set plugins.show_errors true` to see the error on the page.

## 9. Security and your responsibility

> **Use at your own risk.** This plugin is free software, provided **"as is", without warranty of any kind**,
> under the GNU General Public License v3 (see [LICENSE](../LICENSE), sections 15 and 16). The author accepts
> **no responsibility or liability** for any damage, outage, data loss or security incident arising from its use,
> including when the LibreNMS server, its database, its backups or the network it runs on are compromised.
> **Securing your LibreNMS server, its accounts, its backups and your routers is your responsibility.**
> Test it in your own environment before relying on it.

### 9.1 What the plugin does on your routers

- **It only reads.** The REST and API methods only call `print` on `/routing/bgp/session`. The SSH method runs
  this fixed script, which only reads:
  ```
  :foreach s in=[/routing/bgp/session find] do={:local o "RBGP";:foreach p in={"name";"remote.address";"prefix-count";"established";"remote.as";"local.address";"uptime";"remote.messages";"local.messages";"stopped"} do={:local v ""; :do {:set v [/routing/bgp/session get $s $p]} on-error={};:set o ($o . "|" . [:tostr $v])}; :put $o}
  ```
  (`:set` here only fills a script variable; the only router commands are `find` and `get` on
  `/routing/bgp/session`.)
  Nothing you type in the settings is ever put into a router command.
- **"Read-only" is a property of the plugin's code, not a guarantee.** Code can be altered: by someone with access
  to the LibreNMS server, or by a tampered copy of the plugin. Whatever the code does, a router can only be changed
  if the **router account** allows it. That is why the next point matters most.

### 9.2 The one setting that really protects your routers

**Give the plugin its own read-only user on each router, limited to the LibreNMS server's address** (section 4.1):

```
/user group add name=librenms-read policy=read,ssh,api,rest-api
/user add name=librenms group=librenms-read password="CHANGE-ME" address=LIBRENMS-IP/32
```

With that account, even a leaked password or modified code **cannot change anything** on the router, and the
login **does not work from anywhere but the LibreNMS server**. **Do not use an account that has write rights**
(an admin login, or one shared with another system): if it is exposed, whoever has it can change your routers.

### 9.3 How the password is stored, and what that does and does not protect

- Passwords and SSH keys are stored in the LibreNMS database (table `plugins`, column `settings`), **encrypted**
  (AES-256-CBC with an integrity check) with your LibreNMS `APP_KEY` from `/opt/librenms/.env`. The username is
  stored in plain text.
- They are **never shown again** in the web page after saving, never sent to the browser and never written to logs.
- **This protects against:** a copy of the database or a database backup on its own, someone looking at the web
  page, and the logs.
- **It does NOT protect against:**
  - **anyone with full access to the LibreNMS server** (root or the `librenms` account): they can read both the
    database and the key, and therefore decrypt the password. This is the same position as most monitoring and
    backup tools, which store device passwords readable by the server they run on;
  - **a LibreNMS administrator acting in bad faith**: an admin can point a device's *Address* at a machine they
    control and press *Test*, and the plugin will log in there with the stored password. Only give LibreNMS admin
    rights to people you would trust with the router login;
  - **someone in the network path pretending to be a router**: SSH host keys and (by default) TLS certificates are
    not verified, so on an untrusted network the password could be captured in transit.
- If the LibreNMS `APP_KEY` changes (for example a rebuild without restoring `.env`), the stored passwords can no
  longer be decrypted; enter them again.

### 9.4 Good practice

- Use the read-only, address-restricted router user above. This matters more than everything else in this section.
- Keep the router management network (and the LibreNMS server) separate from untrusted networks.
- Prefer encrypted methods (SSH, HTTPS, API-SSL). Plain `api` and `www` send the password unencrypted.
- Turn on TLS verification if your routers have certificates the LibreNMS server trusts.
- Limit who has root, `librenms` and LibreNMS admin access; protect database backups like the server itself.
- Only LibreNMS admins (the `plugin.admin` permission) can open the plugin settings or run tests.

## 10. Update, disable, uninstall

**Update** to the newest version: run the installer again (`sudo bash install.sh`), or by hand:

```bash
cd /opt/librenms
sudo -u librenms ./lnms plugin:add sirzeruks/librenms-routeros-bgp
sudo -u librenms php artisan route:cache
```

LibreNMS's own nightly update also re-installs the plugin, so it survives LibreNMS updates.

**Pause collection:** untick *Collect prefix counts* and save. **Disable** the plugin: Plugin Admin → Disable.

**Uninstall:** `sudo bash install.sh --uninstall`, or by hand:

```bash
cd /opt/librenms
sudo -u librenms ./lnms plugin:remove sirzeruks/librenms-routeros-bgp
```

The prefix counts already stored stay in LibreNMS (they no longer update). To remove them as well:

1. Open the LibreNMS database console with `sudo -u librenms ./lnms db` and run:
   ```sql
   -- which MikroTik devices have stored prefix counts (note the hostnames for step 2)
   SELECT DISTINCT d.hostname FROM bgpPeers_cbgp c JOIN devices d ON d.device_id = c.device_id WHERE d.os = 'routeros';
   -- remove the stored counts for MikroTik devices only
   DELETE c FROM bgpPeers_cbgp c JOIN devices d ON d.device_id = c.device_id WHERE d.os = 'routeros';
   ```
2. Remove the graph files of those devices (one line per hostname from step 1):
   ```bash
   ls /opt/librenms/rrd/HOSTNAME/cbgp-*.rrd      # check what will be removed
   sudo rm /opt/librenms/rrd/HOSTNAME/cbgp-*.rrd
   ```
   Only do this for the MikroTik hostnames listed in step 1.

3. IPv6 peers the plugin added stay in LibreNMS until its next discovery of each router, which removes them
   (LibreNMS cannot see them itself).
4. The plugin's settings stay in LibreNMS's `plugins` table after removal (so a reinstall picks up where you left
   off). They include the **encrypted router password**. To delete them, in `sudo -u librenms ./lnms db`:
   ```sql
   DELETE FROM plugins WHERE plugin_name = 'routeros-bgp';
   ```

Then remove the read-only user from your routers if you no longer need it:

```
/user remove [find name=librenms]
/user group remove [find name=librenms-read]
```

## 11. Limitations and FAQ

**Why not SNMP?** RouterOS v7 implements only the basic BGP4-MIB peer table (state, addresses, AS, message
counters, uptime). There is no prefix counter in it, no BGP4-V2-MIB and no BGP section in the MIKROTIK-MIB.
MikroTik has been asked to add it; until then the router's own API is the only source.

**Which prefix count is it?** RouterOS reports one `prefix-count` per session — the prefixes received from
the peer. The plugin shows it as *accepted* prefixes for the peer's address family (IPv4 or IPv6 unicast).
Advertised, denied and withdrawn counts are not available and show as 0.

**IPv6 peers?** LibreNMS finds MikroTik BGP peers over SNMP (BGP4-MIB), and that MIB can only describe IPv4 peers,
so on its own LibreNMS never shows a MikroTik IPv6 BGP session. With **Add IPv6 BGP sessions to LibreNMS** on
(the default), the plugin adds each IPv6 session as a normal LibreNMS BGP peer:

- remote AS, state (established / idle), admin status, local address, uptime and message counters, read from the
  router during each device poll; the session name becomes the peer description (you can edit it; the plugin keeps
  your edit);
- prefix counts and graphs under *IPv6 unicast*, and the per-peer *updates* graph;
- *BGP Session Up* / *BGP Session Down* event-log entries, written exactly like LibreNMS writes them for IPv4 peers,
  so LibreNMS's BGP alert rules work for IPv6 peers too.

How it stays out of LibreNMS's way:

- The plugin records which peers it added (in its own settings entry in LibreNMS's plugins table, so it adds no
  database tables and LibreNMS's *Validate* page stays clean) and only ever changes or removes those. A peer LibreNMS discovered itself is never touched; if LibreNMS ever starts discovering a peer itself, the
  plugin hands it over.
- LibreNMS's discovery removes peers it did not find itself. Right after each discovery the plugin reads the router
  again and puts its peers back **with the same IDs** and your edited descriptions, so links, graphs and alert history
  are kept.
- A session that disappears from the router is removed from LibreNMS on the next poll. Unticking the option removes
  all the plugin's IPv6 peers on the next poll.
- Routers with only IPv6 sessions are included: the plugin reads every RouterOS device on which LibreNMS has found
  a BGP local AS, even with no IPv4 peers.

**Multiprotocol sessions (IPv4 + IPv6 over one session)?** RouterOS gives one prefix count per session, so IPv6
routes carried over an IPv4 session are included in that session's single count, filed under IPv4.

**VRFs?** Sessions are matched to LibreNMS peers by remote address. Peers in different VRFs with the same
address on one router are not told apart.

**How much load does it put on routers?** One small read per router per device poll (every 5 minutes on a default LibreNMS).

**Does it slow down polling?** It adds the time of one read (typically under a second over the API or SSH) to each RouterOS device's poll, inside the poller worker that is already polling that device. An unreachable router costs at most the *Timeout* you set (default 10 s), so keep it low or switch collection off for routers that cannot be reached.

**How many routers?** Routers are read one after another; with the default 10-second timeout a run with many
unreachable routers can take a while. Keep unreachable routers switched off in their device settings.

**Does it change LibreNMS core files?** No. It is a standard LibreNMS plugin package. It writes to the same
`bgpPeers_cbgp` table and RRD files the LibreNMS BGP poller uses for other vendors; the core poller does not
collect prefix counts for RouterOS, so the two never write the same data.
