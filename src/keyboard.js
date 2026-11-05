document.addEventListener('DOMContentLoaded', function() {
    var body = document.body;
    var device = body.getAttribute('data-device') || '';
    var isLimited = body.getAttribute('data-limited') === '1';
    var inFlight = false;
    var buttons = document.querySelectorAll('.kb-cell button');
    var historyDiv = document.getElementById('history');
    var eraseBtn = document.getElementById('erase');
    var remainingSpan = document.getElementById('remaining-count');

    function setInFlight(state) {
        inFlight = state;
        var items = document.querySelectorAll('.in-flight');
        items.forEach(function(item) { item.classList.remove('in-flight'); });
    }

    function updateRemaining(data) {
        if (remainingSpan && data && data.remaining !== undefined) {
            remainingSpan.textContent = data.remaining;
            if (isLimited && data.remaining <= 0) {
                buttons.forEach(function(btn) { btn.disabled = true; });
            }
        }
    }

    function addToHistory(label) {
        if (historyDiv && isLimited) {
            var span = document.createElement('span');
            span.className = 'history-item';
            span.textContent = label;
            historyDiv.appendChild(span);
            if (eraseBtn) eraseBtn.disabled = false;
        }
    }

    function removeFromHistory() {
        if (historyDiv && historyDiv.lastChild) {
            historyDiv.removeChild(historyDiv.lastChild);
            if (historyDiv.children.length === 0 && eraseBtn) {
                eraseBtn.disabled = true;
            }
        }
    }

    function handleResponse(response, label, isErase) {
        setInFlight(false);
        if (!response.ok) {
            if (response.reroute) {
                window.location.reload();
                return;
            }
            if (historyDiv && isLimited) {
                var fail = document.createElement('span');
                fail.className = 'failure';
                fail.textContent = ' [FAIL: ' + label + ']';
                historyDiv.appendChild(fail);
            }
            return;
        }
        if (isErase) {
            removeFromHistory();
        } else {
            addToHistory(label);
        }
        updateRemaining(response);
    }

    function sendKeystroke(keyId, label) {
        setInFlight(true);
        var form = new FormData();
        form.append('kb', device);
        form.append('key', keyId);
        fetch('keystroke.php', {
            method: 'POST',
            body: form
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) { handleResponse(data, label, false); })
        .catch(function(err) {
            setInFlight(false);
            console.error('Error:', err);
        });
    }

    function sendErase() {
        if (!eraseBtn || eraseBtn.disabled) return;
        setInFlight(true);
        eraseBtn.disabled = true;
        var form = new FormData();
        form.append('kb', device);
        form.append('undo', '1');
        fetch('keystroke.php', {
            method: 'POST',
            body: form
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) { handleResponse(data, 'erase', true); })
        .catch(function(err) {
            setInFlight(false);
            eraseBtn.disabled = false;
            console.error('Error:', err);
        });
    }

    buttons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var keyId = parseInt(this.getAttribute('data-key-id'), 10);
            var label = this.getAttribute('data-label');
            sendKeystroke(keyId, label);
        });
    });

    if (eraseBtn) {
        eraseBtn.addEventListener('click', sendErase);
    }
});
