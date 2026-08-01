<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// Provider profile: Alfahosting (Business tariff)
// ============================================================================
// Captures only hoster-level truths. Per-host data (hostname, deploy_path, ssh
// user, typo3_context) stays in the project's .hosts.yml. Load this in the
// project's deploy.php BEFORE importing hosts:
//
//     require 'vendor/dmfh/typo3-deployer/src/recipe/typo3.php';
//     require 'vendor/dmfh/typo3-deployer/src/provider/alfahosting.php';
//     import('.hosts.yml');
// ============================================================================

// Alfahosting supports SSH ControlMaster multiplexing (faster multi-task runs).
set('ssh_multiplexing', true);

// Business tariff ships PHP 8.4 as the default `php` binary.
set('php', 'php');

// Alfahosting imposes no hard per-account inode limit like IONOS, so the
// file-count guard just reports. Projects may still set MAX_FILES in shared/.env.