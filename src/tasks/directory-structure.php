<?php

declare(strict_types=1);

namespace Deployer;

// TYPO3 hybrid directory structure

// ============================================================================
// TYPO3 HYBRID DIRECTORY STRUCTURE
// ============================================================================

desc('Create TYPO3 hybrid directory structure (real parents, symlinked subdirs)');
task('typo3:create_hybrid_structure', function () {
	writeln('');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('<info>  Creating TYPO3 Hybrid Directory Structure</info>');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('');
	writeln('<comment>Strategy: Option A - Hybrid Solution</comment>');
	writeln('<comment>  • Parent directories = Real directories (TYPO3-konform)</comment>');
	writeln('<comment>  • Persistent subdirs = Symlinks to shared/</comment>');
	writeln('<comment>  • Cache directories  = Per release (clean builds)</comment>');
	writeln('');

	// ========================================================================
	// PHASE 1: Parent-Directories als echte Verzeichnisse erstellen
	// ========================================================================
	writeln('<comment>📁 Phase 1: Creating parent directories as real directories...</comment>');
	writeln('');

	$parentDirs = [
		'{{typo3_webroot}}/typo3temp',
		'var/lock',
		'var',  // Sicherstellen dass var/ existiert
		'var/charset',  // TYPO3 12.x+: Muss echtes Verzeichnis sein
		'var/labels',   // TYPO3 12.x+: Muss echtes Verzeichnis sein
	];

	foreach ($parentDirs as $dir) {
		$path = "{{release_path}}/$dir";

		// Prüfe ob bereits als Symlink existiert (von alten Deployments)
		if (test("[ -L $path ]")) {
			writeln("<comment>  ⚠️  $dir is a symlink - removing it</comment>");
			run("rm $path");
			writeln("<info>  ✓ Removed symlink: $dir</info>");
		}

		// Erstelle echtes Verzeichnis mit TYPO3-konformen Permissions
		run("mkdir -p $path");
		run("chmod 2775 $path");  // setgid + rwxrwxr-x
		writeln("<info>  ✓ Created real directory: $dir</info>");
	}

	writeln('');

	// ========================================================================
	// PHASE 2: Cache-Verzeichnisse erstellen (pro Release)
	// ========================================================================
	writeln('<comment>💾 Phase 2: Creating cache directories (per release)...</comment>');
	writeln('');

	$cacheDirs = [
		'{{typo3_webroot}}/typo3temp/assets',
		'{{typo3_webroot}}/typo3temp/assets/_processed_',  // Für verarbeitete Assets
		'{{typo3_webroot}}/typo3temp/assets/compressed',   // Komprimierte Assets
		'{{typo3_webroot}}/typo3temp/assets/css',          // CSS Cache
		'{{typo3_webroot}}/typo3temp/assets/js',           // JavaScript Cache
		'{{typo3_webroot}}/typo3temp/assets/images',       // Image Cache
		'{{typo3_webroot}}/typo3temp/var',
		'{{typo3_webroot}}/typo3temp/var/cache',
		// transient wird später als Symlink erstellt
	];

	foreach ($cacheDirs as $dir) {
		run("mkdir -p {{release_path}}/$dir");
		run("chmod 2775 {{release_path}}/$dir");  // setgid + rwxrwxr-x
		writeln("<info>  ✓ Created cache dir: $dir</info>");
	}

	// index.html für typo3temp erstellen (Sicherheit)
	$indexHtml = 'Forbidden';
	run("echo '$indexHtml' > {{release_path}}/{{typo3_webroot}}/typo3temp/index.html");
	run("chmod 0664 {{release_path}}/{{typo3_webroot}}/typo3temp/index.html");  // rw-rw-r--
	writeln("<info>  ✓ Created: typo3temp/index.html</info>");

	writeln('');

	// ========================================================================
	// PHASE 3: .htaccess für /var erstellen (Sicherheit)
	// ========================================================================
	writeln('<comment>🔒 Phase 3: Creating security .htaccess...</comment>');
	writeln('');

	$htaccessContent = <<<'HTACCESS'
# Apache < 2.3
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

# Apache >= 2.3
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
HTACCESS;

	run("echo '$htaccessContent' > {{release_path}}/var/.htaccess");
	run("chmod 0664 {{release_path}}/var/.htaccess");  // rw-rw-r--
	writeln('<info>  ✓ Created: var/.htaccess</info>');

	writeln('');
	writeln('<info>✓ Phase 1-3 complete: Parent and cache directories created</info>');
	writeln('<comment>  → Phase 4 will be executed after deploy:shared</comment>');
	writeln('');
});

// ============================================================================
// PERSISTENT SUBDIRECTORIES LINKING
// ============================================================================

desc('Link persistent subdirectories to shared/');
task('typo3:link_persistent_subdirs', function () {
	writeln('');
	writeln('<comment>🔗 Phase 4: Linking persistent subdirectories...</comment>');
	writeln('');

	$subdirs = get('shared_subdirs');

	foreach ($subdirs as $subdir) {
		$sharedPath = "{{deploy_path}}/shared/$subdir";
		$releasePath = "{{release_path}}/$subdir";

		// Erstelle Verzeichnis in shared/ falls nicht vorhanden
		if (!test("[ -d $sharedPath ]")) {
			run("mkdir -p $sharedPath");
			run("chmod 2775 $sharedPath");  // setgid + rwxrwxr-x
			writeln("<info>  ✓ Created in shared: $subdir</info>");
		} else {
			writeln("<comment>  → Already exists in shared: $subdir</comment>");
		}

		// Entferne Verzeichnis im Release falls vorhanden
		// (wurde möglicherweise von rsync oder früheren Tasks erstellt)
		if (test("[ -e $releasePath ]")) {
			// Prüfe ob es bereits ein korrekter Symlink ist
			if (test("[ -L $releasePath ]")) {
				$linkTarget = run("readlink -f $releasePath");
				$expectedTarget = run("readlink -f $sharedPath");
				if (trim($linkTarget) === trim($expectedTarget)) {
					writeln("<comment>  → Already correctly linked: $subdir</comment>");
					continue;
				}
			}

			run("rm -rf $releasePath");
			writeln("<comment>  → Removed from release: $subdir</comment>");
		}

		// Erstelle Symlink
		run("ln -s $sharedPath $releasePath");
		writeln("<info>  ✓ Linked to shared: $subdir</info>");

		// Verifizierung
		if (test("[ -L $releasePath ]")) {
			writeln("<fg=green>  ✓ Verification passed: $subdir is now a symlink</>");
		} else {
			writeln("<fg=red>  ✗ ERROR: Failed to create symlink for $subdir</>");
		}
	}

	writeln('');
	writeln('<info>✓ Phase 4 complete: Persistent subdirectories linked</info>');
	writeln('');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('<info>  Hybrid Structure Summary:</info>');
	writeln('<info>═══════════════════════════════════════════════════════════</info>');
	writeln('');
	writeln('<comment>Real Directories (per release):</comment>');
	writeln('  • public/typo3temp/          (parent)');
	writeln('  • public/typo3temp/assets/   (cache)');
	writeln('  • public/typo3temp/var/cache/ (cache)');
	writeln('  • var/lock/                  (parent)');
	writeln('  • var/charset/               (TYPO3 12.x+)');
	writeln('  • var/labels/                (TYPO3 12.x+)');
	writeln('');
	writeln('<comment>Symlinks to Shared (persistent):</comment>');
	writeln('  • var/log/                   → shared/var/log/');
	writeln('  • var/session/               → shared/var/session/');
	writeln('  • public/typo3temp/var/transient/ → shared/.../transient/');
	writeln('  • public/fileadmin/          → shared/public/fileadmin/');
	writeln('');
	writeln('<comment>Result:</comment>');
	writeln('  ✓ TYPO3 Install Tool: Happy (parent dirs are real)');
	writeln('  ✓ Logs: Persistent across deployments');
	writeln('  ✓ Sessions/Logins: Work across deployments');
	writeln('  ✓ Cache: Clean rebuild per deployment');
	writeln('');
});
