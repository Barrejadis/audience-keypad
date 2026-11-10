document.addEventListener('DOMContentLoaded', function() {
    // Predefined probes: only the description is visible in the listbox; the
    // full definition is hidden until a click populates the init/program
    // fields, where it stays editable.
    var PROBES = [
        { description: 'count users', init: '1', program: 'v' },
        { description: 'pressed keys', init: '0', program: 'if(k >= 0, v | (1 << k), v)' },
        { description: 'resulting integer', init: '0', program: 'if(k == key "1", v * 10 + 1,\nif(k == key "4", v * 10 + 4,\nif(k == key "7", v * 10 + 7,\nif(k == key "0", v * 10 + 0,\nif(k == key "2", v * 10 + 2,\nif(k == key "5", v * 10 + 5,\nif(k == key "8", v * 10 + 8,\nif(k == key "3", v * 10 + 3,\nif(k == key "6", v * 10 + 6,\nif(k == key "9", v * 10 + 9,\nv))))))))))' },
        { description: 'cursor movement', init: '500500', program: 'if(k == key "←", if(v / 1000 > 0, v - 1000, v),\nif(k == key "→", if(v / 1000 < 999, v + 1000, v),\nif(k == key "↑", if(v % 1000 > 0, v - 1, v),\n                 if(v % 1000 < 999, v + 1, v))))' }
    ];


    var kbName = document.getElementById('kb-name');
    var kbKeys = document.getElementById('kb-keys');
    var kbLimit = document.getElementById('kb-limit');
    var openKeyboardBtn = document.getElementById('open-keyboard');
    var aliasPassword = document.getElementById('alias-password');
    var aliasName = document.getElementById('alias-name');
    var defineAliasBtn = document.getElementById('define-alias');
    var openAliasBtn = document.getElementById('open-alias');
    var aliasStatus = document.getElementById('alias-status');
    var probeList = document.getElementById('probe-list');
    var probeInit = document.getElementById('probe-init');
    var probeProgram = document.getElementById('probe-program');
    var runProbeBtn = document.getElementById('run-probe');
    var probeResult = document.getElementById('probe-result');
    var cleanStartBtn = document.getElementById('clean-start');
    var cleanStatus = document.getElementById('clean-status');

    var probeInFlight = false;

    PROBES.forEach(function(probe, i) {
        var option = document.createElement('option');
        option.value = String(i);
        option.textContent = probe.description;
        probeList.appendChild(option);
    });

    probeList.addEventListener('change', function() {
        var probe = PROBES[Number(probeList.value)];
        if (probe) {
            probeInit.value = probe.init;
            probeProgram.value = probe.program;
        }
        refresh();
    });

    function val(el) {
        return el.value.trim();
    }

    // Each button is enabled as soon as all mandatory fields for its action
    // have a value; limit is optional everywhere it appears.
    function refresh() {
        var name = val(kbName);
        var keys = val(kbKeys);
        openKeyboardBtn.disabled = !(name && keys);
        defineAliasBtn.disabled = !(val(aliasPassword) && val(aliasName) && name && keys);
        openAliasBtn.disabled = !val(aliasName);
        runProbeBtn.disabled = probeInFlight || !(name && val(aliasPassword) && val(probeInit) && val(probeProgram));
        cleanStartBtn.disabled = !val(aliasPassword);
    }

    [kbName, kbKeys, kbLimit, aliasPassword, aliasName, probeInit, probeProgram].forEach(function(el) {
        el.addEventListener('input', refresh);
    });

    function openUrl(url) {
        window.open(url, '_blank');
    }

    openKeyboardBtn.addEventListener('click', function() {
        var url = 'index.php?kb=' + encodeURIComponent(val(kbName))
            + '&keys=' + encodeURIComponent(val(kbKeys));
        var limit = val(kbLimit);
        if (limit) {
            url += '&limit=' + encodeURIComponent(limit);
        }
        openUrl(url);
    });

    openAliasBtn.addEventListener('click', function() {
        openUrl('index.php?alias=' + encodeURIComponent(val(aliasName)));
    });

    // Post a JSON body and hand the raw response (status + parsed body) to
    // the callback; the endpoints answer JSON even on error statuses.
    function postJson(url, body, onDone) {
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function(resp) {
            return resp.text().then(function(text) {
                var data = text;
                try {
                    data = JSON.parse(text);
                } catch (e) { /* non-JSON body: shown raw */ }
                onDone(resp.status, data);
            });
        }).catch(function(err) {
            onDone(0, String(err));
        });
    }

    function showJson(status, data) {
        var body = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
        return (status ? 'HTTP ' + status + '\n' : 'Network error\n') + body;
    }

    defineAliasBtn.addEventListener('click', function() {
        var body = {
            password: val(aliasPassword),
            action: 'alias',
            alias: val(aliasName),
            kb: val(kbName),
            keys: val(kbKeys)
        };
        var limit = val(kbLimit);
        if (limit) {
            body.limit = limit;
        }
        aliasStatus.textContent = '…';
        postJson('alias.php', body, function(status, data) {
            aliasStatus.textContent = showJson(status, data);
        });
    });

    // Clean start wipes every keypad key in APCu (all devices, sessions and
    // aliases) — destructive, so it asks for confirmation first and the
    // endpoint requires an explicit confirm flag on top of the password.
    cleanStartBtn.addEventListener('click', function() {
        if (!window.confirm('Wipe ALL keypad data (every device, session, and alias)? This cannot be undone.')) {
            return;
        }
        cleanStatus.textContent = '…';
        postJson('admin.php', {
            password: val(aliasPassword),
            action: 'clean-start',
            confirm: true
        }, function(status, data) {
            cleanStatus.textContent = showJson(status, data);
        });
    });

    runProbeBtn.addEventListener('click', function() {
        probeInFlight = true;
        refresh();
        probeResult.value = '…';
        postJson('probe.php', {
            kb: val(kbName),
            password: val(aliasPassword),
            init: val(probeInit),
            program: probeProgram.value.trim()
        }, function(status, data) {
            probeInFlight = false;
            probeResult.value = showJson(status, data);
            refresh();
        });
    });

    refresh();
});
