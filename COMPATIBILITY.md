# TYPO3 compatibility & maintenance

This recipe targets **TYPO3 v13 and up** (the running site is always v13+; older
versions are only ever the *source* of an upgrade). TYPO3 evolves its CLI,
directory layout and conventions across major versions. This document lists the
version-coupled touchpoints and what to check when TYPO3 releases a new major.

## Versioning contract (the #1 safeguard)

Pin the recipe per project and bump it **deliberately, together with** a TYPO3
major upgrade — never let a core change reach all projects silently.

| Recipe major | Supported TYPO3      |
|--------------|----------------------|
| `^1.0`       | v13, v14             |
| `^2.0`       | (cut when v15 lands) |

In each project's `composer.json`:

```json
"require-dev": { "dmfh/typo3-deployer": "^1.0" }
```

Because the deploy logic lives here (not in 8 copies), a command rename in a new
TYPO3 major is a **one-line change here + `composer update` per project**.

## Built-in drift protection

- **`typo3:preflight`** (critical, runs before the symlink switch) asserts the
  TYPO3 major (`typo3_min_major`) and that every command in
  `typo3_required_commands` exists in this TYPO3. A removed/renamed command
  **aborts the deploy with the command named**, instead of being silently
  skipped mid-run.
- **Critical vs best-effort steps** (`src/flow.php`): the schema update is
  critical (aborts on failure); folder-structure, language, cache-warmup and
  health-check are best-effort (warn and continue). Toggle the schema behaviour
  with `set('typo3_abort_on_schema_error', false)`.

## Checklist — on every TYPO3 major upgrade

### 1. CLI commands (highest coupling — `src/tasks/typo3.php`, `cron.php`)

Run against the upgraded site and confirm each command still exists:

```bash
vendor/bin/typo3 list --no-ansi | sort
```

Commands the flow depends on: `install:fixfolderstructure`, `backend:lock`,
`backend:unlock`, `database:updateschema`, `language:update`, `cache:warmup`,
`cache:flush`, `referenceindex:update`, `scheduler:run`. Update
`typo3_required_commands` and the tasks together if any changed.

> Known drift already handled: the former `upgrade:prepare` (a typo3-console
> command) no longer exists in core; `upgrade:run` covers it.

### 2. Directory / filesystem conventions (`src/config.php`, `src/tasks/directory-structure.php`)

- `writable_dirs`, `shared_subdirs` and the hybrid-structure task encode which
  `var/*` and `public/typo3temp/*` dirs must be **real** vs **symlinked**.
  `var/charset` and `var/labels` are real-dir requirements since v12. Check the
  release notes for any new/renamed required writable directory.
- Confirm `config/system/settings.php` and `config/system/additional.php` are
  still the canonical config paths (`shared_files`).

### 3. Schema & upgrade semantics

- `database:updateschema '*.add,*.change'` — verify the safe-subset token syntax
  is unchanged.
- `upgrade:run all --confirm all` — verify the argument/flag surface.

### 4. PHP version floor

- Each TYPO3 major raises the minimum PHP. Bump each host's PHP binary
  (`.hosts.yml` `php:` / the provider profile's `php`/`php_flags`). No task logic
  changes — the invocation is isolated to those settings.

## Open item — helhum/typo3-console

Some projects resolve `vendor/bin/typo3` to **helhum/typo3-console** rather than
the plain core CLI. Console has its own release cadence and historically lags new
TYPO3 majors, so it is an **extra compatibility gate** on every upgrade. If a
project only uses commands that core now provides natively, consider whether the
dependency is still needed — dropping it removes one lag risk. Evaluate per
project; do not remove blindly.