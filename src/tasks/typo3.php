<?php

declare(strict_types=1);

namespace Deployer;

// TYPO3 CLI tasks (cache, db, upgrade, backend, maintenance)

// ============================================================================
// TYPO3 TASKS
// ============================================================================

desc('Flush all caches');
task('typo3:cache_flush', function () {
	run('{{bin/typo3}} cache:flush');
});

desc('Warm up system caches');
task('typo3:cache_warmup', function () {
	run('{{bin/typo3}} cache:warmup --group system');
});

desc('Set up all installed extensions');
task('typo3:extension_setup', function () {
	run('{{bin/typo3}} extension:setup --verbose');
});

desc('Fix folder structure');
task('typo3:fix_folder_structure', function () {
	run('{{bin/typo3}} install:fixfolderstructure');
});

desc('Update language files');
task('typo3:language_update', function () {
	run('{{bin/typo3}} language:update');
});

desc('Update database schema');
task('typo3:update_database', function () {
	run("{{bin/typo3}} database:updateschema '*.add,*.change'");
});

desc('Update reference index');
task('typo3:update_reference_index', function () {
	run("{{bin/typo3}} referenceindex:update");
});

desc('Execute upgrade wizards');
task('typo3:upgrade_all', function () {
	if (test('[ -f {{release_path}}/vendor/bin/typo3 ]')) {
		run('{{bin/typo3}} upgrade:prepare');
		run('{{bin/typo3}} upgrade:run all --confirm all');
	}
});

// Backend management
desc('Lock backend');
task('typo3:backend_lock', function () {
	run("{{bin/typo3}} backend:lock");
});

desc('Unlock backend');
task('typo3:backend_unlock', function () {
	run("{{bin/typo3}} backend:unlock");
});

// Maintenance tasks
desc('Clean up deleted records');
task('typo3:cleanup_deletedrecords', function () {
	run("{{bin/typo3}} cleanup:deletedrecords");
});

desc('Check redirect integrity');
task('typo3:redirect_checkintegrity', function () {
	run("{{bin/typo3}} redirects:checkintegrity");
});

desc('Clean up redirects');
task('typo3:redirect_cleanup', function () {
	run("{{bin/typo3}} redirects:cleanup");
});

desc('Run TYPO3 Scheduler once');
task('typo3:scheduler_run', function () {
	if (test('[ -f {{release_path}}/vendor/bin/typo3 ]')) {
		try {
			run('{{bin/typo3}} scheduler:run');
			writeln('<info>✓ Scheduler executed successfully</info>');
		} catch (\Exception $e) {
			writeln("<comment>⚠ Scheduler run failed: " . $e->getMessage() . "</comment>");
		}
	}
});
