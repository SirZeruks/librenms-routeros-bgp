<div class="container-fluid" id="routeros-bgp">
    <div class="row">
        <div class="col-md-12">
            <h3 style="margin-top: 0">
                RouterOS BGP prefixes <small>v{{ $version }}</small>
                @if($enabled)
                    <span class="label label-success">Collecting</span>
                @else
                    <span class="label label-default">Off</span>
                @endif
            </h3>
            <p class="text-muted">
                RouterOS v7 does not publish BGP prefix counts over SNMP. This plugin reads them from each router
                (REST, API, API-SSL or SSH, read-only) as part of each device's normal LibreNMS poll and stores them where LibreNMS keeps prefix
                counts for other vendors, so they appear under <strong>Routing &rarr; BGP</strong> (Prefixes graphs) and in the
                <code>list_cbgp</code> API.
            </p>
            <p class="small" id="rbgp-version" data-check-url="{{ route('routeros-bgp.update.check') }}"
               data-start-url="{{ route('routeros-bgp.update.start') }}" data-status-url="{{ route('routeros-bgp.update.status') }}">
                <span class="text-muted">Checking for updates&hellip;</span>
            </p>
            <p class="small text-warning">
                <i class="fa fa-exclamation-triangle"></i> Use at your own risk: provided as is, without warranty.
                Use a <strong>read-only</strong> router user. See <em>Security and your responsibility</em> in the manual.
            </p>
        </div>
    </div>

    {{-- Quick start --}}
    <div class="panel panel-info">
        <div class="panel-heading">
            <a data-toggle="collapse" href="#rbgp-quickstart"><strong>Quick start</strong> (click to {{ $enabled ? 'show' : 'hide' }})</a>
        </div>
        <div id="rbgp-quickstart" class="panel-collapse collapse {{ $enabled ? '' : 'in' }}">
            <div class="panel-body">
                <ol>
                    <li>On each MikroTik, create a <strong>read-only</strong> user for LibreNMS (paste in the RouterOS terminal, change the password and the LibreNMS IP):
<pre>/user group add name=librenms-read policy=read,api,rest-api,ssh
/user add name=librenms group=librenms-read password="CHANGE-ME" address=LIBRENMS-IP/32</pre>
                        and make sure the service you pick below is enabled and reachable from LibreNMS
                        (<code>/ip service print</code> &mdash; <code>www-ssl</code> for REST over HTTPS, <code>api-ssl</code>, <code>api</code> or <code>ssh</code>).
                    </li>
                    <li>Fill in <strong>Default connection</strong> below with that username and password. Use <em>Device settings</em> only for routers that differ (other port, other IP, other user).</li>
                    <li>Press <strong>Test</strong> on a device. You should see its BGP sessions and prefix counts.</li>
                    <li>Tick <strong>Collect prefix counts</strong> and save. The counts are then read during every device poll (press <strong>Poll all now</strong> for an immediate read); graphs fill in after a few polls.</li>
                </ol>
                The full manual is in the plugin's README on GitHub.
            </div>
        </div>
    </div>

    {{-- Defaults --}}
    <div class="panel panel-default">
        <div class="panel-heading"><strong>Default connection</strong> (used by every RouterOS device unless it has its own settings)</div>
        <div class="panel-body">
            <form class="form-horizontal" method="post" action="{{ route('routeros-bgp.defaults') }}" autocomplete="off">
                @csrf
                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-9">
                        <div class="checkbox">
                            <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($enabled)> <strong>Collect prefix counts</strong> (during each device poll)</label>
                        </div>
                        <div class="checkbox">
                            <label><input type="hidden" name="manage_ipv6" value="0"><input type="checkbox" name="manage_ipv6" value="1" @checked($manage_ipv6)>
                                Add <strong>IPv6</strong> BGP sessions to LibreNMS</label>
                            <p class="help-block">LibreNMS cannot see MikroTik IPv6 BGP peers over SNMP. With this on, the plugin adds them as BGP
                                peers itself (state, AS, uptime, prefix graphs and Session Up/Down events), and removes them when they
                                disappear from the router. Peers LibreNMS discovers itself are never touched.</p>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label" for="rbgp-transport">Connect with</label>
                    <div class="col-sm-5">
                        <select class="form-control" id="rbgp-transport" name="transport" data-rbgp-port-target="rbgp-port">
                            @foreach($transports as $key => [$label, $port])
                                <option value="{{ $key }}" data-port="{{ $port }}" @selected($defaults['transport'] === $key)>{{ $label }} &mdash; port {{ $port }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label" for="rbgp-port">Port</label>
                    <div class="col-sm-2">
                        <input class="form-control" type="number" min="1" max="65535" id="rbgp-port" name="port" value="{{ $defaults['port'] ?: '' }}" placeholder="{{ $transports[$defaults['transport']][1] ?? 443 }}">
                    </div>
                    <div class="col-sm-7"><p class="form-control-static text-muted">Leave empty for the standard port. Set it if your routers run the service on a custom port.</p></div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label" for="rbgp-username">Username</label>
                    <div class="col-sm-4"><input class="form-control" id="rbgp-username" name="username" value="{{ $defaults['username'] }}" autocomplete="off"></div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label" for="rbgp-password">Password</label>
                    <div class="col-sm-4">
                        <input class="form-control" type="password" id="rbgp-password" name="password" autocomplete="new-password"
                               placeholder="{{ $defaults['password_set'] ? 'stored - leave empty to keep' : 'not set' }}">
                    </div>
                    @if($defaults['password_set'])
                        <div class="col-sm-5"><div class="checkbox"><label><input type="checkbox" name="clear_password" value="1"> remove stored password</label></div></div>
                    @endif
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label" for="rbgp-sshkey">SSH private key <small class="text-muted">(SSH only, optional)</small></label>
                    <div class="col-sm-6">
                        <textarea class="form-control" rows="3" id="rbgp-sshkey" name="ssh_key" style="font-family: monospace"
                                  placeholder="{{ $defaults['ssh_key_set'] ? 'stored - leave empty to keep' : '-----BEGIN OPENSSH PRIVATE KEY----- ... (the password above is then used as the key passphrase)' }}"></textarea>
                    </div>
                    @if($defaults['ssh_key_set'])
                        <div class="col-sm-3"><div class="checkbox"><label><input type="checkbox" name="clear_ssh_key" value="1"> remove stored key</label></div></div>
                    @endif
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-9">
                        <div class="checkbox">
                            <label><input type="hidden" name="verify_tls" value="0"><input type="checkbox" name="verify_tls" value="1" @checked($defaults['verify_tls'])>
                                Verify the router's TLS certificate</label>
                            <p class="help-block">Leave off for routers with self-signed certificates (the usual case). The connection is still encrypted.</p>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label" for="rbgp-timeout">Timeout (seconds)</label>
                    <div class="col-sm-2"><input class="form-control" type="number" min="2" max="60" id="rbgp-timeout" name="timeout" value="{{ $defaults['timeout'] }}"></div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-9"><button type="submit" class="btn btn-primary">Save</button></div>
                </div>
            </form>
            <p class="text-muted small">Passwords and keys are stored encrypted with your LibreNMS APP_KEY and are never shown again.</p>
        </div>
    </div>

    {{-- Devices --}}
    <div class="panel panel-default">
        <div class="panel-heading clearfix">
            <strong>Devices</strong> &mdash; RouterOS devices on which LibreNMS has found BGP peers ({{ count($rows) }})
            @if($enabled && count($rows))
                <button type="button" class="btn btn-xs btn-success pull-right" id="rbgp-poll-all" data-url="{{ route('routeros-bgp.poll-all') }}"
                        data-status-url="{{ route('routeros-bgp.run-status') }}" title="Read every router now and store the counts (runs in the background)">Poll all now</button>
            @endif
            <div id="rbgp-run" class="small" style="margin-top: 4px">
                @if($run)
                    @if($run['running'])
                        <i class="fa fa-spinner fa-spin"></i> Polling: {{ $run['done'] }} of {{ $run['total'] }} done
                    @else
                        <span class="text-muted">Last full poll ({{ $run['by'] }}): {{ $run['done'] }} of {{ $run['total'] }} devices,
                            {{ $run['failed'] }} failed, finished {{ \Carbon\Carbon::createFromTimestamp($run['finished'] ?? $run['started'])->diffForHumans() }}</span>
                        @if(! empty($run['error']))<span class="text-danger">{{ $run['error'] }}</span>@endif
                    @endif
                @endif
            </div>
        </div>
        @if(count($rows) === 0)
            <div class="panel-body">
                No RouterOS device with BGP peers yet. LibreNMS finds the BGP sessions itself during discovery
                (the <em>bgp-peers</em> module must be enabled for the device); they will then appear here.
            </div>
        @else
            <table class="table table-condensed table-hover" style="margin-bottom: 0">
                <thead>
                <tr>
                    <th>Device</th>
                    <th>Peers</th>
                    <th>Connects with</th>
                    <th>Last result</th>
                    <th class="text-right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($rows as $row)
                    @php($d = $row['device'])
                    @php($o = $row['override'] ?? \SirZeruks\LibrenmsRouterosBgp\SettingsStore::blankOverride())
                    <tr>
                        <td>
                            <a href="{{ url('device/' . $d->device_id) }}">{{ $d->displayName() }}</a>
                            @if(! $row['enabled'])<span class="label label-default">skipped</span>@endif
                            @if($row['override'])<span class="label label-info" title="Has its own settings">custom</span>@endif
                        </td>
                        <td>{{ $row['peers'] }}</td>
                        <td><code>{{ $row['connection']->transport }}</code> {{ $row['connection']->host }}:{{ $row['connection']->port }}
                            <span class="text-muted">{{ $row['connection']->username !== '' ? 'as ' . $row['connection']->username : '(no username)' }}</span></td>
                        <td>
                            @if($row['status'])
                                <span class="label label-{{ $row['status']['ok'] ? 'success' : 'danger' }}">{{ $row['status']['ok'] ? 'OK' : 'Failed' }}</span>
                                <small title="{{ date('Y-m-d H:i:s', $row['status']['time']) }}">{{ \Carbon\Carbon::createFromTimestamp($row['status']['time'])->diffForHumans() }}</small>
                                <br><small class="text-muted">{{ $row['status']['message'] }}</small>
                            @else
                                <span class="text-muted">not read yet</span>
                            @endif
                        </td>
                        <td class="text-right" style="white-space: nowrap">
                            <button type="button" class="btn btn-xs btn-default rbgp-run" data-url="{{ route('routeros-bgp.run', ['device' => $d->device_id]) }}" data-mode="test" title="Read the router now without storing anything">Test</button>
                            <button type="button" class="btn btn-xs btn-default rbgp-run" data-url="{{ route('routeros-bgp.run', ['device' => $d->device_id]) }}" data-mode="poll" title="Read the router and store the counts now">Poll now</button>
                            <button type="button" class="btn btn-xs btn-primary" data-toggle="collapse" data-target="#rbgp-edit-{{ $d->device_id }}">Device settings</button>
                        </td>
                    </tr>
                    <tr class="rbgp-result-row" id="rbgp-result-{{ $d->device_id }}" style="display: none">
                        <td colspan="5"><div class="rbgp-result"></div></td>
                    </tr>
                    <tr id="rbgp-edit-{{ $d->device_id }}" class="collapse">
                        <td colspan="5" style="background: rgba(0,0,0,.03)">
                            <form class="form-horizontal" method="post" action="{{ route('routeros-bgp.device.save') }}" autocomplete="off" style="padding: 10px 0">
                                @csrf
                                <input type="hidden" name="device_id" value="{{ $d->device_id }}">
                                <p class="text-muted col-sm-offset-3">Empty fields use the default connection.</p>
                                <div class="form-group">
                                    <div class="col-sm-offset-3 col-sm-9"><div class="checkbox"><label>
                                        <input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($o['enabled'])> Collect from this device</label></div></div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">Address</label>
                                    <div class="col-sm-4"><input class="form-control" name="host" value="{{ $o['host'] }}" placeholder="{{ $d->overwrite_ip ?: $d->hostname }}"></div>
                                    <div class="col-sm-5"><p class="form-control-static text-muted small">If the router's API/SSH is reachable on another IP or name.</p></div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">Connect with</label>
                                    <div class="col-sm-5">
                                        <select class="form-control" name="transport" data-rbgp-port-target="rbgp-port-{{ $d->device_id }}">
                                            <option value="" data-port="">Default</option>
                                            @foreach($transports as $key => [$label, $port])
                                                <option value="{{ $key }}" data-port="{{ $port }}" @selected($o['transport'] === $key)>{{ $label }} &mdash; port {{ $port }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">Port</label>
                                    <div class="col-sm-2"><input class="form-control" type="number" min="1" max="65535" id="rbgp-port-{{ $d->device_id }}" name="port" value="{{ $o['port'] ?: '' }}" placeholder="default"></div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">Username</label>
                                    <div class="col-sm-4"><input class="form-control" name="username" value="{{ $o['username'] }}" placeholder="default" autocomplete="off"></div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">Password</label>
                                    <div class="col-sm-4"><input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="{{ ! empty($o['password_set']) ? 'stored - leave empty to keep' : 'default' }}"></div>
                                    @if(! empty($o['password_set']))
                                        <div class="col-sm-5"><div class="checkbox"><label><input type="checkbox" name="clear_password" value="1"> use the default password again</label></div></div>
                                    @endif
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">SSH private key</label>
                                    <div class="col-sm-6"><textarea class="form-control" rows="2" name="ssh_key" style="font-family: monospace" placeholder="{{ ! empty($o['ssh_key_set']) ? 'stored - leave empty to keep' : 'default' }}"></textarea></div>
                                    @if(! empty($o['ssh_key_set']))
                                        <div class="col-sm-3"><div class="checkbox"><label><input type="checkbox" name="clear_ssh_key" value="1"> use the default key again</label></div></div>
                                    @endif
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">Verify TLS certificate</label>
                                    <div class="col-sm-3">
                                        <select class="form-control" name="verify_tls">
                                            <option value="" @selected($o['verify_tls'] === '')>Default</option>
                                            <option value="1" @selected($o['verify_tls'] === '1')>Yes</option>
                                            <option value="0" @selected($o['verify_tls'] === '0')>No</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="col-sm-offset-3 col-sm-9">
                                        <button type="submit" class="btn btn-primary btn-sm">Save device settings</button>
                                        @if($row['override'])
                                            <button type="submit" class="btn btn-default btn-sm" formaction="{{ route('routeros-bgp.device.delete', ['deviceId' => $d->device_id]) }}"
                                                    onclick="return confirm('Remove the custom settings for this device and use the defaults?')">Use defaults</button>
                                        @endif
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

<script>
(function () {
    var root = document.getElementById('routeros-bgp');
    if (!root) return;
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // Show the standard port of the chosen transport as the port placeholder.
    root.querySelectorAll('select[data-rbgp-port-target]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var input = document.getElementById(sel.getAttribute('data-rbgp-port-target'));
            var port = sel.options[sel.selectedIndex].getAttribute('data-port');
            if (input) input.placeholder = port || 'default';
        });
    });

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    // Plugin version + "Update now" (installs the newest release from Packagist in the background).
    var ver = document.getElementById('rbgp-version');
    function verLine(d) {
        var html = 'Installed <strong>' + esc(d.installed) + '</strong>';
        if (d.dev_install) {
            html += ' <span class="text-muted">(development install: update it from its source)</span>';
        } else if (d.latest === null) {
            html += ' <span class="text-muted">(could not reach Packagist to check for updates)</span>';
        } else if (d.available) {
            html += ' &middot; <strong class="text-success">' + esc(d.latest) + ' available</strong> ' +
                '<button type="button" class="btn btn-xs btn-primary" id="rbgp-update">Update now</button> ' +
                '<a href="https://github.com/SirZeruks/librenms-routeros-bgp/blob/main/CHANGELOG.md" target="_blank" rel="noopener">what changed</a>';
        } else {
            html += ' <span class="text-muted">&middot; up to date</span>';
        }
        ver.innerHTML = html;
        var b = document.getElementById('rbgp-update');
        if (b) b.addEventListener('click', startUpdate);
    }
    function showUpdate(r) {
        if (!r) return;
        if (r.running) {
            ver.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Updating to ' + esc(r.version) + '&hellip; (this can take a minute)';
            setTimeout(followUpdate, 3000);
        } else if (r.ok) {
            ver.innerHTML = '<span class="text-success">Updated to ' + esc(r.version) + '. Reloading&hellip;</span>';
            setTimeout(function () { window.location.reload(); }, 1500);
        } else {
            ver.innerHTML = '<span class="text-danger">The update failed. Nothing was removed; run the installer on the server ' +
                '(<code>sudo bash install.sh</code>) to finish or retry.</span><pre style="max-height:200px;overflow:auto">' + esc(r.log) + '</pre>';
        }
    }
    function followUpdate() {
        fetch(ver.getAttribute('data-status-url'), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (res) { return res.json(); }).then(function (d) { showUpdate(d.run); })
            .catch(function () { setTimeout(followUpdate, 5000); });
    }
    function startUpdate() {
        if (!confirm('Update the RouterOS BGP plugin to the newest release now? LibreNMS keeps running; the update takes about a minute.')) return;
        ver.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Starting the update&hellip;';
        fetch(ver.getAttribute('data-start-url'), {method: 'POST', headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (res) { return res.json(); })
            .then(function (d) { if (d.started) showUpdate(d.run); else ver.innerHTML = '<span class="text-warning">' + esc(d.message) + '</span>'; })
            .catch(function (e) { ver.innerHTML = '<span class="text-danger">Request failed: ' + esc(e.message) + '</span>'; });
    }
    if (ver) {
        fetch(ver.getAttribute('data-check-url'), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (res) { return res.json(); })
            .then(function (d) { if (d.run && d.run.running) showUpdate(d.run); else verLine(d); })
            .catch(function () { ver.innerHTML = '<span class="text-muted">Could not check for updates.</span>'; });
    }

    // "Poll all now": start a background run, then follow its progress and reload the page when it finishes.
    var pollAll = document.getElementById('rbgp-poll-all');
    var runBox = document.getElementById('rbgp-run');
    function showRun(r) {
        if (!r) return;
        if (r.running) {
            runBox.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Polling: ' + esc(r.done) + ' of ' + esc(r.total) + ' done' + (r.failed ? ', ' + esc(r.failed) + ' failed' : '');
        } else if (r.error) {
            runBox.innerHTML = '<span class="text-danger">' + esc(r.error) + '</span>';
        }
    }
    function follow(statusUrl) {
        fetch(statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (res) { return res.json(); })
            .then(function (d) {
                showRun(d.run);
                if (d.run && d.run.running) { setTimeout(function () { follow(statusUrl); }, 3000); }
                else if (d.run && !d.run.error) { window.location.reload(); }
                else if (pollAll) { pollAll.disabled = false; }
            })
            .catch(function () { setTimeout(function () { follow(statusUrl); }, 5000); });
    }
    if (pollAll) {
        pollAll.addEventListener('click', function () {
            pollAll.disabled = true;
            fetch(pollAll.getAttribute('data-url'), {
                method: 'POST', headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}, credentials: 'same-origin'
            }).then(function (res) { return res.json(); }).then(function (d) {
                if (d.run) showRun(d.run); else runBox.innerHTML = '<span class="text-danger">' + esc(d.message) + '</span>';
                if (d.run && d.run.running) follow(pollAll.getAttribute('data-status-url'));
                else pollAll.disabled = false;
            }).catch(function (e) {
                runBox.innerHTML = '<span class="text-danger">Request failed: ' + esc(e.message) + '</span>';
                pollAll.disabled = false;
            });
        });
        @if($run && $run['running'])
            pollAll.disabled = true;
            follow(pollAll.getAttribute('data-status-url'));
        @endif
    }

    root.querySelectorAll('.rbgp-run').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('tr').nextElementSibling;
            var box = row.querySelector('.rbgp-result');
            row.style.display = '';
            box.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Reading the router&hellip;';
            btn.disabled = true;

            fetch(btn.getAttribute('data-url'), {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'mode=' + encodeURIComponent(btn.getAttribute('data-mode')),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (r) {
                var html = '<div class="alert alert-' + (r.ok ? 'success' : 'danger') + '" style="margin: 0 0 6px">' +
                    '<strong>' + (r.ok ? 'OK' : 'Failed') + '</strong> via <code>' + esc(r.via) + '</code>: ' + esc(r.message) +
                    (btn.getAttribute('data-mode') === 'test' ? ' <em>(test only, nothing stored)</em>' : '') + '</div>';
                if (r.sessions && r.sessions.length) {
                    html += '<table class="table table-condensed" style="margin: 0"><tr><th>Session</th><th>Remote</th><th>State</th><th>Prefixes</th><th>In LibreNMS</th></tr>';
                    r.sessions.forEach(function (s) {
                        html += '<tr><td>' + esc(s.name) + '</td><td>' + esc(s.remote) + '</td><td>' + (s.established ? 'established' : 'down') +
                            '</td><td>' + esc(s.prefixes === null ? '-' : s.prefixes) + '</td><td>' +
                            (s.added ? 'added by the plugin (IPv6)' : s.matched ? 'yes' : '<span class="text-warning">no &mdash; not discovered as a BGP peer</span>') + '</td></tr>';
                    });
                    html += '</table>';
                }
                box.innerHTML = html;
            }).catch(function (e) {
                box.innerHTML = '<div class="alert alert-danger" style="margin: 0">Request failed: ' + esc(e.message) + '</div>';
            }).finally(function () { btn.disabled = false; });
        });
    });
})();
</script>
