# Virtual Key Device — The keyboard

**Functional specification · Status:** Draft · **Normative language:** the key words MUST, MUST NOT, SHOULD, and MAY are to be interpreted as in RFC 2119.

Covers: endpoint (a) — keyboard page, main and limited modes; endpoint (b) — keystroke capture and undo; key id attribution.
Depends on: [Overview](00-overview.md) (concepts, invariants) · [Storage (APCu)](03-storage.md) (schema, TTLs) · [Security](04-security.md) (sessions, label escaping).

## 1. Keyboard definition and page — endpoint (a)

**Request:** `GET index.php?kb=NAME&keys=LABELS[&limit=L]`

The same page is served through an **alias** entry — `GET index.php?alias=NAME` — which resolves an admin-defined binding to this parameter set; resolution, marker recording, and the unknown-alias `404` are specified in *Aliases*.

`NAME` identifies the **device instance**. It MUST match `[A-Za-z0-9_-]{1,32}`; anything else → `400`. All stored data is namespaced under the device name (see *Storage (APCu)*): two devices never interact, and a fresh device name means fresh storage with no reset step.

`LABELS` is a comma-separated list (U+002C separator). The list is split on commas; each segment is taken verbatim as one label. A segment that is a single space `" "` or empty is an **inactive cell**. In URLs the space label SHOULD be encoded `%20`. The comma MUST NOT appear inside a label.

`L`, when present, selects the **limited mode**: each user may record at most `L` keystrokes on this device, and the page permanently displays the user's whole keystroke history on that device, with an erase control. `L` MUST be an integer with `0 < L ≤ MAX_STROKES`; anything else → `400`. Without `limit`, the page is served in **main mode**.

**Validation (each failure → `400` with an explanatory body):**

1. The number of segments MUST be exactly one of `1, 2, 4, 6, 9, 12, 16, 20, 25`.
2. Active labels MUST be unique within the keyboard.
3. The device name MUST match `[A-Za-z0-9_-]{1,32}`.
4. `limit`, when present, MUST be an integer with `0 < L ≤ MAX_STROKES`.

**Device mode.** The mode is a property of the **device**, not of the page load or the user: a device name is used in exactly one mode. The first (a) served on a device records it — `kb:{dev}:mode` = `main` or `limited`, plus `kb:{dev}:limit = L` in limited mode (no TTL, like the label registry; schema in *Storage (APCu)*). Any later (a) on that device that conflicts with the recorded mode — `limit` present on a `main` device, absent on a `limited` device, or a different `L` — → `400` with an explanatory body; a different run uses a fresh device name.

**Geometry** is fixed by the segment count — no padding, no choice:

| Cells | Grid (cols × rows) |
|---|---|
| 1 | 1×1 |
| 2 | 2×1 |
| 4 | 2×2 |
| 6 | 3×2 |
| 9 | 3×3 |
| 12 | 4×3 |
| 16 | 4×4 |
| 20 | 5×4 |
| 25 | 5×5 |

Each entry is the squarest factorization of its capacity ("as square as possible" by construction). The grid is filled in **row-major order** — left to right, then top to bottom: segment 0 is the top-left cell and the last segment the bottom-right. For a 3×2 keyboard (cols = 3, rows = 2) the segments therefore describe cells in this coordinate order: `(0,0) (1,0) (2,0) (0,1) (1,1) (2,1)`.

**Server-side effects:**

- Start the PHP session; `uid = session_id()`.
- For each active label, obtain its key id via the label registry (see §2).
- Store the user's active key id set under `kb:{dev}:u:{uid}:keys` (TTL per *Storage (APCu)*).
- Alias entry additionally records the per-user entry marker `kb:{dev}:u:{uid}:via` (*Aliases*, §3).
- **Limited mode** — the page embeds the user's whole keystroke history on this device (every recorded stroke, oldest first, as labels) for permanent display, together with the remaining allowance (`L − n`).

**Rendering requirements (normative for the served page):**

- The keyboard MUST be a CSS grid with `cols` columns; cells MUST be square; the overall container SHOULD be as close to square as its shape allows.
- Active cells MUST be buttons displaying their label; a button MUST be disabled while its POST is in flight and MUST surface failures visibly.
- Inactive cells MUST render as blank, non-interactive placeholders: a static element, not a button. There is nothing to activate, so no `disabled` attribute is required — the cell simply is not interactive.
- Labels MUST be HTML-escaped when rendered: they arrive via URL (or, on rehydration, from the registry) and are the only reflected content on the page (see *Security*).

**Main mode — rendering:**

- The page MUST render the keyboard alone: no visible page furniture — no title or heading in the page body, no counters. A `<title>` element naming the page for the browser tab is document metadata, not page content; it does not violate this rule. In particular it MUST NOT display the remaining keystroke allowance; the value remains available in (b) responses for callers that want it.

**Limited mode — rendering (in addition to the grid rules):**

- The page MUST permanently display the user's whole keystroke history on the device as a sequence, oldest first, each stroke shown as the pressed key's label (HTML-escaped). The history renders as a single row **above the keyboard grid**, so the feedback sits directly on top of the keys it describes. This display is the device's feedback; in limited mode there is no other.
- The display is **not page-local**: on every load of (a) — including page reloads — the page shows the user's full recorded history on the device, exactly as before the reload; a refresh neither clears nor restarts the feedback, and the remaining allowance is recomputed server-side from the persistent counter. Only TTL expiry of the user's data or an admin reset clears the display.
- A keystroke MUST NOT be displayed before (b) has returned `200` for it (no optimistic rendering); a rejected or failed press MUST NOT appear.
- The page MUST render one **erase control** in the history row, immediately after the last displayed keystroke at the row's trailing edge: the control belongs with the history it erases, not with the keys. It is a server-synthesized button outside the grid, not a keyboard cell, with no entry in the label registry. It MUST be disabled while its POST is in flight and whenever the displayed history is empty (nothing to erase). An erase is reflected on screen only after a `200` from (b) (the last displayed keystroke is removed); a failed erase MUST NOT change the display.
- When the limit is reached (remaining `0`), the page MUST disable every active key, leaving the erase control as the only usable control — the budget can only be freed by erasing.
- The page MAY display the remaining allowance (`L − keystrokes recorded`).

## 2. Key id attribution — the label registry (write side)

Key ids are **content-addressed, not layout-derived**, so probes survive reordering, resizing, and re-padding of keyboards (invariant: *Overview*, "Label-based key identity").

Rules:

- The registry is **per device**: `kb:{dev}:lab:{label} → id`, with a counter `kb:{dev}:lab:next`. Each label gets the next free id (starting at 0) the first time any keyboard *of that device* displays it.
- Registry entries have **no TTL**. This is safe because the keystroke logs are APCu too: a restart or expiry clears logs and registry *together*, so ids are never reassigned under existing logs.

Consequences for keyboards (all intended):

- Reordering keys, changing grid size, or moving inactive cells around has no effect on ids.
- Two keyboards of the same device sharing a label share its id; a device's probe vocabulary is the set of labels ever served *on that device*, not any one keyboard's layout. Devices have fully independent id spaces.
- Renaming a label referenced by a probe fails loudly at query-parse time (`422 unknown label`), never silently — specified in *The probe*.

## 3. Keystroke capture — endpoint (b)

**Request:** `POST keystroke.php` with form field `kb` (device name) and **exactly one** of: `key` (integer key id — record a keystroke) or `undo` (erase the last keystroke of the current limited session). Both fields together, or neither → `400`.

**Routing check (both commands).** When the user entered this device through an alias, the entry marker `kb:{dev}:u:{uid}:via` exists; the request is then checked against the alias's current binding (*Aliases*, §5): if the alias is deleted or retargeted to another device → `409 {"ok":false,"reroute":true}`, and nothing is recorded or erased. The check runs before the command processing below.

**Processing of `key` (in order):**

1. Resolve `uid` from the session.
2. `key` MUST be an integer present in `kb:{dev}:u:{uid}:keys`; otherwise `400` (this also rejects wrong device names, presses from sessions that never loaded a keyboard on this device, and ids of inactive keys, which are never registered).
3. `n = apcu_inc("kb:{dev}:u:{uid}:n")`. If `n > cap` → `429` (the stroke is not stored) **and the counter is rolled back to `n−1`**, where `cap` is `L` on a limited device and `MAX_STROKES` otherwise (`L ≤ MAX_STROKES` by validation, so the global cap is subsumed in limited mode). The rollback keeps the counter equal to the number of stored strokes: a rejected press consumes no budget and leaves no phantom count at the tail of the log, so the sequence press → `429` → `undo` → press behaves exactly as the §6 scenario requires (without it, `undo` would erase a nonexistent slot and the following press would be rejected again).
4. Store `kb:{dev}:u:{uid}:{n−1} = key`.
5. Respond `200` with JSON `{"ok": true, "remaining": cap − n}`.

**Processing of `undo` (in order):**

1. Resolve `uid` from the session.
2. The device MUST be a limited device (`kb:{dev}:mode = limited`); otherwise `400`. On a limited device every recorded stroke is a limited-mode stroke, so the undo scope is simply the user's own log.
3. The user MUST have at least one recorded keystroke (`n > 0`); otherwise `400` (nothing to erase).
4. Delete `kb:{dev}:u:{uid}:{n−1}` and decrement the counter to `n−1` (refresh per-user TTLs).
5. Respond `200` with JSON `{"ok": true, "remaining": L − (n−1)}` (evaluated after the decrement).

**Ordering:** the per-user index order equals press order, because the index comes from an atomic counter. `undo` removes the tail of the log and decrements the same counter, and a rejected press is rolled back (step 3), so the log stays strictly index-ordered and probe folds over `0..n−1` remain consistent (*The probe*, §1). There is no defined ordering *across* users, and none is needed (see *Overview*, "System overview").

**Cap scope:** the keystroke cap applies **per user and per device name** — `L` on a limited device, `MAX_STROKES` otherwise. The counter is `kb:{dev}:u:{uid}:n` — part of the device namespace (see *Storage (APCu)*) — so a user who exhausts their allowance on one device has a full, independent allowance on every other device. An undo restores budget, since the counter is decremented — correcting a mistake does not consume allowance, and neither does a rejected press (rolled back).

**Typical shapes.** A main device hosts a large, continuous keystroke flow with no on-device feedback (e.g. a game whose feedback lives on another display). A limited device hosts a small budget — `L` typically a handful — because the user's whole history on the device is permanently displayed (e.g. entering a choice in answer to a question).

## 4. Applied limits

```php
const MAX_STROKES   = 300;    // keystroke cap per user per device name
const KB_TTL        = 3600;   // per-user data lifetime (seconds)
const KEYBOARD_SIZES = [      // cell count => [cols, rows] (geometry table in §1)
    1  => [1,1],  2  => [2,1],  4  => [2,2],  6  => [3,2],
    9  => [3,3],  12 => [4,3],  16 => [4,4],  20 => [5,4],
    25 => [5,5],
];
const KB_NAME_RE    = '/^[A-Za-z0-9_-]{1,32}$/'; // device name pattern
```

## 5. Errors

| Status | Condition |
|---|---|
| `400` | Malformed or missing device name; malformed keyboard definition (bad key count, duplicate active labels); malformed `limit`; (a) conflicting with the device's recorded mode; invalid or unregistered `key` id in (b); `key` and `undo` both or neither present in (b); `undo` on a main-mode device or with an empty history |
| `429` | Per-user keystroke cap reached: `L` (limited device) or `MAX_STROKES` (main device); the counter is rolled back, so the press consumes no budget |
| `409` | Rerouting (*Aliases*): keystroke or undo from a user whose alias entry is deleted or retargeted to another device |

## 6. Shared test scenario

The demo keyboard used by *The probe*'s test vectors: `GET index.php?kb=demo&keys=←,→,↑,↓` (4 cells → 2×2). The device's registry assigns `←=0, →=1, ↑=2, ↓=3` (first-seen order). Reference press histories: **H1** = `[→, ↑, ↑, ←]` = ids `[1, 2, 2, 0]`; **H2** = 200 × `↑`.

**Limited-mode scenario:** a separate device `GET index.php?kb=quiz&keys=←,→,↑,↓&limit=3` — a device name is used in exactly one mode, so this cannot be `demo`; the fresh registry assigns the same ids by first-seen order (`←=0, →=1, ↑=2, ↓=3`). Presses `→, ↑, ↑` succeed (remaining 0 — keyboard disabled except erase); a fourth press `←` → `429` (counter rolled back to 3, nothing stored); `undo` erases the second `↑` (remaining 1); press `←` succeeds. Reference vector **H3** = `[→, ↑, ←]` = ids `[1, 2, 0]` (probe `count` → `3`, `checksum` → `3`).
