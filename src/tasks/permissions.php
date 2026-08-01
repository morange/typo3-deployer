<?php

declare(strict_types=1);

namespace Deployer;

// File permissions & health check

// ============================================================================
// PERMISSIONS & HEALTH CHECK
// ============================================================================

desc('Set TYPO3-compliant file permissions');
task('deploy:set_permissions', function () {
	writeln('<comment>🔒 Setting TYPO3-compliant permissions...</comment>');

	// Directories: 2775 (setgid + rwxrwxr-x)
	// Files: 0664 (rw-rw-r--)

	// Setze Verzeichnis-Permissions (2775)
	run('find {{release_path}} -type d -not -path "{{release_path}}/vendor/bin*" -print0 | xargs -0 chmod 2775 2>/dev/null || true');
	writeln('<info>  ✓ Directories: 2775 (rwxrwxr-x with setgid)</info>');

	// Setze Datei-Permissions (0664)
	run('find {{release_path}} -type f -not -path "{{release_path}}/vendor/bin*" -print0 | xargs -0 chmod 0664 2>/dev/null || true');
	writeln('<info>  ✓ Files: 0664 (rw-rw-r--)</info>');

	// Spezielle Executable-Permissions für TYPO3 CLI
	if (test('[ -f {{release_path}}/vendor/bin/typo3 ]')) {
		run('chmod +x {{release_path}}/vendor/bin/typo3');
		writeln('<info>  ✓ TYPO3 CLI: executable</info>');
	}

	// Root-Verzeichnis (/) sollte 2755 sein (setgid aber nicht group-writable)
	run('chmod 2755 {{release_path}}');
	writeln('<info>  ✓ Root directory: 2755</info>');

	// Config-Verzeichnisse sollten 2755 sein (setgid aber nicht group-writable)
	$configDirs = [
		'{{release_path}}/config',
		'{{release_path}}/config/sites',
		'{{release_path}}/config/system',
	];

	foreach ($configDirs as $dir) {
		if (test("[ -d $dir ]")) {
			run("chmod 2755 $dir");
		}
	}
	writeln('<info>  ✓ Config directories: 2755</info>');

	// public-Verzeichnis sollte 2755 sein (setgid aber nicht group-writable)
	run('chmod 2755 {{release_path}}/{{typo3_webroot}}');
	writeln('<info>  ✓ Public directory: 2755</info>');

	// .htaccess-Dateien: 0644 (Sicherheit - nur owner kann schreiben)
	run('find {{release_path}} -type f -name ".htaccess" -print0 | xargs -0 chmod 0644 2>/dev/null || true');
	writeln('<info>  ✓ .htaccess files: 0644 (security)</info>');

	writeln('<info>✓ TYPO3-compliant permissions set</info>');
});

desc('Run health check');
task('typo3:health_check', function () {
	$output = run('{{bin/typo3}} --version');
	writeln("<info>TYPO3 Version: " . $output . "</info>");
});
