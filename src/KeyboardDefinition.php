<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';

/**
 * Represents a parsed and validated keyboard definition.
 * Immutable once constructed.
 */
class KeyboardDefinition
{
    public readonly string $deviceName;
    /** @var list<string> */
    public readonly array $labels;
    public readonly ?int $limit;
    public readonly string $mode;
    public readonly int $cols;
    public readonly int $rows;

    /**
     * @param list<string> $rawLabels
     */
    private function __construct(
        string $deviceName,
        array $rawLabels,
        ?int $limit
    ) {
        $this->deviceName = $deviceName;
        $this->labels = $rawLabels;
        $this->limit = $limit;
        $this->mode = $limit !== null ? 'limited' : 'main';
        
        $count = count($rawLabels);
        if (!isset(KEYBOARD_SIZES[$count])) {
            throw new InvalidArgumentException("Invalid keyboard size: $count cells");
        }
        [$this->cols, $this->rows] = KEYBOARD_SIZES[$count];
    }

    /**
     * Parse and validate keyboard parameters.
     * 
     * @param string $kb Device name
     * @param string $keys Comma-separated labels
     * @param string|null $limit Optional limit parameter
     * @return self
     * @throws InvalidArgumentException on validation failure
     */
    public static function fromParameters(string $kb, string $keys, ?string $limit): self
    {
        // Validate device name
        if (!preg_match(KB_NAME_RE, $kb)) {
            throw new InvalidArgumentException("Invalid device name: $kb");
        }

        // Parse labels
        $rawLabels = explode(',', $keys);
        
        // Validate segment count
        $count = count($rawLabels);
        if (!isset(KEYBOARD_SIZES[$count])) {
            throw new InvalidArgumentException("Invalid keyboard size: $count cells. Must be one of: " . implode(', ', array_keys(KEYBOARD_SIZES)));
        }

        // Validate active labels are unique
        $activeLabels = array_filter($rawLabels, fn($l) => $l !== '' && $l !== ' ');
        if (count($activeLabels) !== count(array_unique($activeLabels))) {
            throw new InvalidArgumentException("Duplicate active labels in keyboard definition");
        }

        // Parse and validate limit
        $limitValue = null;
        if ($limit !== null) {
            if (!ctype_digit($limit)) {
                throw new InvalidArgumentException("Limit must be an integer");
            }
            $limitValue = (int)$limit;
            if ($limitValue <= 0 || $limitValue > MAX_STROKES) {
                throw new InvalidArgumentException("Limit must be between 1 and " . MAX_STROKES);
            }
        }

        return new self($kb, $rawLabels, $limitValue);
    }

    /**
     * Check if this definition conflicts with a recorded device mode.
     * 
     * @param string $recordedMode 'main' or 'limited'
     * @param int|null $recordedLimit
     * @return bool True if there's a conflict
     */
    public function conflictsWith(string $recordedMode, ?int $recordedLimit): bool
    {
        if ($this->mode !== $recordedMode) {
            return true;
        }
        if ($this->mode === 'limited' && $this->limit !== $recordedLimit) {
            return true;
        }
        return false;
    }

    /**
     * Get active labels (non-empty, non-space).
     * 
     * @return list<string>
     */
    public function getActiveLabels(): array
    {
        return array_filter($this->labels, fn($l) => $l !== '' && $l !== ' ');
    }

    /**
     * Get the label at a specific index.
     * 
     * @param int $index 0-based index
     * @return string|null The label or null if inactive
     */
    public function getLabelAt(int $index): ?string
    {
        if ($index < 0 || $index >= count($this->labels)) {
            return null;
        }
        $label = $this->labels[$index];
        return ($label === '' || $label === ' ') ? null : $label;
    }
}
