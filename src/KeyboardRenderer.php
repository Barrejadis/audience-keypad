<?php
declare(strict_types=1);

require_once __DIR__ . '/KeyboardDefinition.php';

/**
 * Renders the keyboard page HTML.
 * This is a domain service - it takes data and produces HTML, no HTTP context.
 *
 * Static styling lives in keyboard.css and static client-side behaviour in
 * keyboard.js. Only the per-request values are generated here: the device
 * width (column count), the device name, the mode flag, and the remaining
 * allowance, passed as data attributes that keyboard.js reads.
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
        $deviceName = $this->definition->deviceName;
        $cols = $this->definition->cols;

        // The remaining allowance is not displayed (it is implicit from the
        // stroke history); it is passed as a data attribute so keyboard.js
        // can disable the keys when the limit is reached.
        $remainingAttr = $isLimited ? ' data-remaining="' . $this->remaining . '"' : '';

        // The device width is capped so the square cells never exceed
        // --kb-key-max: the component reads as a keyboard, not big buttons,
        // and the whole device fits on a phone screen. The column count
        // depends on the keyboard, so this is the only per-request CSS.
        $widthStyle = 'width: min(92vw, calc(' . $cols . ' * var(--kb-key-max) + ' . ($cols - 1) . ' * var(--kb-gap)))';

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Keyboard</title>
<link rel="stylesheet" href="keyboard.css">
</head>
<body data-device="' . htmlspecialchars($deviceName, ENT_QUOTES) . '" data-limited="' . ($isLimited ? '1' : '0') . '"' . $remainingAttr . '>
<div class="kb-device" style="' . $widthStyle . '">
' . $this->renderHistory($isLimited) . $this->renderGrid() . '
</div>
<div class="kb-status" id="kb-status"></div>
<script src="keyboard.js"></script>
</body>
</html>';

        return $html;
    }

    private function renderGrid(): string
    {
        $cols = $this->definition->cols;
        $rows = $this->definition->rows;

        // The column count depends on the keyboard, so this rule is emitted
        // per request; everything else comes from keyboard.css.
        $html = '<div class="kb-grid" style="grid-template-columns: repeat(' . $cols . ', 1fr)">';

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

    /**
     * Render the limited-mode stroke history row, displayed above the grid.
     * Each recorded keystroke is a cell in the row; the erase control sits
     * in the same row, immediately after the last displayed keystroke.
     */
    private function renderHistory(bool $isLimited): string
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
        // U+232B ERASE TO THE LEFT. Not a keyboard cell: no registry entry.
        $html .= '<button type="button" class="erase-btn" id="erase"' .
                 (empty($this->history) ? ' disabled' : '') .
                 '>⌫</button>';
        $html .= '</div>';

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
}
