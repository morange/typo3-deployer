<?php

declare(strict_types=1);

namespace Deployer;

// Composer management (ensure/detect/install)

// ============================================================================
// COMPOSER MANAGEMENT
// ============================================================================

desc('Ensure composer.phar exists and is valid');
task('composer:ensure', function () {
	$sharedComposer = get('deploy_path') . '/shared/composer.phar';

	writeln("<comment>🔍 Checking composer.phar in shared directory...</comment>");

	// Prüfe ob Datei existiert und nicht leer ist
	$checkResult = run("if [ -f $sharedComposer ] && [ -s $sharedComposer ]; then echo 'EXISTS'; else echo 'MISSING'; fi");

	if (trim($checkResult) === 'MISSING') {
		writeln("<comment>📥 composer.phar missing or empty, downloading...</comment>");

		// Stelle sicher dass shared-Verzeichnis existiert
		run("mkdir -p " . get('deploy_path') . '/shared');

		// Lade composer.phar herunter
		run("cd " . get('deploy_path') . '/shared && wget -O composer.phar https://getcomposer.org/download/latest-stable/composer.phar 2>&1');
		run("chmod +x $sharedComposer");

		// Verifiziere Download
		$php = get('php');
		$versionCheck = run("$php $sharedComposer --version 2>&1 || echo 'FAILED'");

		if (strpos($versionCheck, 'Composer') !== false) {
			writeln("<info>✓ composer.phar successfully downloaded and verified!</info>");
		} else {
			throw new \Exception("Failed to download or verify composer.phar: " . $versionCheck);
		}
	} else {
		// Prüfe ob die existierende composer.phar funktioniert
		$php = get('php');
		$versionCheck = run("$php $sharedComposer --version 2>&1 || echo 'FAILED'");

		if (strpos($versionCheck, 'Composer') !== false) {
			writeln("<info>✓ composer.phar exists and is working</info>");
		} else {
			writeln("<comment>⚠ composer.phar exists but is not working, re-downloading...</comment>");
			run("cd " . get('deploy_path') . '/shared && wget -O composer.phar https://getcomposer.org/download/latest-stable/composer.phar 2>&1');
			run("chmod +x $sharedComposer");
			writeln("<info>✓ composer.phar re-downloaded</info>");
		}
	}
});

// ============================================================================
// COMPOSER PATH DETECTION
// ============================================================================

desc('Detect available composer path');
task('composer:detect_path', function () {
	writeln("");
	writeln("<comment>🔍 Detecting composer installation...</comment>");

	// Spezial-Check: Teste composer im release_path Kontext (für Alfahosting)
	writeln("<comment>🎯 Special check: Testing 'composer' in release_path context...</comment>");
	$contextTest = run("cd {{release_path}} && (which composer 2>&1 || whereis composer 2>&1 || echo 'NOT_FOUND')");
	writeln("<comment>  Result: " . trim($contextTest) . "</comment>");

	$versionTest = run("cd {{release_path}} && (composer --version 2>&1 || echo 'FAILED')");
	writeln("<comment>  Version test: " . substr(trim($versionTest), 0, 100) . "</comment>");

	if (stripos($versionTest, 'composer') !== false && strpos($versionTest, 'FAILED') === false) {
		writeln("<info>✓ Found 'composer' command available in release_path context!</info>");
		set('composer_cmd', 'composer');
		set('composer_needs_cd', true); // Flag dass wir cd brauchen
		writeln("");
		return 'composer';
	}

	writeln("");

	// Diagnostics: Check shared composer.phar
	$sharedComposer = get('deploy_path') . '/shared/composer.phar';
	writeln("<comment>📋 Diagnostics for $sharedComposer:</comment>");

	$fileInfo = run("ls -lh $sharedComposer 2>&1 || echo 'FILE_NOT_FOUND'");
	writeln("<comment>  File info: " . trim($fileInfo) . "</comment>");

	if (strpos($fileInfo, 'FILE_NOT_FOUND') === false) {
		$fileType = run("file $sharedComposer 2>&1");
		writeln("<comment>  File type: " . trim($fileType) . "</comment>");

		$headOutput = run("head -c 50 $sharedComposer 2>&1 | od -c | head -3");
		writeln("<comment>  First bytes (octal dump):</comment>");
		writeln("<comment>  " . str_replace("\n", "\n  ", trim($headOutput)) . "</comment>");
	}

	writeln("");

	// Liste aller zu testenden Composer-Pfade
	$composerPaths = [
		'php {{deploy_path}}/shared/composer.phar',  // PHP 8.3 mit shared composer.phar
		'{{php}} {{deploy_path}}/shared/composer.phar',  // Falls php in hosts.yml gesetzt
		'composer',
		'/usr/local/bin/composer',
		'/usr/bin/composer',
		'php {{release_path}}/composer.phar',
		'{{php}} {{release_path}}/composer.phar',
		'php {{deploy_path}}/composer.phar',
		'{{php}} {{deploy_path}}/composer.phar',
	];

	$allResults = [];

	foreach ($composerPaths as $path) {
		$testCmd = str_replace(
			['{{deploy_path}}', '{{release_path}}', '{{php}}'],
			[get('deploy_path'), get('release_path'), get('php')],
			$path
		);

		writeln("<comment>  Testing: $testCmd</comment>");

		$result = run("($testCmd --version 2>&1 || echo '__FAILED__') | head -20");

		$rawOutput = substr(str_replace(["\n", "\r"], [" | ", ""], $result), 0, 100);
		writeln("<comment>  RAW: $rawOutput</comment>");

		if (trim($result) === '') {
			writeln("<comment>  ✗ Empty output</comment>");
			$allResults[] = "Path: $path\nResult: EMPTY";
			continue;
		}

		$allResults[] = "Path: $path\nResult: " . substr($result, 0, 150);

		if (strpos($result, '__FAILED__') !== false) {
			writeln("<comment>  ✗ Failed</comment>");
			continue;
		}

		if (stripos($result, 'composer') !== false) {
			set('composer_cmd', $path);
			writeln("");
			writeln("<info>✓ Composer found!</info>");
			writeln("<info>  Using: $path</info>");
			writeln("");
			return $path;
		}

		writeln("<comment>  ✗ Invalid</comment>");
	}

	writeln("");
	writeln("<e>❌ Could not find composer!</e>");
	writeln("");
	for ($i = 0; $i < min(4, count($allResults)); $i++) {
		writeln("<comment>" . str_replace("\n", "\n  ", $allResults[$i]) . "</comment>");
	}

	throw new \Exception("Composer not found");
});

// ============================================================================
// COMPOSER INSTALL WITH AUTO-DETECTION
// ============================================================================

desc('Install composer dependencies');
task('composer:install', function () {
	// Stelle sicher dass composer.phar existiert
	invoke('composer:ensure');

	// Composer-Pfad ermitteln falls noch nicht geschehen
	if (!has('composer_cmd')) {
		writeln("<comment>Composer path not set, detecting...</comment>");
		invoke('composer:detect_path');
	}

	$composerCmd = get('composer_cmd');
	$needsCd = has('composer_needs_cd') && get('composer_needs_cd');

	writeln("<comment>📦 Installing dependencies with: $composerCmd</comment>");
	if ($needsCd) {
		writeln("<comment>   (using cd context)</comment>");
	}

	// Composer install ausführen
	try {
		if ($needsCd || $composerCmd === 'composer') {
			// Bei 'composer' command immer cd nutzen
			$result = run('cd {{release_path}} && ' . $composerCmd . ' install --no-dev --optimize-autoloader --no-interaction --no-scripts 2>&1');
		} else {
			// Bei absoluten Pfaden kein cd nötig
			$result = run('cd {{release_path}} && ' . $composerCmd . ' install --no-dev --optimize-autoloader --no-interaction --no-scripts 2>&1');
		}
		writeln('<info>✓ Composer dependencies installed successfully</info>');
	} catch (\Exception $e) {
		writeln('<e>❌ Composer install failed!</e>');
		writeln('<e>Error: ' . $e->getMessage() . '</e>');
		throw $e;
	}
});

desc('Update TYPO3 to latest patch version');
task('composer:update_typo3', function () {
	// Stelle sicher dass composer.phar existiert
	invoke('composer:ensure');

	// Composer-Pfad ermitteln falls noch nicht geschehen
	if (!has('composer_cmd')) {
		writeln("<comment>Composer path not set, detecting...</comment>");
		invoke('composer:detect_path');
	}

	$composerCmd = get('composer_cmd');

	writeln("<comment>🔄 Updating TYPO3 to latest patch version...</comment>");

	try {
		$result = run('cd {{release_path}} && ' . $composerCmd . ' update typo3/cms-* -W --no-dev --optimize-autoloader --no-interaction --no-scripts 2>&1');

		// Zeige welche TYPO3 Version installiert wurde
		$version = run('cd {{release_path}} && ' . $composerCmd . ' show typo3/cms-core | grep "versions" | awk \'{print $3}\'');
		writeln("<info>✓ TYPO3 updated to version: $version</info>");
	} catch (\Exception $e) {
		writeln('<e>❌ TYPO3 update failed!</e>');
		writeln('<e>Error: ' . $e->getMessage() . '</e>');
		throw $e;
	}
});

// ============================================================================
// UTILITY TASKS
// ============================================================================

desc('Show detected composer path');
task('composer:show_path', function () {
	invoke('composer:detect_path');
});
