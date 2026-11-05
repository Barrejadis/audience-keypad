# AGENTS.md

This file gives coding agents the rules for working in this repository. Read it before writing any code. The rules below are constraints, not suggestions.

## Project philosophy

This project is deliberately minimal. Every rule below exists to keep it that way. If a change makes the project less simple, it is wrong even if it works.

- **No dependencies.** The runtime uses nothing beyond the PHP interpreter and its bundled/default extensions. No Composer, no `vendor/`, no third-party libraries, no frameworks.
- **No build system.** What is committed in `src/` is exactly what is deployed. There are no compilation, bundling, code-generation, or transformation steps. Never introduce one.
- **Deployment is a copy.** The project runs on a web server with the PHP module. Deploying means copying the contents of `src/` to the server. Nothing else ships.

## Repository layout

```text
/               repository root (never deployed)
  src/          ALL production code; deployed as-is
  tests/        unit tests and test tooling; never deployed
  AGENTS.md     this file
```

- Everything in `src/` lives in that single folder: no subfolders, no namespaces directories, no `lib/`, no `public/` split. The folder is flat and stays flat.
- `tests/` mirrors nothing structurally; it holds test files and the PHPUnit phar.

## Three kinds of files

1. **Web endpoints** — in `src/`. Entry points that the web server invokes. There are only a few of them, and each one dispatches internally between the operations it serves.
2. **Domain model** — in `src/`. All business logic. This is where the real code lives.
3. **Unit tests** — in `tests/`. They cover domain files only. Endpoints have no unit tests; that is intentional, and it is also how you tell the roles apart: a domain file has a matching test file, an endpoint does not.

## Web endpoint rules

An endpoint file must be as short as possible. Its entire job is: parse the request → call a domain function or method → emit the HTTP response. If you find yourself writing logic in an endpoint, move that logic to a domain file.

- Keep every endpoint under a few dozen lines. If it grows, that logic belongs in the domain.
- Load domain files with `require_once` at the top of the endpoint. Do not add an autoloader, a bootstrap, or a router abstraction.
- Dispatching between operations inside an endpoint is allowed; doing the work there is not.
- No output beyond the intended response. No debugging echoes left behind.

## Domain model rules

- One class per file. A domain file may also contain small helper functions used by that class, but do not mix unrelated responsibilities in one file.
- All validation, computation, state, and business decisions happen here, not in endpoints.
- Domain code must be callable and testable from a script without a web server: no reliance on `$_SERVER`, `$_GET`, `$_POST`, `$_SESSION`, or superglobals inside domain files. Endpoints read the HTTP world and pass plain values in.
- Files must start with `declare(strict_types=1);`.

## Unit tests

- One test file per domain file, named after it: `src/Foo.php` is tested by `tests/FooTest.php`.
- Tests construct domain objects directly, pass values explicitly, and assert on results. No HTTP, no web server, no superglobals.
- Every domain file must have tests, and every change to a domain file must come with the tests that prove it. Do not write tests for endpoints.
- Never test through an endpoint. If the only way to test a behavior is through an endpoint, the behavior is in the wrong file — move it to the domain.

## Running the tests

The only development-time tool is PHPUnit, confined to `tests/`. It is a phar, it is **not committed**, and it must never affect `src/` or deployment.

To run the tests on a machine, download the pinned phar into `tests/` (this is the documented pinned version for this project):

```bash
wget -O tests/phpunit.phar https://phar.phpunit.de/phpunit-12.phar
```

Then run:

```bash
php tests/phpunit.phar tests/
```

- `tests/phpunit.phar` is gitignored. Never commit it.
- When bumping the pinned version, update the command above in this file. Everyone must run the same PHPUnit version.

## Things you must never do

- Add a dependency, package, framework, or Composer file.
- Introduce a build, generate, or deploy-time transformation step.
- Create subfolders in `src/` or split `src/` into layers.
- Put business logic in an endpoint.
- Add an autoloader, bootstrap, DI container, or routing abstraction.
- Write tests for endpoints, or commit the PHPUnit phar.