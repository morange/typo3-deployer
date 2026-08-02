<?php

declare(strict_types=1);

namespace Deployer;

// Deployment flow: hooks, deploy + rollback tasks

// ============================================================================
// DEPLOYMENT HOOKS
// ============================================================================

// Pre-deployment tasks.
//
// Step criticality (see COMPATIBILITY.md):
//   • CRITICAL  → not caught; failure aborts the deploy (before the symlink
//                 switch, so the live release is untouched).
//   • BEST-EFFORT → wrapped; failure logs a warning and the deploy continues.
//
// typo3:preflight is critical: it asserts the TYPO3 version and that the CLI
// commands used below still exist, turning silent core-drift into a clear stop.
// typo3:update_database is critical by default (stale schema is dangerous);
// set('typo3_abort_on_schema_error', false) downgrades it to best-effort, e.g.
// for a first deployment against an empty database.
before('deploy:symlink', function () {
	invoke('deploy:set_permissions');

	// Only run TYPO3 tasks when TYPO3 is present.
	if (!test('[ -f {{release_path}}/vendor/bin/typo3 ]')) {
		writeln("<comment>⚠ TYPO3 binary not found, skipping TYPO3-specific tasks (first deployment?)</comment>");
		return;
	}

	// CRITICAL: preflight — abort on version/command drift.
	invoke('typo3:preflight');

	// BEST-EFFORT: folder structure (may legitimately fail on first deploy).
	try {
		invoke('typo3:fix_folder_structure');
	} catch (\Exception $e) {
		writeln("<comment>⚠ typo3:fix_folder_structure failed (might be first deployment): " . $e->getMessage() . "</comment>");
	}

	// BEST-EFFORT: backend lock.
	try {
		invoke('typo3:backend_lock');
	} catch (\Exception $e) {
		writeln("<comment>⚠ typo3:backend_lock failed: " . $e->getMessage() . "</comment>");
	}

	// CRITICAL (configurable): database schema update.
	if (get('typo3_abort_on_schema_error')) {
		invoke('typo3:update_database');
	} else {
		try {
			invoke('typo3:update_database');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:update_database failed (best-effort mode): " . $e->getMessage() . "</comment>");
		}
	}

	// BEST-EFFORT: language files.
	try {
		invoke('typo3:language_update');
	} catch (\Exception $e) {
		writeln("<comment>⚠ typo3:language_update failed: " . $e->getMessage() . "</comment>");
	}

	// BEST-EFFORT: cache warmup (rebuilds at runtime anyway).
	try {
		invoke('typo3:cache_warmup');
	} catch (\Exception $e) {
		writeln("<comment>⚠ typo3:cache_warmup failed: " . $e->getMessage() . "</comment>");
	}

	// BEST-EFFORT: health check.
	try {
		invoke('typo3:health_check');
	} catch (\Exception $e) {
		writeln("<comment>⚠ typo3:health_check failed: " . $e->getMessage() . "</comment>");
	}
});

// Post-deployment tasks
after('deploy:symlink', function () {
	// Log rotation (cleanup old logs)
	try {
		invoke('logs:rotate');
	} catch (\Exception $e) {
		writeln("<comment>⚠ logs:rotate failed: " . $e->getMessage() . "</comment>");
	}

	// Backend unlock nur wenn TYPO3 installiert ist
	if (test('[ -f {{release_path}}/vendor/bin/typo3 ]')) {
		try {
			invoke('typo3:backend_unlock');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:backend_unlock failed: " . $e->getMessage() . "</comment>");
		}

		try {
			invoke('typo3:update_reference_index');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:update_reference_index failed: " . $e->getMessage() . "</comment>");
		}

		try {
			invoke('typo3:scheduler_run');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:scheduler_run failed: " . $e->getMessage() . "</comment>");
		}

		try {
			invoke('typo3:cache_flush');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:cache_flush failed: " . $e->getMessage() . "</comment>");
		}
	}

	// Deployment Summary anzeigen
	try {
		invoke('deploy:show_summary');
	} catch (\Exception $e) {
		writeln("<comment>⚠ deploy:show_summary failed: " . $e->getMessage() . "</comment>");
	}

	writeln('');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('<info>  ✓ Deployment completed successfully!</info>');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('');
	writeln('<comment>💡 Useful commands:</comment>');
	writeln('');
	writeln('  <fg=green>dep cron:show</>     - Show correct cron job command');
	writeln('  <fg=green>dep cron:check</>    - Check current crontab entries');
	writeln('  <fg=green>dep cron:setup</>    - Interactive setup helper');
	writeln('');
	writeln('<comment>📂 Current release path:</comment>');
	writeln('  ' . get('deploy_path') . '/current -> releases/' . basename(get('release_path')));
	writeln('');
});

// Cleanup on failure
after('deploy:failed', function () {
	if (test('[ -f {{release_path}}/vendor/bin/typo3 ]')) {
		try {
			invoke('typo3:backend_unlock');
		} catch (\Exception $e) {
			// Ignore errors during cleanup
		}
	}
	invoke('deploy:unlock');
});

// ============================================================================
// CUSTOM PREPARE TASK
// ============================================================================

Deployer::get()->tasks->remove('deploy:prepare');

task('deploy:prepare', function () {
	run('if [ ! -d {{deploy_path}}/.dep ]; then mkdir -p {{deploy_path}}/.dep; fi');
	run('if [ ! -d {{deploy_path}}/releases ]; then mkdir -p {{deploy_path}}/releases; fi');
	run('if [ ! -d {{deploy_path}}/shared ]; then mkdir -p {{deploy_path}}/shared; fi');
	writeln('<info>✓ Deployment structure prepared</info>');
});

// ============================================================================
// MAIN DEPLOYMENT TASK - HYBRID STRATEGY
// ============================================================================

desc('Deploy TYPO3 application');
task('deploy', [
	'deploy:prepare',
	'deploy:release',
	'deploy:check_space',            // ← Check disk space BEFORE rsync
	'deploy:check_file_count',       // ← Check file count limit BEFORE rsync
	'deploy:update_code',
	'deploy:composer',                  // no-op unless composer_install_on_server=true (see config.php)
	'deploy:sync_shared_config',        // settings.php/additional.php → shared/ (vor dem Symlink-Task)
	'typo3:create_hybrid_structure',   // Phase 1-3: Parent dirs + Cache
	'deploy:shared',                    // Shared dirs (charset, labels, fileadmin)
	'typo3:link_persistent_subdirs',   // Phase 4: Link logs, sessions, transient
	'deploy:writable',
	'typo3:ensure_assets_dirs',        // ← Ensure assets subdirs exist AFTER writable
	'deploy:symlink',
	'deploy:cleanup',
]);

// ============================================================================
// ROLLBACK TASK
// ============================================================================

desc('Rollback to previous release');
task('rollback', function () {
	invoke('typo3:backend_lock');
	invoke('deploy:rollback');
	invoke('typo3:cache_flush');
	invoke('typo3:backend_unlock');
});
