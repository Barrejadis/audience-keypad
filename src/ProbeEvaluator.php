<?php
declare(strict_types=1);

require_once __DIR__ . '/Constants.php';
require_once __DIR__ . '/ProbeException.php';

/**
 * Evaluator for probe ASTs (spec 02-probe §3–§4).
 *
 * All arithmetic is arbitrary-precision (GMP); nothing overflows.
 *   / truncates toward zero, % keeps the sign of the dividend
 *   && / || short-circuit and yield 1/0; comparisons yield 1/0
 *   & | ^ require non-negative operands
 *   << / >> shift amounts must lie in [0, MAX_STATE_BITS]
 *   if(c, a, b) evaluates only the taken branch
 * Every semantic failure throws ProbeException with status 422.
 */
class ProbeEvaluator
{
    /**
     * Evaluate one AST node for one fold step.
     *
     * @param array $node AST produced by ProbeParser
     */
    public static function evaluate(array $node, GMP $v, GMP $k): GMP
    {
        switch ($node['op']) {
            case 'int':
                return $node['value'];
            case 'v':
                return $v;
            case 'k':
                return $k;
            case 'neg':
                return gmp_neg(self::evaluate($node['operand'], $v, $k));
            case 'not':
                return gmp_cmp(self::evaluate($node['operand'], $v, $k), 0) === 0
                    ? gmp_init(1)
                    : gmp_init(0);
            case 'and':
                if (gmp_cmp(self::evaluate($node['left'], $v, $k), 0) === 0) {
                    return gmp_init(0);
                }
                return gmp_cmp(self::evaluate($node['right'], $v, $k), 0) !== 0
                    ? gmp_init(1)
                    : gmp_init(0);
            case 'or':
                if (gmp_cmp(self::evaluate($node['left'], $v, $k), 0) !== 0) {
                    return gmp_init(1);
                }
                return gmp_cmp(self::evaluate($node['right'], $v, $k), 0) !== 0
                    ? gmp_init(1)
                    : gmp_init(0);
            case 'if':
                $cond = self::evaluate($node['cond'], $v, $k);
                return self::evaluate(
                    gmp_cmp($cond, 0) !== 0 ? $node['then'] : $node['else'],
                    $v,
                    $k
                );
            case 'bin':
                return self::evaluateBinary($node['kind'], $node['left'], $node['right'], $v, $k);
        }
        throw new ProbeException(422, 'Invalid AST node');
    }

    private static function evaluateBinary(string $kind, array $left, array $right, GMP $v, GMP $k): GMP
    {
        $a = self::evaluate($left, $v, $k);
        $b = self::evaluate($right, $v, $k);

        switch ($kind) {
            case '+':
                return gmp_add($a, $b);
            case '-':
                return gmp_sub($a, $b);
            case '*':
                return gmp_mul($a, $b);
            case '/':
                if (gmp_cmp($b, 0) === 0) {
                    throw new ProbeException(422, 'Division by zero');
                }
                return gmp_div_q($a, $b); // truncates toward zero
            case '%':
                if (gmp_cmp($b, 0) === 0) {
                    throw new ProbeException(422, 'Modulo by zero');
                }
                return gmp_div_r($a, $b); // sign of the dividend
            case '==':
                return gmp_cmp($a, $b) === 0 ? gmp_init(1) : gmp_init(0);
            case '!=':
                return gmp_cmp($a, $b) !== 0 ? gmp_init(1) : gmp_init(0);
            case '<':
                return gmp_cmp($a, $b) < 0 ? gmp_init(1) : gmp_init(0);
            case '<=':
                return gmp_cmp($a, $b) <= 0 ? gmp_init(1) : gmp_init(0);
            case '>':
                return gmp_cmp($a, $b) > 0 ? gmp_init(1) : gmp_init(0);
            case '>=':
                return gmp_cmp($a, $b) >= 0 ? gmp_init(1) : gmp_init(0);
            case '&':
            case '|':
            case '^':
                if (gmp_sign($a) < 0 || gmp_sign($b) < 0) {
                    throw new ProbeException(422, 'Negative bitwise operand');
                }
                if ($kind === '&') {
                    return gmp_and($a, $b);
                }
                return $kind === '|' ? gmp_or($a, $b) : gmp_xor($a, $b);
            case '<<':
            case '>>':
                if (gmp_cmp($b, 0) < 0 || gmp_cmp($b, MAX_STATE_BITS) > 0) {
                    throw new ProbeException(422, 'Shift amount out of range [0, ' . MAX_STATE_BITS . ']');
                }
                $power = gmp_pow(2, gmp_intval($b));
                if ($kind === '<<') {
                    return gmp_mul($a, $power);
                }
                // >> is an arithmetic shift right, i.e. floor(a / 2^n)
                [$q, $r] = gmp_div_qr($a, $power);
                if (gmp_cmp($r, 0) < 0) {
                    // r carries the dividend's sign: floor needs one less
                    $q = gmp_sub($q, 1);
                }
                return $q;
        }
        throw new ProbeException(422, "Unknown operator '$kind'");
    }

    /**
     * Fold a keystroke history into a single value (spec §1):
     * v₀ = init, vᵢ = eval(program, vᵢ₋₁, keyᵢ), result = vₙ as a decimal string.
     *
     * After each fold step the state's bit length must not exceed
     * MAX_STATE_BITS, else 422 "state too large".
     *
     * @param array $program AST produced by ProbeParser
     * @param string $init Decimal string, arbitrary precision, may be negative
     * @param list<int> $history Key ids in index order
     * @return string The folded value as a decimal string
     * @throws ProbeException 422 on invalid init, semantic error, or state too large
     */
    public static function fold(array $program, string $init, array $history): string
    {
        if (!preg_match('/^-?[0-9]+$/', $init)) {
            throw new ProbeException(422, 'init must be a decimal string');
        }

        $v = gmp_init($init);
        foreach ($history as $keyId) {
            $v = self::evaluate($program, $v, gmp_init((int)$keyId));
            if (strlen(gmp_strval(gmp_abs($v), 2)) > MAX_STATE_BITS) {
                throw new ProbeException(422, 'State too large (exceeds MAX_STATE_BITS)');
            }
        }
        return gmp_strval($v, 10);
    }
}
