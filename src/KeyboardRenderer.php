<?php
declare(strict_types=1);

require_once __DIR__ . '/KeyboardDefinition.php';

/**
 * Renders the keyboard page HTML.
 * This is a domain service - it takes data and produces HTML, no HTTP context.
 *
 * Static styling lives in keyboard.css and static client-side behaviour in
 * keyboard.js. Only the per-request values are generated here: the grid
 * geometry (column count), the device name, and the mode flag, passed as
 * data attributes that keyboard.js reads.
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

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Keyboard</title>
<link rel="stylesheet" href="keyboard.css">
</head>
<body data-device="' . htmlspecialchars($deviceName, ENT_QUOTES) . '" data-limited="' . ($isLimited ? '1' : '0') . '">
' . $this->renderGrid() . '
<div class="kb-status" id="kb-status"></div>
' . $this->renderLimitedExtras($isLimited) . '
<script src="keyboard.js"></script>
</body>
</html>';

        return $html;
    }

    private function renderGrid(): string
    {
        $cols = $this->definition->cols;
        $rows = $this->definition->rows;

        // The column count depends on the keyboard, so this one rule is the
        // only per-request CSS; everything else comes from keyboard.css.
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
}
