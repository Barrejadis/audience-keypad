# Virtual Key Device — Storage (APCu)

**Technical specification · Status:** Draft · **Normative language:** the key words MUST, MUST NOT, SHOULD, and MAY are to be interpreted as in RFC 2119.

Covers: storage schema, TTLs, user enumeration, memory bounds, device isolation and reset.
Depends on: [Overview](00-overview.md) (invariants) · [The keyboard](01-keyboard.md) (TTL refresh points) · [The probe](02-probe.md) (reset action).

## 1. Schema

All keys are namespaced by device name (`{dev}` = the `kb` request parameter):

| Key | Content | TTL |
|---|---|---|
| `kb:{dev}:lab:{label}` | key id (int) | none |
| `kb:{dev}:lab:next` | next-id counter | none |
| `kb:{dev}:u:{uid}:keys` | JSON array of the user's active key ids | `KB_TTL` |
| `kb:{dev}:u:{uid}:n` | keystroke count | `KB_TTL` |
| `kb:{dev}:u:{uid}:{i}` | key id of keystroke `i` | `KB_TTL` |
| `kb:{dev}:mode` | device mode: `main` or `limited` | none |
| `kb:{dev}:limit` | stroke budget `L` (limited devices only) | none |
| `kb:{dev}:keys` | JSON ordered label list — the device's keyboard definition (*The keyboard* §1) | none |
| `kb:{dev}:u:{uid}:via` | alias name the user entered this device through (alias entry only) | `KB_TTL` |
| `alias:{name}` | JSON device definition bound to the alias | none |

## 2. TTL behavior

- All per-user TTLs are refreshed on (a) and (b); abandoned sessions therefore expire on their own. The alias entry marker `via` is one of these per-user entries.
- Device entries (`mode`, `limit`, `keys`) carry **no TTL**, like the registry: they describe the device — its claimed definition — not any user, and are wiped with everything else by a reset or an APCu restart. Alias definitions (`alias:{name}`) carry **no TTL** likewise: they are admin-owned bindings, wiped only by an APCu restart or explicit deletion (*Aliases*, §4).
- Registry entries carry **no TTL**: a restart or expiry clears logs and registry together, so ids are never reassigned under existing logs (invariant: *Overview*, "Label-based key identity").

## 3. User enumeration

User enumeration for (c) uses `apcu_cache_info()` filtered on the `:keys` suffix — acceptable at admin-tool scale.

## 4. Memory bounds

Memory is bounded: at most `MAX_STROKES` per-index entries per live user per device (`L` on a limited device, `L ≤ MAX_STROKES`), plus one entry per label of each device's single keyboard definition (bounded by the device's key count) and one definition entry per device (`mode`, `limit` when present, `keys`). An undo only decrements the counter and deletes one entry, so the bound is unaffected. Aliasing adds one definition entry per alias and one small entry marker per live user per device — the bound is unaffected (*Aliases*).

## 5. Device isolation and reset

- Namespacing by device name gives full isolation: two devices never interact, and a fresh device name means fresh storage with no reset step.
- Alias definitions live outside device namespaces (`alias:{name}`), so a reset of a device never touches the aliases pointing at it; an alias to a reset device simply recreates fresh state at the next (a) — consistent with reset semantics (*Aliases*).
- The admin `reset` action (behavior specified in *The probe*, §6) deletes every APCu key under the `kb:{NAME}:` prefix — label registry, counter, logs, user data. After a reset, labels re-register from id 0, which is consistent because their old logs are gone too. A fresh device name achieves the same result at zero cost.

## 6. Requirements

PHP ≥ 8.1 with the `apcu` and `gmp` extensions; standard PHP sessions (hardening requirements: *Security*).

