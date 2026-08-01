<?php

declare(strict_types=1);

namespace Deployer;

// Deployment summary & log rotation

// ============================================================================
// DEPLOYMENT SUMMARY
// ============================================================================

desc('Show deployment summary and statistics');
task('deploy:show_summary', function () {
	writeln('');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('<info>  📊 DEPLOYMENT SUMMARY</info>');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('');

	$deployPath = get('deploy_path');
	$customerPath = run("dirname $deployPath");
	$releaseName = basename(get('release_path'));

	// 1. Projekt-Info
	writeln('<comment>📦 Deployment Information:</comment>');
	$projectName = basename($deployPath);

	// Stage aus Hostname ermitteln (sicherer als labels)
	$stage = 'unknown';
	if (has('labels') && isset(get('labels')['stage'])) {
		$stage = get('labels')['stage'];
	} elseif (strpos($projectName, 'relaunch') !== false || strpos($projectName, 'beta') !== false) {
		$stage = 'stage';
	} elseif (strpos($projectName, 'live') !== false) {
		$stage = 'production';
	}

	writeln("   Project:       $projectName");
	writeln("   Stage:         $stage");
	writeln("   Release:       $releaseName");
	writeln('');

	// 2. Disk Space
	writeln('<comment>💾 Disk Space:</comment>');
	$available = run("df -h $deployPath 2>/dev/null | tail -1 | awk '{print \$4}' || echo 'N/A'");
	$used = run("df -h $deployPath 2>/dev/null | tail -1 | awk '{print \$3}' || echo 'N/A'");
	$percent = run("df -h $deployPath 2>/dev/null | tail -1 | awk '{print \$5}' || echo 'N/A'");
	writeln("   Available:     " . trim($available));
	writeln("   Used:          " . trim($used) . " (" . trim($percent) . ")");
	writeln('');

	// 3. File Count (Customer-wide)
	writeln('<comment>🗂️  File Count (entire customer account):</comment>');

	// Kunde-gesamt
	$totalFiles = run("find $customerPath -type f 2>/dev/null | wc -l || echo '0'");
	$totalFiles = (int)trim($totalFiles);

	// Dieses Projekt
	$projectFiles = run("find $deployPath -type f 2>/dev/null | wc -l || echo '0'");
	$projectFiles = (int)trim($projectFiles);

	// MAX_FILES aus .env laden
	$envPath = "$deployPath/shared/.env";
	$maxFiles = 0;
	if (test("[ -f $envPath ]")) {
		$maxFilesRaw = trim(run("grep '^MAX_FILES=' $envPath 2>/dev/null | cut -d '=' -f2"));
		$maxFiles = (int)$maxFilesRaw;
	}
	if ($maxFiles <= 0) {
		$maxFiles = 262144;
	}

	$percentage = round(($totalFiles / $maxFiles) * 100, 1);

	writeln("   Customer total:     " . number_format($totalFiles, 0, ',', '.') . " / " . number_format($maxFiles, 0, ',', '.') . " ({$percentage}%)");
	writeln("   This project:       " . number_format($projectFiles, 0, ',', '.'));

	// Warnung bei >80%
	if ($percentage >= 80 && $percentage < 100) {
		writeln('');
		writeln('   <fg=yellow>⚠️  WARNING: File count at ' . $percentage . '% - consider cleanup soon!</>');
	} elseif ($percentage >= 100) {
		writeln('');
		writeln('   <fg=red>❌ CRITICAL: File count over limit!</>');
	}
	writeln('');

	// 4. Releases Übersicht
	writeln('<comment>📂 Releases on Server:</comment>');
	$releases = run("ls -1t $deployPath/releases 2>/dev/null | head -5 || echo ''");
	$releasesArray = array_filter(explode("\n", trim($releases)));

	if (count($releasesArray) > 0) {
		$current = basename(run("readlink $deployPath/current 2>/dev/null || echo ''"));
		foreach ($releasesArray as $index => $release) {
			$marker = ($release === $current) ? '→' : ' ';
			$marker = ($release === $releaseName) ? '✓' : $marker;
			writeln("   $marker $release" . ($release === $current ? ' (current)' : '') . ($release === $releaseName ? ' (just deployed)' : ''));
		}

		$totalReleases = (int)run("ls -1 $deployPath/releases 2>/dev/null | wc -l || echo '0'");
		if ($totalReleases > 5) {
			writeln("   ... and " . ($totalReleases - 5) . " older release(s)");
		}
	} else {
		writeln("   No releases found");
	}
	writeln('');

	// 5. Weitere Projekte im Account
	writeln('<comment>🏢 Other Projects in Customer Account:</comment>');
	$allProjects = run("ls -1 $customerPath 2>/dev/null | grep -v '^\.' || echo ''");
	$projectsArray = array_filter(explode("\n", trim($allProjects)));

	if (count($projectsArray) > 1) {
		foreach ($projectsArray as $project) {
			$project = trim($project);
			if (empty($project)) continue;

			$isCurrent = ($project === $projectName);
			$marker = $isCurrent ? '→' : ' ';

			// Dateien zählen (schnell, nur Schätzung)
			$count = run("find $customerPath/$project -type f 2>/dev/null | wc -l || echo '0'");
			$count = (int)trim($count);

			writeln("   $marker $project" . ($isCurrent ? ' (this deployment)' : '') . " - " . number_format($count, 0, ',', '.') . " files");
		}
	} else {
		writeln("   Only this project");
	}
	writeln('');

	// 6. TYPO3 Version (wenn vorhanden)
	if (test('[ -f {{release_path}}/vendor/bin/typo3 ]')) {
		writeln('<comment>⚡ TYPO3 Information:</comment>');

		// TYPO3 Version ermitteln
		$typo3Version = run('cd {{release_path}} && php vendor/bin/typo3 --version 2>/dev/null | head -1 || echo "Unknown"');
		writeln("   Version:       " . trim($typo3Version));
		writeln('');
	}

	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('');
});

desc('Rotate and cleanup old logs');
task('logs:rotate', function () {
	writeln('<comment>📝 Rotating logs...</comment>');

	$logDir = '{{deploy_path}}/shared/var/log';

	// Prüfe ob Log-Verzeichnis existiert
	if (!test("[ -d $logDir ]")) {
		writeln('<comment>  → No log directory found, skipping</comment>');
		return;
	}

	// Logs älter als 30 Tage löschen
	$deleted = run("find $logDir -name '*.log' -mtime +30 -delete -print 2>/dev/null | wc -l || echo '0'");
	$deleted = (int)trim($deleted);

	if ($deleted > 0) {
		writeln("<info>  ✓ Deleted $deleted old log files (>30 days)</info>");
	}

	// Große Log-Dateien (>100MB) truncaten
	$truncated = run("find $logDir -name '*.log' -size +100M -exec truncate -s 0 {} \; -print 2>/dev/null | wc -l || echo '0'");
	$truncated = (int)trim($truncated);

	if ($truncated > 0) {
		writeln("<info>  ✓ Truncated $truncated large log files (>100MB)</info>");
	}

	if ($deleted === 0 && $truncated === 0) {
		writeln('<comment>  → No logs to rotate</comment>');
	}
});
