<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KeyboardDefinition.php';
require_once __DIR__ . '/../src/KeyboardRenderer.php';

use PHPUnit\Framework\TestCase;

class KeyboardRendererTest extends TestCase
{
    public function testRenderBasicKeyboard(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [], 0);

        $html = $renderer->render();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('button', $html);
        $this->assertStringContainsString('A', $html);
        $this->assertStringContainsString('B', $html);
    }

    public function testRenderEscapesLabels(): void
    {
        $def = KeyboardDefinition::fromParameters('test', '<script>,&"', null);
        $renderer = new KeyboardRenderer($def, ['<script>' => 0, '&"' => 1], [0, 1], [], 0);

        $html = $renderer->render();

        // Labels should be HTML-escaped
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&amp;&quot;', $html);
    }

    public function testRenderLinksStylesheetAndScript(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [], 0);

        $html = $renderer->render();

        // Static assets are referenced as dedicated files
        $this->assertStringContainsString('<link rel="stylesheet" href="keyboard.css">', $html);
        $this->assertStringContainsString('<script src="keyboard.js"></script>', $html);
        // The referenced asset files exist next to the endpoint
        $this->assertFileExists(__DIR__ . '/../src/keyboard.css');
        $this->assertFileExists(__DIR__ . '/../src/keyboard.js');
    }

    public function testRenderHasNoInlineStyleOrScriptContent(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [], 0);

        $html = $renderer->render();

        // No inline CSS or JS blocks: styling and behaviour live in
        // keyboard.css / keyboard.js
        $this->assertStringNotContainsString('<style>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderInactiveCell(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A, ,B,C', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1, 'C' => 2], [0, 1, 2], [], 0);

        $html = $renderer->render();

        // Should have a placeholder
        $this->assertStringContainsString('kb-placeholder', $html);
        // Should have 4 cells in a 2x2 grid
        $this->assertStringContainsString('grid-template-columns: repeat(2, 1fr)', $html);
    }

    public function testRender2x2Grid(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B,C,D', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3], [0, 1, 2, 3], [], 0);

        $html = $renderer->render();

        // 2x2 grid
        $this->assertStringContainsString('grid-template-columns: repeat(2, 1fr)', $html);
        $this->assertStringContainsString('A', $html);
        $this->assertStringContainsString('B', $html);
        $this->assertStringContainsString('C', $html);
        $this->assertStringContainsString('D', $html);
    }

    public function testRenderLimitedModeWithHistory(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [0, 1, 0], 2);

        $html = $renderer->render();

        // Should have history display
        $this->assertStringContainsString('class="history"', $html);
        // Should have erase button
        $this->assertStringContainsString('id="erase"', $html);
        // Should show history items
        $this->assertStringContainsString('A', $html);
        $this->assertStringContainsString('B', $html);
    }

    public function testRenderLimitedModeEmptyHistory(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [], 5);

        $html = $renderer->render();

        // Erase button should be disabled
        $this->assertStringContainsString('erase', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function testRenderLimitedModeAtLimit(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '2');
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [0, 1], 0);

        $html = $renderer->render();

        // Keys should be disabled when remaining is 0
        $this->assertStringContainsString('disabled', $html);
    }

    public function testRenderMainModeNoHistory(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [], 0);

        $html = $renderer->render();

        // Main mode should NOT have history
        $this->assertStringNotContainsString('class="history"', $html);
        // Main mode should NOT have erase button
        $this->assertStringNotContainsString('erase', $html);
    }

    public function testRenderKeyDataAttributes(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [], 0);

        $html = $renderer->render();

        $this->assertStringContainsString('data-key-id="0"', $html);
        $this->assertStringContainsString('data-key-id="1"', $html);
        $this->assertStringContainsString('data-label="A"', $html);
        $this->assertStringContainsString('data-label="B"', $html);
    }

    public function testRenderDeviceNameInBodyAttribute(): void
    {
        $def = KeyboardDefinition::fromParameters('my-device', 'A', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0], [0], [], 0);

        $html = $renderer->render();

        // The device name is passed to keyboard.js via a body data attribute
        $this->assertStringContainsString('data-device="my-device"', $html);
    }

    public function testRenderLimitedFlagInBodyAttribute(): void
    {
        $mainDef = KeyboardDefinition::fromParameters('test', 'A', null);
        $mainRenderer = new KeyboardRenderer($mainDef, ['A' => 0], [0], [], 0);

        $limitedDef = KeyboardDefinition::fromParameters('test2', 'A', '3');
        $limitedRenderer = new KeyboardRenderer($limitedDef, ['A' => 0], [0], [], 3);

        $this->assertStringContainsString('data-limited="0"', $mainRenderer->render());
        $this->assertStringContainsString('data-limited="1"', $limitedRenderer->render());
    }

    public function testRenderDemoKeyboardFromSpec(): void
    {
        // From spec: GET index.php?kb=demo&keys=←,→,↑,↓ (4 cells → 2×2)
        $def = KeyboardDefinition::fromParameters('demo', '←,→,↑,↓', null);
        $renderer = new KeyboardRenderer($def, ['←' => 0, '→' => 1, '↑' => 2, '↓' => 3], [0, 1, 2, 3], [], 0);

        $html = $renderer->render();

        $this->assertStringContainsString('grid-template-columns: repeat(2, 1fr)', $html);
        $this->assertStringContainsString('←', $html);
        $this->assertStringContainsString('→', $html);
        $this->assertStringContainsString('↑', $html);
        $this->assertStringContainsString('↓', $html);
    }

    public function testRender5x5Grid(): void
    {
        $labels = [];
        for ($i = 0; $i < 25; $i++) {
            $labels[] = 'K' . $i;
        }
        $def = KeyboardDefinition::fromParameters('test', implode(',', $labels), null);

        $labelToId = [];
        $userKeyIds = [];
        for ($i = 0; $i < 25; $i++) {
            $labelToId['K' . $i] = $i;
            $userKeyIds[] = $i;
        }

        $renderer = new KeyboardRenderer($def, $labelToId, $userKeyIds, [], 0);
        $html = $renderer->render();

        // 5x5 grid
        $this->assertStringContainsString('grid-template-columns: repeat(5, 1fr)', $html);
        // Should have all 25 keys
        $this->assertStringContainsString('K0', $html);
        $this->assertStringContainsString('K24', $html);
    }

    public function testLabelsAreHtmlEscaped(): void
    {
        $dangerous = '<script>alert("XSS")</script>';
        $def = KeyboardDefinition::fromParameters('test', $dangerous . ',A', null);
        $renderer = new KeyboardRenderer($def, [$dangerous => 0, 'A' => 1], [0, 1], [], 0);

        $html = $renderer->render();

        // Should be escaped
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testLimitedModeDoesNotDisplayRemainingCounter(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [0], 4);

        $html = $renderer->render();

        // The remaining allowance is implicit from the stroke history:
        // no counter is displayed.
        $this->assertStringNotContainsString('id="remaining-count"', $html);
        $this->assertStringNotContainsString('class="remaining"', $html);
        $this->assertStringNotContainsString('Remaining:', $html);
    }

    public function testLimitedModeExposesRemainingAsDataAttribute(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [0], 4);

        $html = $renderer->render();

        $this->assertStringContainsString('data-remaining="4"', $html);
    }

    public function testMainModeHasNoRemainingAttribute(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [], 0);

        $this->assertStringNotContainsString('data-remaining', $renderer->render());
    }

    public function testEraseButtonUsesEraseToTheLeftSymbol(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [0], 4);

        $html = $renderer->render();

        // U+232B ERASE TO THE LEFT as the button label
        $this->assertStringContainsString('>⌫</button>', $html);
    }

    public function testHistoryRowIsAboveGridAndEraseSitsInsideIt(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [0, 1], 3);

        $html = $renderer->render();

        $historyPos = strpos($html, 'id="history"');
        $erasePos = strpos($html, 'id="erase"');
        $gridPos = strpos($html, 'kb-grid');

        $this->assertNotFalse($historyPos);
        $this->assertNotFalse($erasePos);
        $this->assertNotFalse($gridPos);
        // History on top of the keyboard...
        $this->assertLessThan($gridPos, $historyPos);
        // ...with the erase control in the history row, after the keystrokes
        $this->assertGreaterThan($historyPos, $erasePos);
        $this->assertLessThan($gridPos, $erasePos);
    }

    public function testHistoryItemsComeBeforeEraseButton(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [0, 1], 3);

        $html = $renderer->render();

        $this->assertLessThan(
            strpos($html, 'id="erase"'),
            strpos($html, 'history-item')
        );
    }

    public function testDeviceWrapperCapsWidthToKeySize(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [], 0);

        $html = $renderer->render();

        // 2 columns: the device is at most 2 key widths plus one gap wide
        $this->assertStringContainsString('class="kb-device"', $html);
        $this->assertStringContainsString(
            'width: min(92vw, calc(2 * var(--kb-key-max) + 1 * var(--kb-gap)))',
            $html
        );
    }

    public function testDeviceWrapperWidthFor5x5Grid(): void
    {
        $labels = [];
        for ($i = 0; $i < 25; $i++) {
            $labels[] = 'K' . $i;
        }
        $def = KeyboardDefinition::fromParameters('test', implode(',', $labels), null);
        $renderer = new KeyboardRenderer($def, array_combine($labels, range(0, 24)), range(0, 24), [], 0);

        $html = $renderer->render();

        $this->assertStringContainsString(
            'width: min(92vw, calc(5 * var(--kb-key-max) + 4 * var(--kb-gap)))',
            $html
        );
    }

    public function testRenderLimitedModeWithNumericLabelsAndHistory(): void
    {
        // Regression test: numeric labels ("1".."9") are coerced to int
        // array keys when the label->id map is built, exactly as index.php
        // does. Rendering the history row (second visit, non-empty
        // history) must not crash and must show the digit labels.
        $def = KeyboardDefinition::fromParameters('demo', '1,2,3,4,5,6,7,8,9', '5');

        $labelToId = [];
        $userKeyIds = [];
        foreach ($def->getActiveLabels() as $i => $label) {
            $labelToId[$label] = $i;
            $userKeyIds[] = $i;
        }

        // History references ids 4, 0, 8 -> labels "5", "1", "9"
        $renderer = new KeyboardRenderer($def, $labelToId, $userKeyIds, [4, 0, 8], 2);

        $html = $renderer->render();

        $this->assertStringContainsString('class="history"', $html);
        $this->assertStringContainsString('<span class="history-item">5</span>', $html);
        $this->assertStringContainsString('<span class="history-item">1</span>', $html);
        $this->assertStringContainsString('<span class="history-item">9</span>', $html);
        // The grid still renders the digit keys
        $this->assertStringContainsString('data-label="1"', $html);
        $this->assertStringContainsString('grid-template-columns: repeat(3, 1fr)', $html);
    }
}
