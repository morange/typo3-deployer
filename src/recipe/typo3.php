<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// dmfh/typo3-deployer — TYPO3 shared-hosting recipe (Deployer 7)
// ============================================================================
// Single entry point. A project's deploy.php only needs:
//
//     <?php
//     namespace Deployer;
//     require 'vendor/dmfh/typo3-deployer/src/recipe/typo3.php';
//     require 'vendor/dmfh/typo3-deployer/src/provider/<hoster>.php';
//     import('.hosts.yml');
//     // optional: set('rsync_exclude_extra', [...]);
//
// The frontend build + composer install are expected to run in CI (GitHub
// Action); the server receives the finished vendor/ (incl. built assets) via
// rsync. The composer tasks remain available for projects that build on-server.
// ============================================================================

// Base Deployer recipes.
require 'recipe/common.php';
require 'contrib/cachetool.php';
require 'contrib/rsync.php';

// Generic configuration (SSH, shared dirs/files, writable, rsync).
require __DIR__ . '/../config.php';

// Task definitions.
require __DIR__ . '/../tasks/directory-structure.php';
require __DIR__ . '/../tasks/config.php';
require __DIR__ . '/../tasks/htaccess.php';
require __DIR__ . '/../tasks/backups.php';
require __DIR__ . '/../tasks/composer.php';
require __DIR__ . '/../tasks/typo3.php';
require __DIR__ . '/../tasks/cron.php';
require __DIR__ . '/../tasks/permissions.php';
require __DIR__ . '/../tasks/assets.php';
require __DIR__ . '/../tasks/diskspace.php';
require __DIR__ . '/../tasks/summary.php';

// Deployment flow: hooks, deploy + rollback tasks (must load last).
require __DIR__ . '/../flow.php';