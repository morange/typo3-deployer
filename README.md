# dmfh/typo3-deployer

Shared [Deployer 7](https://deployer.org) recipe for deploying **TYPO3 v12+** to
shared hosting. One core, many hosters — the provider-specific quirks live in
small profiles, everything project- and secret-specific stays in the consuming
project.

Supported / planned provider profiles: **Alfahosting**, IONOS, 1blu, all-inkl,
HostEurope, Strato, Mittwald.

## Why

The deploy logic (hybrid TYPO3 directory strategy, rsync transfer, permissions,
TYPO3 CLI steps, disk/file-count guards, server-side backup wiring) was written
once and copy-pasted across ~8 projects, then drifted. This package is the
single source of truth. Projects pin a version and update deliberately.

## Design

- **Core** (`src/`) — provider-agnostic config, tasks and deploy flow. No
  hostnames, paths or secrets. Ever. This repo is public.
- **Provider profiles** (`src/provider/*.php`) — pure `set()` calls for one
  hoster (SSH multiplexing, PHP binary, …). Declarative, no logic.
- **Per project** (not in this repo) — `.hosts.yml` (hosts, deploy paths, ssh
  users, TYPO3_CONTEXT) and secret files (`.env`, `bin/.env`). Both gitignored.

## Install

```bash
composer require --dev dmfh/typo3-deployer
```

## Usage

Project `deploy.php`:

```php
<?php
namespace Deployer;

require 'vendor/dmfh/typo3-deployer/src/recipe/typo3.php';
require 'vendor/dmfh/typo3-deployer/src/provider/alfahosting.php';
import('.hosts.yml');

// Optional project-specific overrides (picked up lazily):
// set('rsync_exclude_extra', ['/Projektdateien', 'SHARED-HOSTING.md']);
// set('shared_files', array_merge(get('shared_files'), ['.env.staging']));
// set('stage_auth_marker', '__STAGE_AUTH__');
```

Project `.hosts.yml` (see `.hosts.yml.example`) — this file is **gitignored**
and never enters this package.

Deploy:

```bash
vendor/bin/dep deploy stage
vendor/bin/dep deploy production
vendor/bin/dep rollback production
```

## What the deploy does

1. Disk-space + file-count preflight (shared-hosting guards).
2. rsync the CI-built code (incl. `vendor/` and built assets) to a new release.
3. Build the TYPO3 **hybrid directory structure**: real parent dirs, persistent
   subdirs symlinked into `shared/`, per-release cache dirs.
4. TYPO3 CLI: DB schema update, language update, cache warmup, reference index,
   scheduler run, backend lock/unlock around the switch.
5. Stage-only: prepend an HTTP Basic-Auth block to `public/.htaccess`.
6. Create/refresh the customer-account-level `backups/` and `bin/` dirs and
   upload the server-side backup scripts from `server-bin/`.
7. Rotate logs, print a deployment summary.

## Server-side backup scripts (`server-bin/`)

`server-backup.sh` (DB), `server-backup-files.sh` (files) and `nas-pull.sh` are
deployed to each server's `bin/`. They are parametrised entirely via CLI flags
(`-o`/`-n`/`-f`) and a per-server `bin/.env` — **no credentials in this repo**.

They are the counterpart to the local `~/typo3-backup` orchestrator, which calls
them over SSH. Keep script names and flags stable so that contract holds.

## Adding a provider

Create `src/provider/<hoster>.php` with the hoster's quirks only:

```php
<?php
namespace Deployer;
set('ssh_multiplexing', false);
set('php', '/usr/bin/php8.3');   // e.g. HostEurope
```

Keep it declarative. If a hoster needs conditional behaviour, prefer a setting
the core already reads over branching logic in the profile.