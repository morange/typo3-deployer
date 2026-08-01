<?php

declare(strict_types=1);

namespace Deployer;

// Cron job management helpers

// ============================================================================
// CRON JOB MANAGEMENT
// ============================================================================

desc('Show correct cron job command');
task('cron:show', function () {
	$deployPath = get('deploy_path');
	$php = get('php');
	$context = get('typo3_context');

	// Ermittle tatsächlichen PHP-Pfad falls relativ
	if ($php === 'php') {
		$phpPath = run('which php 2>/dev/null || echo "php"');
		$phpPath = trim($phpPath);
	} else {
		$phpPath = $php;
	}

	// TYPO3_CONTEXT muss auch im Cron gesetzt sein (CLI hat kein HTTP_HOST,
	// die host-basierte .htaccess-Regel greift dort nicht).
	$ctx = "TYPO3_CONTEXT=$context ";

	writeln('');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('<info>  TYPO3 Scheduler Cron Job Command</info>');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('');
	writeln('<comment>Current deployment path:</comment>');
	writeln("  $deployPath");
	writeln('');
	writeln('<comment>PHP executable:</comment>');
	writeln("  $phpPath");
	writeln('');
	writeln('<comment>TYPO3_CONTEXT:</comment>');
	writeln("  $context");
	writeln('');
	writeln('<info>Add this to your crontab (crontab -e):</info>');
	writeln('');
	writeln("<fg=green>* * * * * {$ctx}$phpPath $deployPath/current/vendor/bin/typo3 scheduler:run > /dev/null 2>&1</>");
	writeln('');
	writeln('<comment>Or with logging for debugging:</comment>');
	writeln("<fg=yellow>* * * * * {$ctx}$phpPath $deployPath/current/vendor/bin/typo3 scheduler:run >> /tmp/typo3-scheduler.log 2>&1</>");
	writeln('');
	writeln('<comment>💡 Tips:</comment>');
	writeln('  • The "current" symlink always points to the latest release');
	writeln('  • No need to update cron job after deployments');
	writeln('  • Use logging during testing, then switch to /dev/null');
	writeln('');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('');
});

desc('Check and show current crontab entries');
task('cron:check', function () {
	$deployPath = get('deploy_path');

	writeln('');
	writeln('<comment>📋 Checking current crontab...</comment>');
	writeln('');

	try {
		$currentCrontab = run('crontab -l 2>/dev/null || echo "# No crontab entries found"');

		// Prüfe ob TYPO3-relevante Einträge existieren
		$lines = explode("\n", $currentCrontab);
		$typo3Entries = [];
		$outdatedEntries = [];

		foreach ($lines as $i => $line) {
			$line = trim($line);
			if (empty($line) || strpos($line, '#') === 0) {
				continue;
			}

			if (strpos($line, 'typo3') !== false || strpos($line, 'scheduler:run') !== false) {
				$typo3Entries[] = $line;

				// Prüfe ob es ein veralteter Eintrag ist (mit /releases/XX/)
				if (preg_match('#/releases/\d+/#', $line)) {
					$outdatedEntries[] = $line;
				}
			}
		}

		if (empty($typo3Entries)) {
			writeln('<comment>⚠️  No TYPO3 scheduler entries found in crontab</comment>');
			writeln('');
			writeln('<info>💡 Run "dep cron:show" to see the correct command</info>');
		} else {
			writeln('<info>Found TYPO3 scheduler entries:</info>');
			writeln('');
			foreach ($typo3Entries as $entry) {
				if (in_array($entry, $outdatedEntries)) {
					writeln("<fg=red>  ⚠️  $entry</>");
				} else if (strpos($entry, '/current/') !== false) {
					writeln("<fg=green>  ✓  $entry</>");
				} else {
					writeln("<fg=yellow>  ?  $entry</>");
				}
			}
			writeln('');

			if (!empty($outdatedEntries)) {
				writeln('<fg=red>⚠️  WARNING: Found outdated entries with hardcoded release paths!</>');
				writeln('<comment>   These should use "/current/" instead of "/releases/XX/"</comment>');
				writeln('');
				writeln('<info>💡 Run "dep cron:show" to see the correct command</info>');
			} else if (!empty($typo3Entries)) {
				$hasCurrentSymlink = false;
				foreach ($typo3Entries as $entry) {
					if (strpos($entry, '/current/') !== false) {
						$hasCurrentSymlink = true;
						break;
					}
				}

				if ($hasCurrentSymlink) {
					writeln('<info>✓ Cron job is correctly using the "current" symlink</info>');
				} else {
					writeln('<comment>💡 Consider using "/current/" symlink for automatic updates</comment>');
					writeln('<info>   Run "dep cron:show" to see the recommended command</info>');
				}
			}
		}

		writeln('');
		writeln('<comment>Full crontab:</comment>');
		writeln('─────────────────────────────────────────────────────────');
		writeln($currentCrontab);
		writeln('─────────────────────────────────────────────────────────');

	} catch (\Exception $e) {
		writeln('<comment>⚠️  Could not read crontab: ' . $e->getMessage() . '</comment>');
	}

	writeln('');
});

desc('Interactive cron job setup helper');
task('cron:setup', function () {
	writeln('');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('<info>  TYPO3 Scheduler Cron Job Setup Helper</info>');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('');

	// Zeige aktuellen Status
	invoke('cron:check');

	writeln('');
	writeln('<info>📝 Next steps:</info>');
	writeln('');
	writeln('1. Run: <fg=green>crontab -e</>');
	writeln('2. Add or update your TYPO3 scheduler entry');
	writeln('3. Save and exit');
	writeln('');
	writeln('<comment>Run "dep cron:show" to see the exact command to use</comment>');
	writeln('');
});
