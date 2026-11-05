<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KeyboardDefinition.php';

use PHPUnit\Framework\TestCase;

class KeyboardDefinitionTest extends TestCase
{
    public function testValidDeviceName(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $this->assertEquals('test', $def->deviceName);
    }

    public function testInvalidDeviceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyboardDefinition::fromParameters('invalid name!', 'A,B', null);
    }

    public function testDeviceNameMaxLength(): void
    {
        $longName = str_repeat('a', 32);
        $def = KeyboardDefinition::fromParameters($longName, 'A', null);
        $this->assertEquals($longName, $def->deviceName);
    }

    public function testDeviceNameTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $tooLong = str_repeat('a', 33);
        KeyboardDefinition::fromParameters($tooLong, 'A', null);
    }

    public function testValidKeyboardSizes(): void
    {
        $validSizes = [1, 2, 4, 6, 9, 12, 16, 20, 25];
        
        foreach ($validSizes as $size) {
            $labels = array_fill(0, $size, '');
            foreach ($labels as &$label) {
                $label = 'K' . uniqid();
            }
            $def = KeyboardDefinition::fromParameters('test', implode(',', $labels), null);
            $this->assertEquals($size, count($def->labels));
        }
    }

    public function testInvalidKeyboardSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyboardDefinition::fromParameters('test', 'A,B,C', null); // 3 cells - invalid
    }

    public function testDuplicateActiveLabels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyboardDefinition::fromParameters('test', 'A,A,B,C', null);
    }

    public function testInactiveLabelsAreAllowedDuplicates(): void
    {
        // Space and empty are inactive, so they can appear multiple times
        $def = KeyboardDefinition::fromParameters('test', 'A, ,B, ', null);
        $this->assertEquals(['A', ' ', 'B', ' '], $def->labels);
    }

    public function testUniqueActiveLabels(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B,C,D', null);
        $active = $def->getActiveLabels();
        $this->assertEquals(['A', 'B', 'C', 'D'], $active);
        $this->assertEquals(4, count(array_unique($active)));
    }

    public function testMainMode(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $this->assertEquals('main', $def->mode);
        $this->assertNull($def->limit);
    }

    public function testLimitedMode(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        $this->assertEquals('limited', $def->mode);
        $this->assertEquals(5, $def->limit);
    }

    public function testInvalidLimitZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyboardDefinition::fromParameters('test', 'A,B', '0');
    }

    public function testInvalidLimitNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyboardDefinition::fromParameters('test', 'A,B', '-1');
    }

    public function testInvalidLimitTooHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyboardDefinition::fromParameters('test', 'A,B', (string)(MAX_STROKES + 1));
    }

    public function testInvalidLimitNonInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyboardDefinition::fromParameters('test', 'A,B', 'abc');
    }

    public function testGeometry(): void
    {
        // Test all valid sizes
        $expected = [
            1 => [1, 1],
            2 => [2, 1],
            4 => [2, 2],
            6 => [3, 2],
            9 => [3, 3],
            12 => [4, 3],
            16 => [4, 4],
            20 => [5, 4],
            25 => [5, 5],
        ];

        foreach ($expected as $size => [$expectedCols, $expectedRows]) {
            $labels = array_map(fn($i) => 'K' . $i, range(0, $size - 1));
            $def = KeyboardDefinition::fromParameters('test', implode(',', $labels), null);
            $this->assertEquals($expectedCols, $def->cols, "Failed for size $size cols");
            $this->assertEquals($expectedRows, $def->rows, "Failed for size $size rows");
        }
    }

    public function testGetLabelAt(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B,C,D', null);
        
        $this->assertEquals('A', $def->getLabelAt(0));
        $this->assertEquals('B', $def->getLabelAt(1));
        $this->assertEquals('C', $def->getLabelAt(2));
        $this->assertEquals('D', $def->getLabelAt(3));
        $this->assertNull($def->getLabelAt(4)); // Out of bounds
        $this->assertNull($def->getLabelAt(-1));
    }

    public function testGetLabelAtInactive(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A, ,C,D', null);
        
        $this->assertEquals('A', $def->getLabelAt(0));
        $this->assertNull($def->getLabelAt(1)); // Space is inactive
        $this->assertEquals('C', $def->getLabelAt(2));
        $this->assertEquals('D', $def->getLabelAt(3));
    }

    public function testConflictsWithDifferentMode(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5'); // limited
        
        $this->assertTrue($def->conflictsWith('main', null));
        $this->assertFalse($def->conflictsWith('limited', 5));
    }

    public function testConflictsWithDifferentLimit(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        
        $this->assertTrue($def->conflictsWith('limited', 10));
        $this->assertFalse($def->conflictsWith('limited', 5));
    }

    public function testNoConflictSameDefinition(): void
    {
        $def = KeyboardDefinition::fromParameters('test', 'A,B', '5');
        
        $this->assertFalse($def->conflictsWith('limited', 5));
    }

    public function testLabelWithCommaNotAllowed(): void
    {
        // The spec says comma MUST NOT appear inside a label
        // Our implementation splits on comma, so "A,B" is parsed as two labels: "A" and "B"
        // This is correct behavior - the validation happens at parse time
        $def = KeyboardDefinition::fromParameters('test', 'A,B', null);
        $this->assertEquals(['A', 'B'], $def->labels);
    }

    public function testEmptyLabels(): void
    {
        // Empty string and space are both inactive
        $def = KeyboardDefinition::fromParameters('test', 'A,,B, ', null);
        $active = $def->getActiveLabels();
        $this->assertEquals(['A', 'B'], $active);
    }
}
