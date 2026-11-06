# audience-keypad

A virtual keypad that allows the audience to interact with a presenter: anonymous users press labeled keys in their browser, and an administrator aggregates the results with a small, closed expression language.

It is a single PHP application with **no dependencies and no build step**: a web server with the PHP module and the APCu extension is all it takes. State lives entirely in APCu shared memory, so nothing survives a server restart by design.

## How it works

Each run is a **device**: a named keyboard (up to 25 keys, square-ish grid) whose definition is claimed at first load and never changed. Every key press is recorded in the pressing user's log, identified by a stable key id derived from its label — capture is interpretation-free. At query time, the admin submits a **probe**: an initial value plus one pure expression over `v` (state) and `k` (key id). The server folds each user's keystroke history with that expression and returns one value per user.

Two capture modes:

- **main** — a plain keyboard page with no on-device feedback (e.g. continuous activity such as a game whose feedback lives on another display).
- **limited** — each user gets a stroke budget `L`, sees their whole keystroke history permanently, and can erase the last keystroke (e.g. entering a choice in answer to a question).

**Aliases** let the admin bind a stable public name to a device definition, so users keep one URL across a sequence of devices and are rerouted automatically when the alias is retargeted.

## Requirements

- PHP with the APCu extension enabled
- A web server with the PHP module (Apache + mod_php or equivalent)
- No Composer, no `vendor/`, no framework, no build system

## Deployment

Deployment is a copy: copy the contents of `src/` to the server. Nothing else ships.

## Usage

Four endpoints (relative to where you deployed `src/`):

| Endpoint | Method | Purpose |
|---|---|---|
| `index.php` | GET | Serve the keyboard page |
| `keystroke.php` | POST | Record a keystroke, or undo the last one |
| `probe.php` | POST | Admin: run a probe, or reset a device |
| `alias.php` | POST | Admin: create, rebind, or delete an alias |

**Serve a keyboard:**

```
GET index.php?kb=demo&keys=←,→,↑,↓            (main mode)
GET index.php?kb=quiz&keys=A,B,C,D&limit=3    (limited mode)
```

`keys` is a comma-separated label list; the number of cells must be one of 1, 2, 4, 6, 9, 12, 16, 20, 25. An empty segment or a single space marks an inactive cell. A device accepts exactly one definition; a different run uses a fresh device name (or reset the device first).

**Record a keystroke** (the served page does this for you):

```
POST keystroke.php   kb=demo&key=1          → 200 {"ok":true,"remaining":299}
POST keystroke.php   kb=quiz&undo          → 200 {"ok":true,"remaining":1}
```

**Run a probe** (password in the POST body, never a query string):

```json
POST probe.php
{
  "kb": "demo",
  "password": "secret",
  "init": "0",
  "program": "v + (k == key \"↑\")"
}
```

The response maps every user id to its folded value: `{"abc123...": "2", "def456...": "0"}`. The probe language is closed (no loops, no recursion, no I/O) with arbitrary-precision integers; reference probes include `v + 1` (count), `v ^ k` (checksum), `k` (last key), `v + (1 << (k * 9))` (histogram), and plain `v` (presence/headcount). `{"action": "reset"}` wipes a device's whole namespace.

**Admin password:** compared as SHA-256 against the `ADMIN_HASH` constant in `src/Constants.php`. The default password is `admin` — change it before real use by replacing the constant with the hash of a new password.

**Demo page:** `demo.html` ships in `src/` as a driver for trying keyboards, aliases, and probes without curl.

## Development

The only development-time tool is PHPUnit, as a gitignored phar in `tests/`:

```bash
wget -O tests/phpunit.phar https://phar.phpunit.de/phpunit-12.phar
php tests/phpunit.phar tests/
```

Unit tests cover domain classes only; endpoints stay thin dispatchers.

## Repository layout

```text
src/     production code, deployed as-is (flat: endpoints, domain classes, demo assets)
tests/   unit tests and PHPUnit tooling, never deployed
specs/   normative specification corpus
```

See [AGENTS.md](AGENTS.md) for the project rules that keep it minimal, and the `specs/` corpus (overview, keyboard, probe, storage, security, aliases) for the authoritative behavior.

## License

AGPL-3.0 — see [LICENSE](LICENSE).