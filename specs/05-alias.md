# Virtual Key Device — Aliases

**Functional specification · Status:** Draft · **Normative language:** the key words MUST, MUST NOT, SHOULD, and MAY are to be interpreted as in RFC 2119.

Covers: the alias concept; alias definitions and storage; entry via alias — endpoint (a); alias management actions — endpoint (c); the rerouting rule at endpoint (b); test scenario.
Depends on: [Overview](00-overview.md) (concepts, invariants) · [The keyboard](01-keyboard.md) (endpoint processing, validation, rendering) · [The probe](02-probe.md) (admin channel, actions) · [Storage (APCu)](03-storage.md) (schema) · [Security](04-security.md) (admin auth).

## 1. Concept

An **alias** is a stable public name bound by the administrator to a complete **device definition** — exactly the parameter set of endpoint (a): a target device name, a keyboard definition, an optional stroke limit. Users open the alias URL and never handle device names: the server resolves the alias and serves the keyboard of the target device with all the usual effects of (a).

**Motivating use case.** The administrator runs a sequence of activities, each on its own device (fresh name → own mode, registry, logs). Users enter through a single alias; at each transition the administrator rebinds the alias to the next device, and each user is carried to the new activity at their next keystroke — no manual re-initialization.

Properties:

- An alias is a **front-door indirection for (a) only**. Keystroke capture (b) always addresses a concrete device name; probes and reset (c) always address concrete devices; aliases are never resolved there.
- An alias holds no state beyond its definition: all device state (mode, registry, logs, user data) lives in the target device's namespace, unchanged.
- No real-time push (non-goal, *Overview* §2): an idle user is routed at their next keystroke or page load, never proactively.

## 2. Alias definition

| Field | Meaning | Validation (failure → `400`) |
|---|---|---|
| `alias` | alias name | MUST match `KB_NAME_RE` |
| `kb` | target device name | MUST match `KB_NAME_RE` |
| `keys` | keyboard definition | exactly as in *The keyboard* §1: segment count in `KEYBOARD_SIZES`, unique active labels, comma rules |
| `limit` | stroke budget (optional) | integer `0 < L ≤ MAX_STROKES`; presence selects limited mode |

Validation is **structural**: it checks the definition alone, at write time. The target device need not exist yet — it is created by the first (a) that resolves the alias, which applies the mode-recording and conflict rules of *The keyboard* §1 unchanged.

Alias names and device names are **independent namespaces**: they are never compared, because the entry parameters are distinct (`alias` vs `kb`); an alias MAY share its name with a device.

**Storage** (*Storage (APCu)*): `alias:{name}` → JSON definition, no TTL — admin-owned data like the label registry, wiped only by an APCu restart or explicit deletion.

## 3. Entry via alias — endpoint (a)

**Request:** `GET index.php?alias=NAME`

The `alias` parameter MUST be the only definition parameter: combined with `kb`, `keys`, or `limit` → `400` (the alias carries the whole definition).

**Processing:**

1. `NAME` MUST match `KB_NAME_RE`; otherwise `400`.
2. Resolve `alias:{NAME}`. Absent → **`404`** with a human-readable explanatory page (unknown alias). This is the system's only `404`: it distinguishes "well-formed name, no such binding" from the `400` family of malformed requests.
3. Substitute the stored definition as the request parameters and process endpoint (a) exactly as specified in *The keyboard* §1: validation, mode recording, label registry, user key set, rendering. The device's mode-conflict rules apply unchanged.
4. Record the **entry marker** `kb:{dev}:u:{uid}:via = NAME` (TTL `KB_TTL`, refreshed like the other per-user data). The marker is the only alias-related state inside a device namespace, and it is per device: it records how the user's current key set on *this* device was established.

The served page's URL is the alias URL; it embeds the resolved device name for its (b) posts.

**Direct entry.** `GET ?kb=NAME` (no alias) processes exactly as in *The keyboard* and sets no marker — it also clears any existing marker on that device: **last entry wins**. A user who entered directly is never rerouted (§5).

## 4. Alias management — endpoint (c)

Alias actions travel on the admin channel of (c) (*The probe* §6), with the same password authentication (*Security*): `password` in the POST body, compared with `hash_equals` against `ADMIN_HASH`; mismatch → `401`.

| Action | Request | Effect |
|---|---|---|
| Upsert | `{"password", "action": "alias", "alias", "kb", "keys", "limit"?}` | Creates or overwrites the binding. Overwriting with an identical definition is a no-op. Rewriting has no immediate effect on already-loaded users: rerouting is evaluated per keystroke (§5). |
| Delete | `{"password", "action": "alias-del", "alias"}` | Removes the binding; unknown alias → no-op. Users entered through it are rerouted at their next keystroke, onto the `404` page. |

Structural validation failures → `400` (§2). Success → `200 {"ok": true}`. Repointing is the normal way to move or retire an activity; deletion is housekeeping.

## 5. Rerouting rule — endpoint (b)

The keystroke POST carries the concrete device name `kb`, as in *The keyboard* §3; (b) MUST NOT accept or resolve an `alias` parameter — a keystroke belongs to the keyboard that displayed it, not to whatever an alias points to by the time it lands.

**Routing check** (applies to both `key` and `undo`, before the command processing of *The keyboard* §3): if `kb:{dev}:u:{uid}:via` exists, resolve `alias:{via}`:

- **Unchanged** — the alias exists and its target `kb` equals the request's device → normal processing.
- **Modified** — the alias is absent (deleted) or its target `kb` differs from the request's device → **nothing is recorded or erased** (no counter increment, no log entry, no budget consumed; the keystroke is dropped, not replayed) and the server responds **`409`** with `{"ok": false, "reroute": true}`.

The modification criterion is **the target device name only**. A definition rewrite that keeps the same target device does not reroute anyone: users keep the keyboard they were served, and presses of keys in their served key set keep recording on that device; the new definition applies to (a) loads from that point on. Likewise, an alias repointed away and back to the same device before the user's next keystroke records normally — the check compares the current target with the request device, not an event history.

**Client behavior.** On a `409` with `reroute`, the page MUST reload its URL — the alias URL — automatically, which re-enters (a) and serves the new target's keyboard. From the user's point of view the new device simply replaces the old one: the page MUST NOT display any transition notice, intermediate message, or other auxiliary content. The device is operated under a facilitator's direction, never autonomously — every display beyond the requested keyboard is noise (the same minimalism as main mode, *The keyboard* §1). The dropped keystroke is not carried over: the user presses again on the new keyboard, at the facilitator's pace.

**Consequences:**

- Limited mode: a reroute consumes no budget on either device; the new device gives the user a fresh allowance.
- A user who entered a device directly (no marker) is never rerouted, whatever happens to aliases pointing at that device.
- The admin `reset` of a target device wipes the marker with the user's data: a stale press then fails `400` (unregistered key, *The keyboard* §3) until the page is reloaded. Repointing the alias — a `409` reroute — is the clean mid-activity transition; reset is not.

## 6. Applied limits

```php
const ALIAS_RE = KB_NAME_RE;  // alias names reuse the device name pattern
```

No other constant: an alias definition reuses the validation of (a) (*The keyboard* §4).

## 7. Errors

| Status | Condition |
|---|---|
| `400` | (a): `alias` combined with any of `kb`/`keys`/`limit`; malformed alias name. (c): alias action with a structurally invalid definition |
| `401` | Admin password mismatch on an alias action |
| `404` | (a): well-formed alias name with no binding |
| `409` | (b): user entered via an alias that is deleted or retargeted to another device |

## 8. Test scenario

Setup — admin upsert: `act` → `{kb: "act1", keys: "A,B,C,D"}` (4 cells → 2×2).

1. Users u1, u2 open `GET ?alias=act` → served `act1`'s keyboard; the registry of `act1` assigns `A=0, B=1, C=2, D=3`; both carry the marker `via=act`.
2. u1 presses `A` → `200`, recorded on `act1`.
3. Admin upsert: `act` → `{kb: "act2", keys: "E,F"}` (2 cells → 2×1).
4. u1 presses `B` → `409 {"ok":false,"reroute":true}`; u1's counter on `act1` is still 1, no log entry. The page reloads `?alias=act` → served `act2`'s keyboard (`E=0, F=1` in `act2`'s fresh registry).
5. u1 presses `E` → `200`, recorded on `act2`.
6. u2 (idle through the transition) presses `A` later → same `409` → reload → `act2`.
7. A user who had opened `GET ?kb=act1` directly presses `B` → `200`, recorded on `act1` — never routed.
8. Probe (c) on `act1`: folds u1's `[A]` and the direct user's data; alias names never appear in (c).

Deletion scenario: admin `alias-del act`; u1 (entered via `act`, now on `act2`) presses `E` → `409`; the reload lands on the `404` unknown-alias page.
