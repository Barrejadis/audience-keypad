document.addEventListener('DOMContentLoaded', function() {
    var body = document.body;
    var device = body.getAttribute('data-device') || '';
    var isLimited = body.getAttribute('data-limited') === '1';
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.kb-cell button'));
    var historyDiv = document.getElementById('history');
    var eraseBtn = document.getElementById('erase');
    var remainingSpan = document.getElementById('remaining-count');
    var statusDiv = document.getElementById('kb-status');
    var inFlight = false;

    function setStatus(message) {
        if (statusDiv) {
            statusDiv.textContent = message;
        }
    }

    function remaining() {
        if (!remainingSpan) return null;
        var value = parseInt(remainingSpan.textContent, 10);
        return isNaN(value) ? null : value;
    }

    // Active keys are disabled while a POST is in flight and, in limited
    // mode, when the remaining allowance is 0 (erase stays usable then).
    function refreshKeys() {
        var left = remaining();
        buttons.forEach(function(btn) {
            btn.disabled = inFlight || (isLimited && left !== null && left <= 0);
        });
    }

    // The erase control is disabled while a POST is in flight and whenever
    // the displayed history is empty (nothing to erase).
    function refreshErase() {
        if (!eraseBtn) return;
        var empty = !historyDiv || historyDiv.children.length === 0;
        eraseBtn.disabled = inFlight || empty;
    }

    function refresh() {
        refreshKeys();
        refreshErase();
    }

    function addToHistory(label) {
        if (!historyDiv || !isLimited) return;
        var span = document.createElement('span');
        span.className = 'history-item';
        span.textContent = label;
        historyDiv.appendChild(span);
    }

    function removeFromHistory() {
        if (historyDiv && historyDiv.lastChild) {
            historyDiv.removeChild(historyDiv.lastChild);
        }
    }

    // The display changes only on a confirmed 200: no optimistic rendering,
    // and a failed press or erase leaves the history untouched.
    function handleResult(status, data, label, isErase) {
        inFlight = false;
        if (status === 200 && data && data.ok) {
            if (isErase) {
                removeFromHistory();
            } else {
                addToHistory(label);
            }
            if (remainingSpan && data.remaining !== undefined) {
                remainingSpan.textContent = data.remaining;
            }
            setStatus('');
        } else {
            if (data && data.reroute) {
                window.location.reload();
                return;
            }
            setStatus('Failed: ' + label + (data && data.error ? ' (' + data.error + ')' : ''));
        }
        refresh();
    }

    function post(form, label, isErase) {
        inFlight = true;
        refresh();
        setStatus('');
        fetch('keystroke.php', { method: 'POST', body: form })
            .then(function(resp) {
                return resp.json().then(function(data) {
                    return { status: resp.status, data: data };
                });
            })
            .then(function(result) { handleResult(result.status, result.data, label, isErase); })
            .catch(function() {
                inFlight = false;
                setStatus('Failed: ' + label + ' (network error)');
                refresh();
            });
    }

    buttons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (inFlight || btn.disabled) return;
            var form = new FormData();
            form.append('kb', device);
            form.append('key', btn.getAttribute('data-key-id'));
            post(form, btn.getAttribute('data-label'), false);
        });
    });

    if (eraseBtn) {
        eraseBtn.addEventListener('click', function() {
            if (inFlight || eraseBtn.disabled) return;
            var form = new FormData();
            form.append('kb', device);
            form.append('undo', '1');
            post(form, 'erase', true);
        });
    }

    refresh();
});
