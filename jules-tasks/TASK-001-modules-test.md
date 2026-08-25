<!-- branch: main -->
# TASK-001: Fix the two pre-existing ModulesTest failures (6th module "pado")

## Context
This repository contains `igbz-suite`, a WordPress/WooCommerce multi-tenant
commerce plugin. A sixth module `pado` was recently added to
`igbz-suite/src/Support/Modules.php` (constant `PADO = 'pado'`); it is included
in `Modules::all()` and in `Modules::defaults()` (defaults are now
`['multitenant', 'pado']`). The unit test
`igbz-suite/tests/ModulesTest.php` was never updated for this change, so the
suite currently reports 1248 passed, 2 failed:

1. "the suite ships five modules (expected 5, got 6)"
2. "only multi-tenant is on by default (expected ['multitenant'], got ['multitenant','pado'])"

These two failures are pre-existing and unrelated to any other work.

## Task
Update `igbz-suite/tests/ModulesTest.php` so both assertions reflect the
current, intended behaviour of `Modules.php`:
- The suite ships SIX modules: multitenant, instagram, hub, fx, rest_api, pado.
- Default enabled modules are exactly ['multitenant', 'pado'].
Also update any assertion message strings that still say "five modules" or
"only multi-tenant" so messages stay truthful.

## Constraints
- Modify ONLY `igbz-suite/tests/ModulesTest.php`. Do NOT change
  `Modules.php` or any production code — the production behaviour is correct;
  the test expectations are stale.
- Do not touch unrelated tests or files.
- Run the whole test suite with `bash _devenv/test.sh` (PHP runs via php-wasm;
  a plain `php` binary is NOT on PATH). If the environment cannot run it,
  at minimum ensure the file is syntactically valid PHP.
- Keep the existing test style of this repo: plain assertions with message
  strings, no PHPUnit annotations changes, no new dependencies.

## Acceptance criteria
- `bash _devenv/test.sh` reports 1250 passed, 0 failed.
- Diff touches exactly one file: `igbz-suite/tests/ModulesTest.php`.
- Assertion messages match the new expectations (six modules; multitenant+pado
  by default).
