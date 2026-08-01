<?php

declare(strict_types=1);

namespace Deployer;

// Deployment flow: hooks, deploy + rollback tasks

// ============================================================================
// DEPLOYMENT HOOKS
// ============================================================================

// Pre-deployment tasks (mit Fehlerbehandlung für neue Installationen)
before('deploy:symlink', function () {
	invoke('deploy:set_permissions');

	// Nur TYPO3-Tasks ausführen wenn TYPO3 installiert ist
	if (test('[ -f {{release_path}}/vendor/bin/typo3 ]')) {
		try {
			invoke('typo3:fix_folder_structure');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:fix_folder_structure failed (might be first deployment): " . $e->getMessage() . "</comment>");
		}

		try {
			invoke('typo3:backend_lock');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:backend_lock failed: " . $e->getMessage() . "</comment>");
		}

		try {
			invoke('typo3:update_database');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:update_database failed: " . $e->getMessage() . "</comment>");
		}

		try {
			invoke('typo3:language_update');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:language_update failed: " . $e->getMessage() . "</comment>");
		}

		try {
			invoke('typo3:cache_warmup');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:cache_warmup failed: " . $e->getMessage() . "</comment>");
		}

		try {
			invoke('typo3:health_check');
		} catch (\Exception $e) {
			writeln("<comment>⚠ typo3:health_check failed: " . $e->getMessage() . "</comment>");
		}
	} else {
		writeln("<comment>⚠ TYPO3 binary not found, skipping TYPO3-specific tasks (first deployment?)</comment>");
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
	// composer:install entfällt – vendor/ wird fertig gebaut (inkl. Frontend-Assets)
	// von der GitHub Action mit-rsynct (Option A). Kein Composer/Node auf dem Server.
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
