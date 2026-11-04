<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// Provider profile: Mittwald (mStudio hosting)
// ============================================================================
// Captures only hoster-level truths. Per-host data (hostname, deploy_path, ssh
// user, typo3_context) stays in the project's .hosts.yml. Load this in the
// project's deploy.php BEFORE importing hosts:
//
//     require 'vendor/dmfh/typo3-deployer/src/recipe/typo3.php';
//     require 'vendor/dmfh/typo3-deployer/src/provider/mittwald.php';
//     import('.hosts.yml');
// ============================================================================

// Mittwald's own docs flag ControlMaster multiplexing as a known failure mode
// (ControlPath socket name can exceed the system limit, causing stale/mismatched
// multiplexed sessions mid-deploy) and recommend disabling it.
// See: https://developer.mittwald.de/docs/v2/technologies/deployment/deployer/
set('ssh_multiplexing', false);

// Mittwald ships the project's configured PHP version as `php` in PATH.
// Override per host in .hosts.yml via `php:` if a project needs a different version.
set('php', 'php');

// Mittwald imposes no hard per-account inode limit like IONOS, so the
// file-count guard just reports. Projects may still set MAX_FILES in shared/.env.