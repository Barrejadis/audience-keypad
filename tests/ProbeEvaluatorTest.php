<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KvStore.php';
require_once __DIR__ . '/../src/LabelRegistry.php';
require_once __DIR__ . '/../src/ProbeException.php';
require_once __DIR__ . '/../src/ProbeParser.php';
require_once __DIR__ . '/../src/ProbeEvaluator.php';
require_once __DIR__ . '/InMemoryKvStore.php';

use PHPUnit\Framework\TestCase;

class ProbeEvaluatorTest extends TestCase
{
    private LabelRegistry $registry;

    protected function setUp(): void
    {
        // Demo keyboard of spec 01 §6: ←=0, →=1, ↑=2, ↓=3
        $this->registry = new LabelRegistry('demo', new InMemoryKvStore());
        foreach (['←', '→', '↑', '↓'] as $label) {
            $this->registry->getOrCreateId($label);
        }
    }

    /**
     * Parse and fold in one step.
     *
     * @param list<int> $history
     */
    private function fold(string $program, string $init, array $history): string
    {
        return ProbeEvaluator::fold(ProbeParser::parse($program, $this->registry), $init, $history);
    }

    private function assertFold422(string $program, string $init, array $history): void
    {
        try {
            $this->fold($program, $init, $history);
            $this->fail('Expected ProbeException for program: ' . $program);
        } catch (ProbeException $e) {
            $this->assertSame(422, $e->status, $program . ' / ' . $e->getMessage());
        }
    }

    // ---- Spec §9 test vectors, history H1 = [→, ↑, ↑, ←] = [1, 2, 2, 0] ----

    public function testVectorCountH1(): void
    {
        $this->assertSame('4', $this->fold('v + 1', '0', [1, 2, 2, 0]));
    }

    public function testVectorCountUpH1(): void
    {
        $this->assertSame('2', $this->fold('v + (k == key "↑")', '0', [1, 2, 2, 0]));
    }

    public function testVectorChecksumH1(): void
    {
        $this->assertSame('1', $this->fold('v ^ k', '0', [1, 2, 2, 0]));
    }

    public function testVectorLastKeyH1(): void
    {
        $this->assertSame('0', $this->fold('k', '-1', [1, 2, 2, 0]));
    }

    public function testVectorHistogramH1(): void
    {
        $this->assertSame('524801', $this->fold('v + (1 << (k * 9))', '0', [1, 2, 2, 0]));
    }

    // ---- Spec §9 cursor probe ----

    private const CURSOR_PROGRAM =
        'if(k == key "←", if(v >= 100, v - 100, v),' .
        'if(k == key "→", if(v < 9900, v + 100, v),' .
        'if(k == key "↑", if(v % 100 < 99, v + 1, v),' .
        '                 if(v % 100 > 0, v - 1, v))))';

    public function testCursorProbeH1(): void
    {
        // H1 → 5052, decoding (x=50, y=52)
        $this->assertSame('5052', $this->fold(self::CURSOR_PROGRAM, '5050', [1, 2, 2, 0]));
    }

    public function testCursorProbeH2ClampsAt99(): void
    {
        // H2 = 200 × ↑ from (50, 50) → 5099: y clamps at 99 and stays there
        $this->assertSame('5099', $this->fold(self::CURSOR_PROGRAM, '5050', array_fill(0, 200, 2)));
    }

    public function testCursorClampPreventsFieldBleed(): void
    {
        // 60 × ↑ without the clamp would push y past 99 into x's digits;
        // with the clamp the state stays 5099.
        $this->assertSame('5099', $this->fold(self::CURSOR_PROGRAM, '5050', array_fill(0, 60, 2)));
        // 60 × → without the clamp would push x past 99 into y's digits
        $this->assertSame('9950', $this->fold(self::CURSOR_PROGRAM, '5050', array_fill(0, 60, 1)));
    }

    // ---- Spec §9 history H3 (undo scenario) = [→, ↑, ←] = [1, 2, 0] ----

    public function testVectorCountH3(): void
    {
        // An undo leaves no trace in the fold
        $this->assertSame('3', $this->fold('v + 1', '0', [1, 2, 0]));
    }

    public function testVectorChecksumH3(): void
    {
        $this->assertSame('3', $this->fold('v ^ k', '0', [1, 2, 0]));
    }

    // ---- presence probe ----

    public function testPresenceProbe(): void
    {
        // Every session with live data yields 1, zero-keystroke users included
        $this->assertSame('1', $this->fold('v', '1', []));
        $this->assertSame('1', $this->fold('v', '1', [1, 2, 0]));
    }

    // ---- §3 operator semantics ----

    public function testDivisionTruncatesTowardZero(): void
    {
        $this->assertSame('-3', $this->fold('v / k', '-7', [2])); // -7/2 = -3.5 → -3
        $this->assertSame('3', $this->fold('v / k', '7', [2]));
    }

    public function testModuloKeepsSignOfDividend(): void
    {
        $this->assertSame('-1', $this->fold('v % k', '-7', [2])); // C/PHP style
        $this->assertSame('1', $this->fold('v % k', '7', [2]));
    }

    public function testDivisionAndModuloByZeroAre422(): void
    {
        $this->assertFold422('v / k', '1', [0]);
        $this->assertFold422('v % k', '1', [0]);
        $this->assertFold422('v / (k - 1)', '1', [1]);
    }

    public function testAndOrShortCircuitAndYieldOneZero(): void
    {
        // The untaken branch would divide by zero; short-circuit must skip it
        $this->assertSame('0', $this->fold('k != 0 && v / k > 0', '5', [0]));
        $this->assertSame('1', $this->fold('k == 0 || v / k > 0', '5', [0]));
        $this->assertSame('0', $this->fold('0 && v / 0 > 0', '5', [1]));
        $this->assertSame('1', $this->fold('1 || v / 0 > 0', '5', [1]));
        // Truth is non-zero, result is exactly 1/0
        $this->assertSame('1', $this->fold('v && k', '5', [3]));
        $this->assertSame('0', $this->fold('v && k', '0', [3]));
        $this->assertSame('1', $this->fold('v || k', '0', [3]));
    }

    public function testNotOperator(): void
    {
        $this->assertSame('1', $this->fold('!k', '0', [0]));
        $this->assertSame('0', $this->fold('!k', '0', [3]));
        $this->assertSame('1', $this->fold('!!v', '7', [1]));
    }

    public function testComparisonsYieldOneZero(): void
    {
        $this->assertSame('1', $this->fold('v < k', '1', [2]));
        $this->assertSame('0', $this->fold('v < k', '2', [2]));
        $this->assertSame('1', $this->fold('v <= k', '2', [2]));
        $this->assertSame('1', $this->fold('v > k', '3', [2]));
        $this->assertSame('1', $this->fold('v >= k', '2', [2]));
        $this->assertSame('1', $this->fold('v == k', '2', [2]));
        $this->assertSame('1', $this->fold('v != k', '3', [2]));
        $this->assertSame('0', $this->fold('v != k', '2', [2]));
    }

    public function testBitwiseOperators(): void
    {
        $this->assertSame('5', $this->fold('v | k', '4', [1]));
        $this->assertSame('2', $this->fold('v & k', '6', [3]));
        $this->assertSame('5', $this->fold('v ^ k', '6', [3]));
    }

    public function testBitwiseNegativeOperandIs422(): void
    {
        // Negative left operand
        $this->assertFold422('v & k', '-1', [1]);
        $this->assertFold422('v | k', '-2', [1]);
        $this->assertFold422('v ^ k', '-1', [1]);
        // Negative right operand
        $this->assertFold422('k & v', '-5', [1]);
        $this->assertFold422('k | v', '-3', [1]);
    }

    public function testShiftAmountRange(): void
    {
        // Boundary: 1 << 4095 has bit length 4096 → allowed
        $result = $this->fold('1 << 4095', '0', [0]);
        $this->assertSame(MAX_STATE_BITS, strlen(gmp_strval(gmp_init($result), 2)));

        // 1 << 4096 has bit length 4097 → state too large
        $this->assertFold422('1 << 4096', '0', [0]);
        // Shift amount above MAX_STATE_BITS → 422 even for >>
        $this->assertFold422('v >> k', '8', [MAX_STATE_BITS + 1]);
        $this->assertFold422('1 << 4097', '0', [0]);
        // Negative shift amount → 422
        $this->assertFold422('v << (0 - 1)', '1', [0]);
    }

    public function testShiftSemantics(): void
    {
        $this->assertSame('8', $this->fold('v << 1', '4', [0]));
        $this->assertSame('2', $this->fold('v >> 1', '4', [0]));
        // >> on a negative state is an arithmetic shift (floor), PHP-style
        $this->assertSame('-3', $this->fold('v >> 1', '-5', [1]));
    }

    public function testIfEvaluatesOnlyTakenBranch(): void
    {
        // k == 0 → then-branch, no division by zero
        $this->assertSame('1', $this->fold('if(k == 0, 1, v / k)', '5', [0]));
        // k == 2 → else-branch: 7 / 2 = 3
        $this->assertSame('3', $this->fold('if(k == 0, 1, v / k)', '7', [2]));
    }

    public function testStateTooLargeGuard(): void
    {
        // 10^700 has ~2325 bits; v * v squares it to ~4650 bits > 4096 → 422
        $this->assertFold422('v * v', '1' . str_repeat('0', 700), [1]);
        // A few squarings of a small state stay within the cap
        $this->assertSame('256', $this->fold('v * v', '2', [1, 1, 1])); // 2→4→16→256
    }

    public function testInitValidation(): void
    {
        $this->assertFold422('v', 'abc', []);
        $this->assertFold422('v', '', []);
        $this->assertFold422('v', '1.5', []);
        $this->assertSame('-42', $this->fold('v', '-42', []));
        $this->assertFold422('v', '+7', []);
    }

    public function testArbitraryPrecisionNoOverflow(): void
    {
        $big = '123456789012345678901234567890';
        $expected = gmp_strval(gmp_mul(gmp_init($big), gmp_init($big)), 10);
        $this->assertSame($expected, $this->fold('v * v', $big, [0]));
    }

    public function testZeroKeystrokeUserYieldsInit(): void
    {
        $this->assertSame('5050', $this->fold('v + 1', '5050', []));
        $this->assertSame('-7', $this->fold('v * 99', '-7', []));
    }
}
