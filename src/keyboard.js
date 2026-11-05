document.addEventListener('DOMContentLoaded', function() {
    var body = document.body;
    var device = body.getAttribute('data-device') || '';
    var isLimited = body.getAttribute('data-limited') === '1';
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.kb-cell button'));
    var historyDiv = document.getElementById('history');
    var eraseBtn = document.getElementById('erase');
    var statusDiv = document.getElementById('kb-status');
    var inFlight = false;

    // The remaining allowance is not displayed; it arrives as a data
    // attribute and is updated from (b) responses to disable the keys
    // when the limit is reached.
    var remaining = null;
    var remainingAttr = body.getAttribute('data-remaining');
    if (remainingAttr !== null) {
        var parsed = parseInt(remainingAttr, 10);
        remaining = isNaN(parsed) ? null : parsed;
    }

    function setStatus(message) {
        if (statusDiv) {
            statusDiv.textContent = message;
        }
    }

    // Active keys are disabled while a POST is in flight and, in limited
    // mode, when the remaining allowance is 0 (erase stays usable then).
    function refreshKeys() {
        buttons.forEach(function(btn) {
            btn.disabled = inFlight || (isLimited && remaining !== null && remaining <= 0);
        });
    }

    // The erase control is disabled while a POST is in flight and whenever
    // the displayed history is empty (nothing to erase).
    function refreshErase() {
        if (!eraseBtn) return;
        var empty = !historyDiv || historyDiv.querySelectorAll('.history-item').length === 0;
        eraseBtn.disabled = inFlight || empty;
    }

    function refresh() {
        refreshKeys();
        refreshErase();
    }

    // A new keystroke cell is inserted in the history row, immediately
    // before the erase control.
    function addToHistory(label) {
        if (!historyDiv || !isLimited) return;
        var span = document.createElement('span');
        span.className = 'history-item';
        span.textContent = label;
        if (eraseBtn && eraseBtn.parentNode === historyDiv) {
            historyDiv.insertBefore(span, eraseBtn);
        } else {
            historyDiv.appendChild(span);
        }
    }

    function removeFromHistory() {
        if (!historyDiv) return;
        var items = historyDiv.querySelectorAll('.history-item');
        if (items.length > 0) {
            historyDiv.removeChild(items[items.length - 1]);
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
            if (isLimited && data.remaining !== undefined) {
                remaining = data.remaining;
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
