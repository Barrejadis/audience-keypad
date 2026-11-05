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

    public function testRenderInactiveCell(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A, ,B', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0, 'B' => 1], [0, 1], [], 0);
        
        $html = $renderer->render();
        
        // Should have a placeholder
        $this->assertStringContainsString('kb-placeholder', $html);
        // Should have 3 cells
        $this->assertStringContainsString('grid-template-columns: repeat(3, 1fr)', $html);
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
        $this->assertStringContainsString('history', $html);
        // Should have erase button
        $this->assertStringContainsString('erase', $html);
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

    public function testRenderDeviceNameInScript(): void
    {
        $def = KeyboardDefinition::fromParameters('my-device', 'A', null);
        $renderer = new KeyboardRenderer($def, ['A' => 0], [0], [], 0);
        
        $html = $renderer->render();
        
        $this->assertStringContainsString('my-device', $html);
        // Should be JSON-escaped
        $this->assertStringContainsString('"my-device"', $html);
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
}
