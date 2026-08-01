<?php

declare(strict_types=1);

namespace Deployer;

// TYPO3 CLI tasks (cache, db, upgrade, backend, maintenance)

// ============================================================================
// PREFLIGHT — fail early and loudly on TYPO3 core drift
// ============================================================================
// Runs in the new release BEFORE the symlink switch. Two guards:
//   1. TYPO3 major >= {{typo3_min_major}} (this recipe targets v13+).
//   2. Every command in {{typo3_required_commands}} actually exists in this
//      TYPO3's CLI. Command names drift across majors (e.g. the former
//      upgrade:prepare is gone); this turns "silent skip mid-deploy" into a
//      clear abort with the offending command named. See COMPATIBILITY.md.
// ============================================================================

desc('Preflight: assert TYPO3 version and required CLI commands exist');
task('typo3:preflight', function () {
	if (!test('[ -f {{release_path}}/vendor/bin/typo3 ]')) {
		writeln('<comment>⚠ TYPO3 binary not found — skipping preflight (first deployment?)</comment>');
		return;
	}

	writeln('<comment>🛫 TYPO3 preflight...</comment>');

	// 1. Minimum major version.
	$min = (int) get('typo3_min_major');
	$versionOutput = run('{{bin/typo3}} --version --no-ansi 2>/dev/null || true');
	if (preg_match('/\b(\d+)\.\d+\.\d+\b/', $versionOutput, $m)) {
		$major = (int) $m[1];
		if ($major < $min) {
			throw new \Exception(
				"TYPO3 major $major is below this recipe's minimum ($min). "
				. 'Pin an older recipe version or upgrade TYPO3.'
			);
		}
		writeln("<info>  ✓ TYPO3 major $major (>= $min)</info>");
	} else {
		writeln('<comment>  ⚠ Could not parse TYPO3 version from: ' . trim($versionOutput) . '</comment>');
	}

	// 2. Required CLI commands must exist in this TYPO3.
	$required = (array) get('typo3_required_commands');
	if (empty($required)) {
		return;
	}

	$list = run('{{bin/typo3}} list --raw --no-ansi 2>/dev/null || {{bin/typo3}} list --no-ansi 2>/dev/null || true');
	$missing = [];
	foreach ($required as $cmd) {
		// Command appears at a token boundary (avoids cache:flush matching
		// cache:flushtags).
		if (!preg_match('/(^|\s)' . preg_quote($cmd, '/') . '(\s|$)/m', $list)) {
			$missing[] = $cmd;
		}
	}

	if (!empty($missing)) {
		throw new \Exception(
			'Required TYPO3 CLI command(s) not available in this version: '
			. implode(', ', $missing)
			. '. The recipe likely needs updating for this TYPO3 major — see COMPATIBILITY.md.'
		);
	}

	writeln('<info>  ✓ All ' . count($required) . ' required CLI commands available</info>');
});

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
		// NB: the former `upgrade:prepare` step no longer exists in TYPO3 core
		// (it was a typo3-console command). `upgrade:run` covers preparation.
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
