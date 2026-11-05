<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/LabelRegistry.php';
require_once __DIR__ . '/ProbeException.php';

/**
 * Parser for the probe language (spec 02-probe §2).
 *
 * Parses exactly one expression into an AST made of plain arrays:
 *   ['op' => 'int', 'value' => GMP]        integer literal (key refs are
 *                                          resolved to this at parse time)
 *   ['op' => 'v'] / ['op' => 'k']          current state / current key id
 *   ['op' => 'neg'|'not', 'operand' => node]
 *   ['op' => 'and'|'or', 'left' => node, 'right' => node]   short-circuit
 *   ['op' => 'if', 'cond' => node, 'then' => node, 'else' => node]
 *   ['op' => 'bin', 'kind' => one of + - * / % == != < <= > >= & | ^ << >>,
 *    'left' => node, 'right' => node]
 *
 * key "label" is replaced by the label's integer id at parse time, using the
 * registry of the request device; an unregistered label → 422.
 */
class ProbeParser
{
    /** @var list<array{type: string, value: string}> */
    private array $tokens = [];
    private int $pos = 0;

    private function __construct(
        private readonly string $source,
        private readonly LabelRegistry $registry
    ) {
    }

    /**
     * Parse a program into an AST.
     *
     * @return array The AST root node
     * @throws ProbeException 413 if the program exceeds MAX_PROGRAM_SIZE,
     *                        422 on any parse error or unknown label
     */
    public static function parse(string $source, LabelRegistry $registry): array
    {
        if (strlen($source) > MAX_PROGRAM_SIZE) {
            throw new ProbeException(413, 'Program exceeds MAX_PROGRAM_SIZE (' . MAX_PROGRAM_SIZE . ' chars)');
        }

        $parser = new self($source, $registry);
        $parser->tokens = $parser->tokenize();
        $ast = $parser->parseExpr();
        if ($parser->peek()['type'] !== 'eof') {
            throw new ProbeException(422, 'Unexpected token after expression');
        }
        return $ast;
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function tokenize(): array
    {
        $tokens = [];
        $len = strlen($this->source);
        $i = 0;
        while ($i < $len) {
            $c = $this->source[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;
                continue;
            }
            if (ctype_digit($c)) {
                $j = $i;
                while ($j < $len && ctype_digit($this->source[$j])) {
                    $j++;
                }
                $tokens[] = ['type' => 'int', 'value' => substr($this->source, $i, $j - $i)];
                $i = $j;
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($this->source[$j]) || $this->source[$j] === '_')) {
                    $j++;
                }
                $tokens[] = ['type' => 'id', 'value' => substr($this->source, $i, $j - $i)];
                $i = $j;
                continue;
            }
            if ($c === '"') {
                $j = $i + 1;
                $str = '';
                while ($j < $len && $this->source[$j] !== '"') {
                    if ($this->source[$j] === '\\' && $j + 1 < $len
                        && ($this->source[$j + 1] === '"' || $this->source[$j + 1] === '\\')) {
                        $str .= $this->source[$j + 1];
                        $j += 2;
                    } else {
                        $str .= $this->source[$j];
                        $j++;
                    }
                }
                if ($j >= $len) {
                    throw new ProbeException(422, 'Unterminated string literal');
                }
                $tokens[] = ['type' => 'str', 'value' => $str];
                $i = $j + 1;
                continue;
            }
            $two = substr($this->source, $i, 2);
            if (in_array($two, ['||', '&&', '==', '!=', '<=', '>=', '<<', '>>'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $two];
                $i += 2;
                continue;
            }
            if (strpos('|&^+-*/%<>!(),', $c) !== false) {
                $tokens[] = ['type' => 'op', 'value' => $c];
                $i++;
                continue;
            }
            throw new ProbeException(422, "Unexpected character '$c'");
        }
        $tokens[] = ['type' => 'eof', 'value' => ''];
        return $tokens;
    }

    /** @return array{type: string, value: string} */
    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    /** @return array{type: string, value: string} */
    private function next(): array
    {
        return $this->tokens[$this->pos++];
    }

    private function peekIsOp(string ...$ops): bool
    {
        $t = $this->peek();
        return $t['type'] === 'op' && in_array($t['value'], $ops, true);
    }

    private function expectOp(string $op): void
    {
        if (!$this->peekIsOp($op)) {
            throw new ProbeException(422, "Expected '$op'");
        }
        $this->next();
    }

    private function parseExpr(): array
    {
        return $this->parseOr();
    }

    private function parseOr(): array
    {
        $left = $this->parseAnd();
        while ($this->peekIsOp('||')) {
            $this->next();
            $left = ['op' => 'or', 'left' => $left, 'right' => $this->parseAnd()];
        }
        return $left;
    }

    private function parseAnd(): array
    {
        $left = $this->parseBitOr();
        while ($this->peekIsOp('&&')) {
            $this->next();
            $left = ['op' => 'and', 'left' => $left, 'right' => $this->parseBitOr()];
        }
        return $left;
    }

    private function parseBitOr(): array
    {
        return $this->parseBinaryLevel('parseBitXor', '|');
    }

    private function parseBitXor(): array
    {
        return $this->parseBinaryLevel('parseBitAnd', '^');
    }

    private function parseBitAnd(): array
    {
        return $this->parseBinaryLevel('parseEq', '&');
    }

    private function parseEq(): array
    {
        return $this->parseBinaryLevel('parseRel', '==', '!=');
    }

    private function parseRel(): array
    {
        return $this->parseBinaryLevel('parseShift', '<', '<=', '>', '>=');
    }

    private function parseShift(): array
    {
        return $this->parseBinaryLevel('parseAdd', '<<', '>>');
    }

    private function parseAdd(): array
    {
        return $this->parseBinaryLevel('parseMul', '+', '-');
    }

    private function parseMul(): array
    {
        return $this->parseBinaryLevel('parseUnary', '*', '/', '%');
    }

    private function parseBinaryLevel(string $nextLevel, string ...$ops): array
    {
        $left = $this->$nextLevel();
        while ($this->peekIsOp(...$ops)) {
            $kind = $this->next()['value'];
            $left = ['op' => 'bin', 'kind' => $kind, 'left' => $left, 'right' => $this->$nextLevel()];
        }
        return $left;
    }

    private function parseUnary(): array
    {
        if ($this->peekIsOp('-')) {
            $this->next();
            return ['op' => 'neg', 'operand' => $this->parseUnary()];
        }
        if ($this->peekIsOp('!')) {
            $this->next();
            return ['op' => 'not', 'operand' => $this->parseUnary()];
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): array
    {
        $t = $this->peek();

        if ($t['type'] === 'int') {
            $this->next();
            return ['op' => 'int', 'value' => gmp_init($t['value'])];
        }

        if ($t['type'] === 'id') {
            $this->next();
            switch ($t['value']) {
                case 'v':
                    return ['op' => 'v'];
                case 'k':
                    return ['op' => 'k'];
                case 'key':
                    $str = $this->peek();
                    if ($str['type'] !== 'str') {
                        throw new ProbeException(422, 'key requires a string label: key "label"');
                    }
                    $this->next();
                    $id = $this->registry->getId($str['value']);
                    if ($id === null) {
                        throw new ProbeException(422, 'Unknown label: ' . $str['value']);
                    }
                    return ['op' => 'int', 'value' => gmp_init($id)];
                case 'if':
                    $this->expectOp('(');
                    $cond = $this->parseExpr();
                    $this->expectOp(',');
                    $then = $this->parseExpr();
                    $this->expectOp(',');
                    $else = $this->parseExpr();
                    $this->expectOp(')');
                    return ['op' => 'if', 'cond' => $cond, 'then' => $then, 'else' => $else];
                default:
                    throw new ProbeException(422, "Unknown identifier '{$t['value']}'");
            }
        }

        if ($t['type'] === 'op' && $t['value'] === '(') {
            $this->next();
            $expr = $this->parseExpr();
            $this->expectOp(')');
            return $expr;
        }

        throw new ProbeException(422, 'Unexpected token in expression');
    }
}
