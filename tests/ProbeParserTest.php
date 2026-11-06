<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KvStore.php';
require_once __DIR__ . '/../src/LabelRegistry.php';
require_once __DIR__ . '/../src/ProbeException.php';
require_once __DIR__ . '/../src/ProbeParser.php';
require_once __DIR__ . '/InMemoryKvStore.php';

use PHPUnit\Framework\TestCase;

class ProbeParserTest extends TestCase
{
    private InMemoryKvStore $store;
    private LabelRegistry $registry;

    protected function setUp(): void
    {
        $this->store = new InMemoryKvStore();
        // Demo keyboard of spec 01 §6: ←=0, →=1, ↑=2, ↓=3
        $this->registry = new LabelRegistry('demo', $this->store);
        foreach (['←', '→', '↑', '↓'] as $label) {
            $this->registry->getOrCreateId($label);
        }
    }

    private function parse(string $source): array
    {
        return ProbeParser::parse($source, $this->registry);
    }

    private function assertIntNode(array $node, string $decimal): void
    {
        $this->assertSame('int', $node['op']);
        $this->assertSame($decimal, gmp_strval($node['value'], 10));
    }

    public function testParsesStateAndKeyVariables(): void
    {
        $this->assertSame(['op' => 'v'], $this->parse('v'));
        $this->assertSame(['op' => 'k'], $this->parse('k'));
    }

    public function testParsesIntegerLiteralArbitraryPrecision(): void
    {
        $node = $this->parse('123456789012345678901234567890');
        $this->assertIntNode($node, '123456789012345678901234567890');
    }

    public function testParsesBinaryOperator(): void
    {
        $node = $this->parse('v + 1');
        $this->assertSame('bin', $node['op']);
        $this->assertSame('+', $node['kind']);
        $this->assertSame(['op' => 'v'], $node['left']);
        $this->assertIntNode($node['right'], '1');
    }

    public function testAllBinaryOperatorsTokenize(): void
    {
        // || and && get their own node kinds; every other operator is a bin node
        foreach (['|', '^', '&', '==', '!=', '<', '<=', '>', '>=', '<<', '>>', '+', '-', '*', '/', '%'] as $op) {
            $node = $this->parse("v $op k");
            $this->assertSame('bin', $node['op'], $op);
            $this->assertSame($op, $node['kind'], $op);
        }
        $this->assertSame('or', $this->parse('v || k')['op']);
        $this->assertSame('and', $this->parse('v && k')['op']);
    }

    public function testPrecedenceShiftBindsLooserThanAdd(): void
    {
        // Spec precedence (lowest→highest): ... << >> · + - · * / % ...
        // so v + 1 << 2  ==  (v + 1) << 2
        $node = $this->parse('v + 1 << 2');
        $this->assertSame('<<', $node['kind']);
        $this->assertSame('+', $node['left']['kind']);

        // 1 << 2 + 3  ==  1 << (2 + 3)
        $node = $this->parse('1 << 2 + 3');
        $this->assertSame('<<', $node['kind']);
        $this->assertSame('+', $node['right']['kind']);
    }

    public function testPrecedenceBitwiseChain(): void
    {
        // | lowest of the three, then ^, then &: v | k ^ 1 & 2 == v | (k ^ (1 & 2))
        $node = $this->parse('v | k ^ 1 & 2');
        $this->assertSame('|', $node['kind']);
        $this->assertSame('^', $node['right']['kind']);
        $this->assertSame('&', $node['right']['right']['kind']);
    }

    public function testPrecedenceEqualityBindsLooserThanRelational(): void
    {
        // v == k < 1  ==  v == (k < 1)
        $node = $this->parse('v == k < 1');
        $this->assertSame('==', $node['kind']);
        $this->assertSame('<', $node['right']['kind']);
    }

    public function testPrecedenceOrAndBitwise(): void
    {
        // v || k && 1  ==  v || (k && 1)
        $node = $this->parse('v || k && 1');
        $this->assertSame('or', $node['op']);
        $this->assertSame('and', $node['right']['op']);
    }

    public function testBinaryOperatorsAreLeftAssociative(): void
    {
        // v - 1 - 2  ==  (v - 1) - 2
        $node = $this->parse('v - 1 - 2');
        $node = $this->parse('v - 1 - 2');
        $this->assertSame('bin', $node['op']);
        $this->assertSame('-', $node['kind']);
        $this->assertSame('-', $node['left']['kind']);
        $this->assertSame(['op' => 'v'], $node['left']['left']);
        $this->assertIntNode($node['left']['right'], '1');
        $this->assertIntNode($node['right'], '2');
    }

    public function testUnaryOperators(): void
    {
        $node = $this->parse('-v');
        $this->assertSame('neg', $node['op']);
        $this->assertSame(['op' => 'v'], $node['operand']);

        $node = $this->parse('!k');
        $this->assertSame('not', $node['op']);
        $this->assertSame(['op' => 'k'], $node['operand']);

        // - - v nests
        $node = $this->parse('- -v');
        $this->assertSame('neg', $node['op']);
        $this->assertSame('neg', $node['operand']['op']);

        // unary binds tighter than *: -v * 2 == (-v) * 2
        $node = $this->parse('-v * 2');
        $this->assertSame('*', $node['kind']);
        $this->assertSame('neg', $node['left']['op']);
    }

    public function testParenthesesOverridePrecedence(): void
    {
        $node = $this->parse('(v + 1) * 2');
        $this->assertSame('*', $node['kind']);
        $this->assertSame('+', $node['left']['kind']);
    }

    public function testIfExpression(): void
    {
        $node = $this->parse('if(k, v, 0)');
        $this->assertSame('if', $node['op']);
        $this->assertSame(['op' => 'k'], $node['cond']);
        $this->assertSame(['op' => 'v'], $node['then']);
        $this->assertIntNode($node['else'], '0');
    }

    public function testKeyRefResolvesToLabelIdAtParseTime(): void
    {
        // Demo registry: ←=0, →=1, ↑=2, ↓=3
        $this->assertIntNode($this->parse('key "←"'), '0');
        $this->assertIntNode($this->parse('key "→"'), '1');
        $this->assertIntNode($this->parse('key "↑"'), '2');
        $this->assertIntNode($this->parse('key "↓"'), '3');

        $node = $this->parse('k == key "↑"');
        $this->assertSame('==', $node['kind']);
        $this->assertIntNode($node['right'], '2');
    }

    public function testKeyRefUsesRequestDeviceRegistry(): void
    {
        // Another device has its own id space
        $other = new LabelRegistry('other', $this->store);
        $other->getOrCreateId('↑'); // ↑ = 0 on "other"
        $node = ProbeParser::parse('key "↑"', $other);
        $this->assertIntNode($node, '0');
    }

    public function testKeyRefUnknownLabelIs422(): void
    {
        try {
            $this->parse('key "nope"');
            $this->fail('Expected ProbeException');
        } catch (ProbeException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('Unknown label', $e->getMessage());
        }
    }

    public function testStringEscapes(): void
    {
        $this->registry->getOrCreateId('a"b');
        $id = $this->registry->getId('a"b');
        $node = $this->parse('key "a\"b"');
        $this->assertIntNode($node, (string)$id);
    }

    public function testWhitespaceIsIgnored(): void
    {
        $node = $this->parse("  v\n+\t1\r ");
        $this->assertSame('+', $node['kind']);
    }

    public function testProgramSizeCap(): void
    {
        // Exactly at the cap: not 413 (it fails as a parse error instead)
        try {
            $this->parse(str_repeat(' ', MAX_PROGRAM_SIZE));
            $this->fail('Expected ProbeException');
        } catch (ProbeException $e) {
            $this->assertSame(422, $e->status);
        }

        // One char over the cap: 413 before any parsing
        try {
            $this->parse(str_repeat('v', MAX_PROGRAM_SIZE + 1));
            $this->fail('Expected ProbeException');
        } catch (ProbeException $e) {
            $this->assertSame(413, $e->status);
        }
    }

    public function testParseErrorsAre422(): void
    {
        foreach ([
            '',            // empty program
            'v +',         // dangling operator
            '(v',          // unclosed paren
            'v)',          // unopened paren
            'v v',         // two expressions
            '2 3',
            '=',           // single = is not a token
            '@',           // illegal character
            'if(k, v)',    // missing branch
            'if k, v, 0)', // missing (
            'key 5',       // key without string
            'key "unterminated',
            'foo',         // unknown identifier
            'v & & k',
        ] as $bad) {
            try {
                $this->parse($bad);
                $this->fail('Expected ProbeException for: ' . json_encode($bad));
            } catch (ProbeException $e) {
                $this->assertSame(422, $e->status, json_encode($bad));
            }
        }
    }
}
