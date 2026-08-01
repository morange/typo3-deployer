<?php

declare(strict_types=1);

namespace Deployer;

// TYPO3 assets directories (after deploy:writable)

// ============================================================================
// TYPO3 ASSETS DIRECTORIES (must run AFTER deploy:writable)
// ============================================================================

desc('Ensure all TYPO3 assets subdirectories exist');
task('typo3:ensure_assets_dirs', function () {
	writeln('<comment>📁 Ensuring TYPO3 assets directories exist...</comment>');

	$assetsDirs = [
		'{{typo3_webroot}}/typo3temp/assets/compressed',
		'{{typo3_webroot}}/typo3temp/assets/css',
		'{{typo3_webroot}}/typo3temp/assets/js',
		'{{typo3_webroot}}/typo3temp/assets/images',
	];

	foreach ($assetsDirs as $dir) {
		// Prüfe ob Verzeichnis existiert
		if (!test("[ -d {{release_path}}/$dir ]")) {
			run("mkdir -p {{release_path}}/$dir");
			run("chmod 2775 {{release_path}}/$dir");
			writeln("<info>  ✓ Created: $dir</info>");
		} else {
			writeln("<comment>  → Already exists: $dir</comment>");
		}
	}

	writeln('<info>✓ All assets directories ensured</info>');
});
