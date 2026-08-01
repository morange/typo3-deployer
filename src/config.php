<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// Generic deployment configuration (SSH, shared dirs/files, writable, rsync)
// ============================================================================
// This file holds only provider-agnostic defaults. Anything host-specific
// (hostnames, deploy_path, ssh user) lives in each project's .hosts.yml.
// Anything hoster-specific (php binary, ssh multiplexing) lives in a provider
// profile under src/provider/. Projects tweak the rest by overriding the
// settings below in their own deploy.php AFTER requiring the recipe.
// ============================================================================

// --- SSH defaults (a provider profile may override) -------------------------
set('ssh_type', 'native');
set('ssh_multiplexing', false);

// --- PHP / TYPO3 CLI --------------------------------------------------------
// {{php}} is the PHP binary; a provider profile overrides it (e.g. HostEurope
// needs /usr/bin/php8.3). {{bin/php}} tracks {{php}} so tasks reading either
// setting stay consistent.
set('php', 'php');
set('bin/php', '{{php}}');

// TYPO3_CONTEXT for the CLI. Overridden per host in .hosts.yml so the CLI reads
// the same .env the web does (host-based rule in public/.htaccess). Fallback =
// Production.
set('typo3_context', 'Production');
set('bin/typo3', 'TYPO3_CONTEXT={{typo3_context}} {{bin/php}} {{release_path}}/vendor/bin/typo3');

// --- Release / docroot ------------------------------------------------------
set('keep_releases', 5);
set('typo3_webroot', 'public');

// --- Writable settings ------------------------------------------------------
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775'); // base for Deployer; set_permissions refines it
set('writable_chmod_recursive', true);
set('writable_use_sudo', false);

// --- Stage Basic-Auth marker (see tasks/htaccess.php) -----------------------
// The idempotency check greps the project's public/.htaccess.stage-auth snippet
// for this marker. The snippet must contain the same string. Override per
// project if you use a different marker.
set('stage_auth_marker', '__STAGE_AUTH__');

// ============================================================================
// HYBRID DIRECTORY STRATEGY
// ============================================================================
// 1. Parent directories (typo3temp, var/lock) = real dirs (TYPO3-compliant)
// 2. Persistent subdirectories (logs, sessions) = symlinks into shared/
// 3. Cache directories (assets, cache) = per release (rebuilt each deploy)
// ============================================================================

// Top-level dirs symlinked completely into shared/.
set('shared_dirs', [
    '{{typo3_webroot}}/fileadmin',
    // NOT: typo3temp, var/lock (must be real dirs)
    // NOT: var/log, var/session (linked as subdirs, see shared_subdirs)
    // NOT: var/charset, var/labels (must be real dirs - TYPO3 12.x+)
]);

// Persistent subdirectories symlinked INSIDE real parent dirs.
set('shared_subdirs', [
    'var/log',
    'var/session',
    '{{typo3_webroot}}/typo3temp/var/transient',
]);

// Shared files (persistent, symlinked across releases).
// settings.php/additional.php are shared but hold NO secrets (they read env at
// runtime); the deploy:sync_shared_config task keeps them tracking Git.
// Override in a project's deploy.php to add e.g. a second env file.
set('shared_files', [
    'config/system/settings.php',
    'config/system/additional.php',
    '.env',
]);

// Real secret files: excluded from rsync (they never exist in the Git checkout
// anyway). settings.php/additional.php are intentionally NOT listed here.
set('rsync_secret_files', [
    '.env',
]);

// Writable dirs - everything that must be writable by the web server.
set('writable_dirs', [
    'var',
    'var/charset',      // real dir (TYPO3 12.x+)
    'var/labels',       // real dir (TYPO3 12.x+)
    'var/lock',
    'var/log',
    'var/session',
    '{{typo3_webroot}}/typo3temp',
    '{{typo3_webroot}}/typo3temp/assets',
    '{{typo3_webroot}}/typo3temp/var',
    '{{typo3_webroot}}/fileadmin',
    '{{typo3_webroot}}/uploads',
]);

// ============================================================================
// RSYNC
// ============================================================================

// Provider-agnostic base excludes (dev tooling, sources, build artefacts).
set('rsync_exclude_base', [
    '.DS_Store',
    'Thumbs.db',
    '.ddev',
    '.editorconfig',
    '.fleet',
    '.git*',
    '.idea',
    '.php-cs-fixer.dist.php',
    '.vscode',
    'auth.json',
    'deploy.php',
    'deploy.txt',
    'deploy/',
    '.hosts.yml',
    '.hosts.yaml',
    'gitlab-ci.yml',
    'phpstan.neon',
    'phpstan.neon.dist',
    'phpunit.xml',
    'README*',
    'rector.php',
    'fractor.php',
    'typoscript-lint.yml',
    '/.deployment',
    '/var/log',
    '/var/cache',
    '/**/Tests/*',
    'node_modules',
    '/Build',        // Webpack build-time sources - not needed at runtime
    '/bin-dev',      // local dev scripts
    '/data',         // local DB dumps/backups
    'tests',
    '.pa11yci',
    'qodana.yaml',
    'change_log.md',
    'ToDos*.md',
    '.env',
]);

// Project-specific additional excludes. Projects set this in their deploy.php.
if (!has('rsync_exclude_extra')) {
    set('rsync_exclude_extra', []);
}

// Extra include patterns (e.g. a stage Basic-Auth snippet). Projects may extend.
if (!has('rsync_include_extra')) {
    set('rsync_include_extra', ['{{typo3_webroot}}/.htaccess.stage-auth']);
}

// Built lazily so project overrides of the *_extra / shared_dirs / secret_files
// settings are picked up even when set AFTER this file is required.
set('rsync', function () {
    return [
        'exclude' => array_merge(
            get('shared_dirs'),
            get('rsync_secret_files'),
            get('rsync_exclude_base'),
            get('rsync_exclude_extra'),
            ['/backups', '/bin'] // never rsync the server-side backup dirs
        ),
        'exclude-file' => false,
        'include' => get('rsync_include_extra'),
        'include-file' => false,
        'filter' => [],
        'filter-file' => false,
        'filter-perdir' => false,
        'flags' => 'az',
        'options' => ['delete', 'delete-excluded'],
        'timeout' => 600,
    ];
});
set('rsync_src', './');

// Use rsync instead of git for code transfer.
task('deploy:update_code', function () {
    invoke('rsync');
});