<?php

declare(strict_types=1);

namespace Deployer;

// Disk space & file count checks (shared hosting)

// ============================================================================
// DISK SPACE MANAGEMENT (for Shared Hosting)
// ============================================================================

desc('Check available disk space before deployment');
task('deploy:check_space', function () {
	$deployPath = get('deploy_path');

	writeln('<comment>📊 Checking disk space...</comment>');

	// Prüfe verfügbaren Speicher (in KB)
	$available = run("df -k $deployPath 2>/dev/null | tail -1 | awk '{print \$4}' || echo '0'");
	$available = (int)trim($available);

	if ($available === 0) {
		writeln('<comment>⚠️  Could not determine disk space (quota command might not be available)</comment>');
		return;
	}

	// Minimum: 1.5 GB (1572864 KB)
	$minRequired = 1572864;

	$availableGB = round($available / 1024 / 1024, 2);
	$requiredGB = round($minRequired / 1024 / 1024, 2);

	writeln("<comment>  Available: {$availableGB} GB</comment>");
	writeln("<comment>  Required:  {$requiredGB} GB</comment>");

	if ($available < $minRequired) {
		writeln('');
		writeln('<fg=red>═══════════════════════════════════════════════════════════</>');
		writeln('<fg=red>  ❌ NOT ENOUGH DISK SPACE!</>');
		writeln('<fg=red>═══════════════════════════════════════════════════════════</>');
		writeln('');
		writeln("<fg=red>Available: {$availableGB} GB</>");
		writeln("<fg=red>Required:  {$requiredGB} GB</>");
		writeln('');
		writeln('<fg=yellow>Quick fixes:</>');
		writeln('<fg=yellow>1. SSH to server and run:</>');
		writeln('<fg=yellow>   cd ' . $deployPath . '</>');
		writeln('<fg=yellow>   rm -rf releases/1 releases/2 releases/3 releases/4</>');
		writeln('<fg=yellow>2. Clean logs:</>');
		writeln('<fg=yellow>   find shared/var/log -name "*.log" -mtime +30 -delete</>');
		writeln('');

		throw new \Exception("Not enough disk space! Available: {$availableGB} GB, Required: {$requiredGB} GB");
	}

	writeln('<info>✓ Sufficient disk space available</info>');
});

desc('Check file count limit (e.g. IONOS hosting limit)');
task('deploy:check_file_count', function () {
	writeln('');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('<info>  Checking File Count Limit</info>');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('');

	$deployPath = get('deploy_path');
	$envPath = "$deployPath/shared/.env";

	// Lade MAX_FILES aus shared/.env
	writeln('<comment>📄 Loading MAX_FILES from shared/.env...</comment>');

	// Prüfe ob .env existiert
	if (!test("[ -f $envPath ]")) {
		writeln('<comment>⚠️  .env file not found in shared/, using default limit</comment>');
		$maxFiles = 262144; // Default: IONOS Standard-Limit (2^18)
	} else {
		// Extrahiere MAX_FILES aus .env
		$maxFilesRaw = run("grep '^MAX_FILES=' $envPath 2>/dev/null | cut -d '=' -f2 || echo '262144'");
		$maxFiles = (int)trim($maxFilesRaw);

		if ($maxFiles === 0 || $maxFiles === 262144) {
			writeln('<comment>  → MAX_FILES not set in .env, using default: 262,144</comment>');
			$maxFiles = 262144;
		} else {
			writeln("<info>  ✓ MAX_FILES from .env: " . number_format($maxFiles, 0, ',', '.') . "</info>");
		}
	}

	writeln('');
	writeln('<comment>🔢 Counting files...</comment>');

	// Extrahiere Kunden-Pfad (Parent von deploy_path)
	// z.B. /var/www/vhosts/<account>/<project>
	//   -> /var/www/vhosts/<account>
	$customerPath = run("dirname $deployPath");
	writeln("<comment>  Customer account: $customerPath</comment>");
	writeln('');

	// Zähle ALLE Dateien im kompletten Kunden-Account
	writeln('<comment>  → Counting ALL files in customer account...</comment>');
	writeln('<comment>     (This includes ALL projects: beta, live, and others)</comment>');
	$totalCurrentFiles = run("find $customerPath -type f 2>/dev/null | wc -l || echo '0'");
	$totalCurrentFiles = (int)trim($totalCurrentFiles);
	writeln("<comment>     Current total: " . number_format($totalCurrentFiles, 0, ',', '.') . "</comment>");

	writeln('');
	writeln('<comment>  → Estimating files in new release...</comment>');
	// Zähle Dateien im neuen Release (wird gerade deployed)
	$releaseFiles = run('find {{release_path}} -type f 2>/dev/null | wc -l || echo "0"');
	$releaseFiles = (int)trim($releaseFiles);
	writeln("<comment>     New release files: " . number_format($releaseFiles, 0, ',', '.') . "</comment>");

	// Berechne geschätzte Gesamtzahl nach Deployment
	$estimatedTotal = $totalCurrentFiles + $releaseFiles;
	$percentage = round(($estimatedTotal / $maxFiles) * 100, 1);

	writeln('');
	writeln('<info>───────────────────────────────────────────────────────────</info>');
	writeln("<info>  Current total:       " . number_format($totalCurrentFiles, 0, ',', '.') . "</info>");
	writeln("<info>  + New release:       " . number_format($releaseFiles, 0, ',', '.') . "</info>");
	writeln("<info>  = Estimated after:   " . number_format($estimatedTotal, 0, ',', '.') . " / " . number_format($maxFiles, 0, ',', '.') . " ({$percentage}%)</info>");
	writeln('<info>───────────────────────────────────────────────────────────</info>');
	writeln('');

	// Prüfe ob Limit überschritten
	if ($estimatedTotal > $maxFiles) {
		writeln('');
		writeln('<fg=red>═══════════════════════════════════════════════════════════</>');
		writeln('<fg=red>  ❌ FILE COUNT LIMIT WILL BE EXCEEDED!</>');
		writeln('<fg=red>═══════════════════════════════════════════════════════════</>');
		writeln('');
		writeln("<fg=red>Current:  " . number_format($totalCurrentFiles, 0, ',', '.') . " files (all projects)</>");
		writeln("<fg=red>+ Release: " . number_format($releaseFiles, 0, ',', '.') . " files (this deployment)</>");
		writeln("<fg=red>= After:   " . number_format($estimatedTotal, 0, ',', '.') . " files</>");
		writeln("<fg=red>Maximum:   " . number_format($maxFiles, 0, ',', '.') . " files</>");
		writeln("<fg=red>Overage:   " . number_format($estimatedTotal - $maxFiles, 0, ',', '.') . " files</>");
		writeln('');
		writeln('<fg=yellow>⚠️  IMPORTANT: File limit applies to ENTIRE customer account!</>');
		writeln('<fg=yellow>   This includes ALL projects (beta, live, and others)</>');
		writeln('');
		writeln('<fg=yellow>Quick fixes:</>');
		writeln('<fg=yellow>1. Clean old releases in ALL projects:</>');
		writeln("<fg=yellow>   ssh to server and run:</>");
		writeln("<fg=yellow>   cd $customerPath</>");
		writeln('<fg=yellow>   for dir in */; do</>');
		writeln('<fg=yellow>     cd "\$dir" && rm -rf releases/1 releases/2 releases/3 2>/dev/null && cd ..</>');
		writeln('<fg=yellow>   done</>');
		writeln('<fg=yellow>2. Clean old logs in ALL projects:</>');
		writeln("<fg=yellow>   find $customerPath -path '*/var/log/*.log' -mtime +30 -delete</>");
		writeln('<fg=yellow>3. Check for unused projects:</>');
		writeln("<fg=yellow>   ls -lah $customerPath</>");
		writeln('');

		throw new \Exception("File count limit will be exceeded! Estimated: {$estimatedTotal}, Max: {$maxFiles}");
	}

	// Warnung bei >80%
	if ($percentage >= 80 && $percentage < 100) {
		writeln("<fg=yellow>⚠️  WARNING: File count is at {$percentage}% of limit!</>");
		writeln('<fg=yellow>   Consider cleaning up old releases and logs soon.</>');
		writeln('');
	}

	writeln('<info>✓ File count check passed</info>');
	writeln('');
});
