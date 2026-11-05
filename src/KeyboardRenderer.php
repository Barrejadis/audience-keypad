<?php
declare(strict_types=1);

require_once __DIR__ . '/KeyboardDefinition.php';

/**
 * Renders the keyboard page HTML.
 * This is a domain service - it takes data and produces HTML, no HTTP context.
 */
class KeyboardRenderer
{
    private KeyboardDefinition $definition;
    /** @var array<string, int> Label to key id mapping */
    private array $labelToId;
    /** @var list<int> User's active key ids */
    private array $userKeyIds;
    /** @var list<int> User's keystroke history (key ids) */
    private array $history;
    private int $remaining;

    /**
     * @param KeyboardDefinition $definition
     * @param array<string, int> $labelToId
     * @param list<int> $userKeyIds
     * @param list<int> $history User's keystroke history
     * @param int $remaining Remaining strokes
     */
    public function __construct(
        KeyboardDefinition $definition,
        array $labelToId,
        array $userKeyIds,
        array $history = [],
        int $remaining = 0
    ) {
        $this->definition = $definition;
        $this->labelToId = $labelToId;
        $this->userKeyIds = $userKeyIds;
        $this->history = $history;
        $this->remaining = $remaining;
    }

    /**
     * Render the complete keyboard page.
     */
    public function render(): string
    {
        $isLimited = $this->definition->mode === 'limited';
        $cols = $this->definition->cols;
        $rows = $this->definition->rows;
        $deviceName = $this->definition->deviceName;

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Keyboard</title>
<style>' . $this->renderStyles($cols, $rows) . '</style>
</head>
<body>
' . $this->renderGrid($cols, $rows) . '
' . $this->renderLimitedExtras($isLimited) . '
<script>' . $this->renderScript($deviceName, $isLimited) . '</script>
</body>
</html>';

        return $html;
    }

    private function renderStyles(int $cols, int $rows): string
    {
        return '.kb-grid { display: grid; grid-template-columns: repeat(' . $cols . ', 1fr); gap: 4px; }' .
               '.kb-cell { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; }' .
               '.kb-cell button { width: 100%; height: 100%; font-size: 24px; cursor: pointer; border: 2px solid #333; border-radius: 4px; background: #f0f0f0; }' .
               '.kb-cell button:disabled { background: #ccc; cursor: not-allowed; }' .
               '.kb-cell button:active:not(:disabled) { background: #ddd; }' .
               '.kb-placeholder { background: #e0e0e0; }' .
               '.history { margin-top: 16px; font-size: 20px; min-height: 24px; }' .
               '.history-item { display: inline-block; margin: 0 4px; padding: 2px 8px; background: #e8f4f8; border-radius: 4px; }' .
               '.remaining { margin-top: 8px; font-size: 16px; color: #666; }' .
               '.remaining span { font-weight: bold; color: #333; }' .
               '.erase-btn { margin-top: 8px; padding: 8px 16px; font-size: 16px; cursor: pointer; border: 2px solid #333; border-radius: 4px; background: #f0f0f0; }' .
               '.erase-btn:disabled { background: #ccc; cursor: not-allowed; }' .
               '.failure { color: #d32f2f; font-weight: bold; }' .
               '.in-flight { opacity: 0.6; }';
    }

    private function renderGrid(int $cols, int $rows): string
    {
        $html = '<div class="kb-grid">';
        $index = 0;
        
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $label = $this->definition->getLabelAt($index);
                
                if ($label === null) {
                    $html .= '<div class="kb-cell kb-placeholder"></div>';
                } else {
                    $keyId = $this->labelToId[$label] ?? null;
                    $disabled = $this->shouldDisableKey($keyId);
                    
                    $html .= '<div class="kb-cell">';
                    $html .= '<button type="button" data-key-id="' . htmlspecialchars((string)$keyId, ENT_QUOTES) . '"' . 
                             ' data-label="' . htmlspecialchars($label, ENT_QUOTES) . '"' .
                             ($disabled ? ' disabled' : '') . 
                             '>' . htmlspecialchars($label) . '</button>';
                    $html .= '</div>';
                }
                $index++;
            }
        }
        
        $html .= '</div>';
        return $html;
    }

    private function shouldDisableKey(?int $keyId): bool
    {
        if ($keyId === null) {
            return true;
        }
        if ($this->definition->mode === 'limited' && $this->remaining <= 0) {
            return true;
        }
        return false;
    }

    private function renderLimitedExtras(bool $isLimited): string
    {
        if (!$isLimited) {
            return '';
        }

        $html = '<div class="history" id="history">';
        foreach ($this->history as $keyId) {
            $label = $this->findLabelById($keyId);
            if ($label !== null) {
                $html .= '<span class="history-item">' . htmlspecialchars($label) . '</span>';
            }
        }
        $html .= '</div>';
        
        $html .= '<div class="remaining" id="remaining">Remaining: <span id="remaining-count">' . $this->remaining . '</span></div>';
        $html .= '<button type="button" class="erase-btn" id="erase"' . 
                 (empty($this->history) ? ' disabled' : '') . 
                 '>Erase last</button>';
        
        return $html;
    }

    private function findLabelById(int $keyId): ?string
    {
        foreach ($this->labelToId as $label => $id) {
            if ($id === $keyId) {
                return $label;
            }
        }
        return null;
    }

    private function renderScript(string $deviceName, bool $isLimited): string
    {
        $escapedDevice = json_encode($deviceName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $isLimitedStr = $isLimited ? 'true' : 'false';
        
        $script = "document.addEventListener('DOMContentLoaded', function() {\n    var device = " . $escapedDevice . ";\n";
        $script .= "    var inFlight = false;\n";
        $script .= "    var isLimited = " . $isLimitedStr . ";\n";
        $script .= "    var buttons = document.querySelectorAll('.kb-cell button');\n";
        $script .= "    var historyDiv = document.getElementById('history');\n";
        $script .= "    var eraseBtn = document.getElementById('erase');\n";
        $script .= "    var remainingSpan = document.getElementById('remaining-count');\n\n";
        
        $script .= "    function setInFlight(state) {\n";
        $script .= "        inFlight = state;\n";
        $script .= "        var items = document.querySelectorAll('.in-flight');\n";
        $script .= "        items.forEach(function(item) { item.classList.remove('in-flight'); });\n";
        $script .= "    }\n\n";
        
        $script .= "    function updateRemaining(data) {\n";
        $script .= "        if (remainingSpan && data && data.remaining !== undefined) {\n";
        $script .= "            remainingSpan.textContent = data.remaining;\n";
        $script .= "            if (isLimited && data.remaining <= 0) {\n";
        $script .= "                buttons.forEach(function(btn) { btn.disabled = true; });\n";
        $script .= "            }\n";
        $script .= "        }\n";
        $script .= "    }\n\n";
        
        $script .= "    function addToHistory(label) {\n";
        $script .= "        if (historyDiv && isLimited) {\n";
        $script .= "            var span = document.createElement('span');\n";
        $script .= "            span.className = 'history-item';\n";
        $script .= "            span.textContent = label;\n";
        $script .= "            historyDiv.appendChild(span);\n";
        $script .= "            if (eraseBtn) eraseBtn.disabled = false;\n";
        $script .= "        }\n";
        $script .= "    }\n\n";
        
        $script .= "    function removeFromHistory() {\n";
        $script .= "        if (historyDiv && historyDiv.lastChild) {\n";
        $script .= "            historyDiv.removeChild(historyDiv.lastChild);\n";
        $script .= "            if (historyDiv.children.length === 0 && eraseBtn) {\n";
        $script .= "                eraseBtn.disabled = true;\n";
        $script .= "            }\n";
        $script .= "        }\n";
        $script .= "    }\n\n";
        
        $script .= "    function handleResponse(response, label, isErase) {\n";
        $script .= "        setInFlight(false);\n";
        $script .= "        if (!response.ok) {\n";
        $script .= "            if (response.reroute) {\n";
        $script .= "                window.location.reload();\n";
        $script .= "                return;\n";
        $script .= "            }\n";
        $script .= "            if (historyDiv && isLimited) {\n";
        $script .= "                var fail = document.createElement('span');\n";
        $script .= "                fail.className = 'failure';\n";
        $script .= "                fail.textContent = ' [FAIL: ' + label + ']';\n";
        $script .= "                historyDiv.appendChild(fail);\n";
        $script .= "            }\n";
        $script .= "            return;\n";
        $script .= "        }\n";
        $script .= "        if (isErase) {\n";
        $script .= "            removeFromHistory();\n";
        $script .= "        } else {\n";
        $script .= "            addToHistory(label);\n";
        $script .= "        }\n";
        $script .= "        updateRemaining(response);\n";
        $script .= "    }\n\n";
        
        $script .= "    function sendKeystroke(keyId, label) {\n";
        $script .= "        setInFlight(true);\n";
        $script .= "        var form = new FormData();\n";
        $script .= "        form.append('kb', device);\n";
        $script .= "        form.append('key', keyId);\n";
        $script .= "        fetch('keystroke.php', {\n";
        $script .= "            method: 'POST',\n";
        $script .= "            body: form\n";
        $script .= "        })\n";
        $script .= "        .then(function(resp) { return resp.json(); })\n";
        $script .= "        .then(function(data) { handleResponse(data, label, false); })\n";
        $script .= "        .catch(function(err) {\n";
        $script .= "            setInFlight(false);\n";
        $script .= "            console.error('Error:', err);\n";
        $script .= "        });\n";
        $script .= "    }\n\n";
        
        $script .= "    function sendErase() {\n";
        $script .= "        if (!eraseBtn || eraseBtn.disabled) return;\n";
        $script .= "        setInFlight(true);\n";
        $script .= "        eraseBtn.disabled = true;\n";
        $script .= "        var form = new FormData();\n";
        $script .= "        form.append('kb', device);\n";
        $script .= "        form.append('undo', '1');\n";
        $script .= "        fetch('keystroke.php', {\n";
        $script .= "            method: 'POST',\n";
        $script .= "            body: form\n";
        $script .= "        })\n";
        $script .= "        .then(function(resp) { return resp.json(); })\n";
        $script .= "        .then(function(data) { handleResponse(data, 'erase', true); })\n";
        $script .= "        .catch(function(err) {\n";
        $script .= "            setInFlight(false);\n";
        $script .= "            eraseBtn.disabled = false;\n";
        $script .= "            console.error('Error:', err);\n";
        $script .= "        });\n";
        $script .= "    }\n\n";
        
        $script .= "    buttons.forEach(function(btn) {\n";
        $script .= "        btn.addEventListener('click', function() {\n";
        $script .= "            var keyId = parseInt(this.getAttribute('data-key-id'), 10);\n";
        $script .= "            var label = this.getAttribute('data-label');\n";
        $script .= "            sendKeystroke(keyId, label);\n";
        $script .= "        });\n";
        $script .= "    });\n\n";
        
        $script .= "    if (eraseBtn) {\n";
        $script .= "        eraseBtn.addEventListener('click', sendErase);\n";
        $script .= "    }\n";
        $script .= "});\n";

        return $script;
    }
}
