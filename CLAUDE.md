# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`dmfh/typo3-deployer` is a **Composer library**, not an application: a shared
[Deployer 7/8](https://deployer.org) recipe for deploying **TYPO3 v13+** to
shared hosting. It is the single source of truth extracted from ~8 projects that
had copy-pasted and drifted. Consuming projects `composer require --dev` it, pin
a version, and update deliberately.

**This repo is public — no hostnames, paths, credentials, or secrets, ever.**
Host- and secret-specific data lives only in each consuming project's gitignored
`.hosts.yml` / `.env`, never here.

## No build, test, or lint in this repo

There is no test suite, build step, or linter. The recipe is plain PHP loaded by
Deployer at deploy time. You exercise it from a *consuming* project:

```bash
vendor/bin/dep deploy stage         # full deploy to the 'stage' host
vendor/bin/dep deploy production
vendor/bin/dep rollback production
vendor/bin/dep cron:show            # print the correct scheduler crontab line
vendor/bin/dep cron:check           # audit existing crontab entries
```

Verify a change to a task in isolation by running that single task against a
host, e.g. `vendor/bin/dep typo3:preflight stage`.

## Architecture — three layers

1. **Core** (`src/`) — provider-agnostic config, tasks, and deploy flow. Never
   contains hostnames/paths/secrets.
2. **Provider profiles** (`src/provider/*.php`) — one file per hoster
   (alfahosting, ionos, 1blu, allinkl, hosteurope, strato). Pure declarative
   `set()` calls capturing that hoster's quirks (PHP binary, `php_flags`, SSH
   multiplexing) — **no logic, no branching**. If a hoster needs conditional
   behaviour, add a setting the core reads rather than an `if` in the profile.
3. **Per project** (NOT in this repo) — `.hosts.yml` (hosts, `deploy_path`, ssh
   user, `typo3_context`) and secret files. See `.hosts.yml.example` and
   `deploy.php.example` for the shapes.

Load order in a project's `deploy.php`: `require` the recipe, then `require` the
provider profile, then `import('.hosts.yml')`. Projects override defaults by
`set()` **after** requiring the recipe.

### Entry point and load order

`src/recipe/typo3.php` is the single entry point. It requires Deployer's base
recipes, then `src/config.php` (all defaults), then every `src/tasks/*.php`, then
`src/flow.php` **last** (flow wires the hooks and defines `deploy`/`rollback`, so
it must see all task definitions and settings first).

### The deploy flow (`src/flow.php`)

`deploy` is a static task list (see the array in `flow.php`). Pre-symlink work is
hung on `before('deploy:symlink', …)`; post-switch work on
`after('deploy:symlink', …)`. **Step criticality is deliberate:**

- **CRITICAL** (uncaught → aborts before the symlink switch, live release
  untouched): `typo3:preflight`, and `typo3:update_database` by default.
- **BEST-EFFORT** (wrapped in try/catch → warn and continue): folder structure,
  backend lock, language update, cache warmup, health check, reference index,
  scheduler, log rotation.

`set('typo3_abort_on_schema_error', false)` downgrades the schema update to
best-effort (e.g. first deploy against an empty DB). All TYPO3 steps are guarded
by a `[ -f {{release_path}}/vendor/bin/typo3 ]` test so a first deployment (no
vendor yet) degrades gracefully.

### Hybrid directory strategy

The central design (see `src/config.php` and
`src/tasks/directory-structure.php`). Three kinds of directory:

- **Real parent dirs per release** — `public/typo3temp`, `var/lock`,
  `var/charset`, `var/labels`. TYPO3's Install Tool requires these to be real
  directories, *not* symlinks (`var/charset`/`var/labels` since TYPO3 12).
- **Persistent subdirs symlinked into `shared/`** (`shared_subdirs`) — `var/log`,
  `var/session`, `typo3temp/var/transient`. Survive deploys (logins, logs).
- **Per-release cache dirs** — `typo3temp/assets/*`, `typo3temp/var/cache`.
  Rebuilt clean each deploy.

`shared_dirs` (fully symlinked top-level, e.g. `fileadmin`) is Deployer's normal
mechanism; the subdir linking is custom and runs as Phase 4
(`typo3:link_persistent_subdirs`) *after* `deploy:shared`.

### PHP invocation — two axes

Every PHP call (typo3 CLI, cron, composer) goes through `{{bin/php}}`, composed
in `src/config.php` as `{{php}} {{php_flags}}`:

- `{{php}}` — the binary, varies per *host* (`.hosts.yml`) or hoster profile.
- `{{php_flags}}` — hoster-wide flags. Strato runs php as cgi-fcgi and needs
  `-d register_argc_argv=1` so `$argv` is populated.

`vendor/bin/typo3` is always routed through `{{bin/php}}`, never trusting its
shebang (1blu's shebang resolves to the wrong PHP).

### Build strategy — `composer_install_on_server`

One flag decides where `vendor/` comes from (full guide:
`docs/BUILD-STRATEGY.md`). Default `false`: `vendor/` (incl. built assets) is
prepared in CI/locally and rsynced as-is — do **not** exclude `vendor/`. `true`:
`composer install` runs on the server (`deploy:composer`), and you *may* add
`/vendor` to `rsync_exclude_extra`. The `deploy:composer` task is always in the
list but is a clean no-op when the flag is `false`.

### Server-side backup scripts (`server-bin/`)

`server-backup.sh`, `server-backup-files.sh`, `nas-pull.sh` are uploaded to each
customer account's `bin/` (one level **above** `deploy_path`, surviving release
rotation) by `deploy:setup_backups`. They are the counterpart to the local
`~/typo3-backup` orchestrator that calls them over SSH. **Keep script names and
their CLI flags (`-o`/`-n`/`-f`) stable — that contract is external to this repo.**
Credentials live in a per-server `bin/.env` that the deploy never manages.

## Conventions when editing

- **rsync config is built lazily** (`set('rsync', function () {…})`) so a
  project's `set()` overrides of `*_extra` / `shared_dirs` / `rsync_secret_files`
  are picked up even when set *after* the recipe is required. Preserve that
  laziness for anything a project may override.
- Comments and log output are a mix of German and English — match the
  surrounding file rather than normalising.
- Log output leans heavily on Deployer's `writeln` with `<info>`/`<comment>`/
  `<fg=…>` tags and box-drawing banners; keep that house style in new tasks.

## TYPO3 core-drift maintenance

TYPO3 renames/removes CLI commands and changes directory conventions across
majors. Read `COMPATIBILITY.md` before touching anything version-coupled. The
key guards:

- `typo3:preflight` (critical) asserts `typo3_min_major` and that every command
  in `typo3_required_commands` exists — a removed/renamed command aborts the
  deploy *naming the command* instead of silently skipping.
- On a TYPO3 major upgrade, verify commands against `vendor/bin/typo3 list` and
  update `typo3_required_commands` (`src/config.php`) plus the tasks
  (`src/tasks/typo3.php`, `cron.php`) together. Bump the recipe major in lockstep
  with the TYPO3 major.