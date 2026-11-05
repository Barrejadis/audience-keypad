# Virtual Key Device — The probe

**Functional specification · Status:** Draft · **Normative language:** the key words MUST, MUST NOT, SHOULD, and MAY are to be interpreted as in RFC 2119.

Covers: probe language; endpoint (c) — admin aggregation; `reset` action; test vectors.
Depends on: [Overview](00-overview.md) (invariants) · [The keyboard](01-keyboard.md) (demo keyboard, ids) · [Storage (APCu)](03-storage.md) (user enumeration) · [Security](04-security.md) (admin auth, interpreter).

## 1. Program model

A probe folds one user's keystroke list into a single integer:

```
v₀ = init
vᵢ = eval(program, vᵢ₋₁, keyᵢ)    for i = 1..n, in index order
result = vₙ
```

`program` is exactly one expression; the fold is the only loop and it is provided by the system, not the program.

## 2. Grammar

```ebnf
program    = expr ;
expr       = orexpr ;
orexpr     = andexpr { "||" andexpr } ;
andexpr    = bitor { "&&" bitor } ;
bitor      = bitxor { "|" bitxor } ;
bitxor     = bitand { "^" bitand } ;
bitand     = eqexpr { "&" eqexpr } ;
eqexpr     = relexpr { ("==" | "!=") relexpr } ;
relexpr    = shift { ("<" | "<=" | ">" | ">=") shift } ;
shift      = addexpr { ("<<" | ">>") addexpr } ;
addexpr    = mulexpr { ("+" | "-") mulexpr } ;
mulexpr    = unary { ("*" | "/" | "%") unary } ;
unary      = ("-" | "!") unary | primary ;
primary    = INTEGER | "v" | "k" | keyref | ifexpr | "(" expr ")" ;
keyref     = "key" STRING ;
ifexpr     = "if" "(" expr "," expr "," expr ")" ;
```

All binary operators are left-associative. Precedence, lowest to highest:
`||` · `&&` · `|` · `^` · `&` · `== !=` · `< <= > >=` · `<< >>` · `+ -` · `* / %` · unary `- !`

## 3. Values and operator semantics

- **Integers are arbitrary-precision.** Implementations MUST NOT overflow (GMP).
- `v` is the current state; `k` is the key id of the current keystroke.
- `+`, `-`, `*`: exact arithmetic.
- `/`: truncation toward zero. `%`: remainder with the sign of the dividend (C/PHP style). Division or modulo by zero → `422`.
- Comparisons yield `1` or `0`.
- `&&`, `||`: short-circuit; truth is non-zero; result is `1` or `0`. `!x` is `1` if `x = 0`, else `0`.
- `&`, `|`, `^`: bitwise; operands MUST be non-negative, else `422`.
- `<<`, `>>`: shift amount MUST be in `[0, MAX_STATE_BITS]`, else `422`.
- `if(c, a, b)`: evaluates only the taken branch.
- `key "label"`: replaced by the label's integer id **at parse time**, using the registry of the device named in the request (attribution: *The keyboard*, §2). An unregistered label → `422 unknown label`. After parsing, the program is a pure integer expression.

## 4. Safety properties (normative)

- No loops, no recursion, no bindings: evaluation terminates and costs at most one traversal of the expression tree per keystroke.
- Program size MUST NOT exceed `MAX_PROGRAM_SIZE` → `413`.
- After each fold step, the bit length of the state MUST NOT exceed `MAX_STATE_BITS` → `422 state too large`. This bounds big-integer blowup (e.g. a submitted `v * v`, which squares the width every step).
- Programs have no I/O and no side effects; folding is deterministic.

## 5. Reference probes

```
count        = 0    : v + 1
count-label  = 0    : v + (k == key "5")
checksum     = 0    : v ^ k
last-key     = -1   : k
histogram    = 0    : v + (1 << (k * 9))     -- decode: counter i = (v >> (i * 9)) & 511
presence     = 1    : v                       -- every user yields 1, zero-keystroke users included; headcount = number of entries in the response map
```

Notes: the histogram packs a 9-bit counter per key id (9 bits hold any count up to 511 > `MAX_STROKES`); its width depends on the largest registered id, which is a probe concern, not a server concern. The cursor example in §9 shows a multi-field (x, y) state packed into a single integer with base-100 digits.

`presence` counts **sessions with live data**, not live connections: a user is enumerated as soon as they load a keyboard on the device — before any keystroke — and for up to `KB_TTL` after their last (a)/(b) request, since that is what keeps `kb:{dev}:u:{uid}:keys` alive (see *Storage (APCu)*).

## 6. Aggregation — endpoint (c)

**Request:** `POST admin.php`, `Content-Type: application/json`:

```json
{
  "kb": "NAME",
  "password": "secret",
  "init": "5050",
  "program": "if(k == key \"←\", if(v >= 100, v - 100, v), v)"
}
```

- `init`: decimal string, arbitrary precision, MAY be negative.
- `program`: string per the probe language (§1–§5).
- The password MUST travel in the POST body (never a query string, which leaks into access logs) and MUST be compared with `hash_equals` against the hardcoded `ADMIN_HASH` (SHA-256 hex of the admin password). Mismatch → `401`.

**Evaluation:**

1. Parse `program` (per §2–§3); size, parse, unknown-label, and semantic failures → `413`/`422`. `key "label"` references resolve against the request device's registry (see *The keyboard*, §2).
2. Enumerate users: every `uid` with a live `kb:{dev}:u:{uid}:keys` entry (i.e. every session that loaded a keyboard on this device). Sorted by uid for deterministic output.
3. For each user, fold their keystrokes in index order. A user with zero keystrokes yields `init`.
4. **Response:** `200`, `application/json`, an object mapping each uid to its value as a **decimal string** (JSON numbers cannot represent arbitrary-precision integers exactly):

```json
{ "abc123...": "5052", "def456...": "5099" }
```

**Reset action:** an admin can wipe a device by POSTing `{"kb": "NAME", "password": "secret", "action": "reset"}`. The server deletes every APCu key under the `kb:{NAME}:` prefix — label registry, counter, logs, user data — and returns `200 {"ok": true}`. This lets the admin reuse a device name for a completely fresh run without waiting for TTL expiry; a fresh device name achieves the same with zero cost. After a reset, labels re-register from id 0, which is consistent because their old logs are gone too.

**Alias management actions** also travel on this channel: `alias` (create or rebind a public name to a device definition) and `alias-del` (remove a binding), specified in *Aliases*, §4. They share this channel's password authentication and the `400`/`401` error conventions; probes themselves always address concrete device names and never resolve aliases.

## 7. Applied limits

```php
const MAX_PROGRAM_SIZE = 2048;  // probe program size cap (chars)
const MAX_STATE_BITS    = 4096; // per-step state width cap
const ADMIN_HASH        = '…';   // sha256 hex of the admin password
```

## 8. Errors

| Status | Condition |
|---|---|
| `401` | Admin password mismatch in (c) |
| `413` | Program exceeds `MAX_PROGRAM_SIZE` |
| `422` | Parse error; unknown label; division/modulo by zero; negative bitwise operand; shift out of range; state exceeding `MAX_STATE_BITS` |

The `400` for a malformed device name is shared with *The keyboard*.

## 9. Test vectors

Keyboard: `GET ?kb=demo&keys=←,→,↑,↓` (4 cells → 2×2) — shared scenario with *The keyboard*, §6. The device's registry assigns `←=0, →=1, ↑=2, ↓=3`.

**History H1** = presses `[→, ↑, ↑, ←]` = key ids `[1, 2, 2, 0]`:

| Probe | init | program | expected |
|---|---|---|---|
| count | `0` | `v + 1` | `4` |
| count ↑ | `0` | `v + (k == key "↑")` | `2` |
| checksum | `0` | `v ^ k` | `1` |
| last key | `-1` | `k` | `0` |
| histogram | `0` | `v + (1 << (k * 9))` | `524801` |

**Cursor probe** (state = `x * 100 + y`, both fields clamped to `[0, 99]`, start `(50, 50)`):

```
init 5050
program:
if(k == key "←", if(v >= 100, v - 100, v),
if(k == key "→", if(v < 9900, v + 100, v),
if(k == key "↑", if(v % 100 < 99, v + 1, v),
                 if(v % 100 > 0, v - 1, v))))
```

- On H1 → `5052`, decoding `(x=50, y=52)`.
- **History H2** = 200 × `↑` from the same init → `5099`: `y` clamps at 99 and stays there.
- **History H3** (limited-mode scenario, *The keyboard*, §6, device `quiz`) = `[→, ↑, ←]` = ids `[1, 2, 0]`, after an undo erased a stroke: `count` → `3`, `checksum` → `3` — an undo leaves no trace in the fold.

The clamp checks are what stop one packed field from bleeding into the other (e.g. `y` overflowing into `x`'s digits); the `MAX_STATE_BITS` check is the global backstop.
