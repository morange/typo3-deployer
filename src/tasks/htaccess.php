<?php

declare(strict_types=1);

namespace Deployer;

// Environment-specific .htaccess handling

// ============================================================================
// HTACCESS MANAGEMENT
// ============================================================================
//
// Strategie: Die normale public/.htaccess (SiteKit/TYPO3-Standard) wird via
// rsync ausgeliefert und auf PRODUCTION unverändert gelassen.
//
// Auf STAGE (deploy_path enthält relaunch./stage./staging./beta.) wird der
// HTTP-Basic-Auth-Block aus public/.htaccess.stage-auth OBEN an die .htaccess
// angehängt (Prepend), damit die Stage-Seite nicht öffentlich/indexierbar ist.
// So muss nicht die
// komplette .htaccess dupliziert werden — nur das kleine Auth-Snippet.
//
// Die Passwortdatei liegt unter {{deploy_path}}/.htpasswd (außerhalb der
// releases/, bleibt also über Deployments hinweg erhalten). Der Platzhalter
// __HTPASSWD_PATH__ im Snippet wird beim Deploy durch den echten Pfad ersetzt.
// ============================================================================

desc('Add HTTP Basic Auth to .htaccess on stage environments');
task('deploy:htaccess', function () {
	$deployPath = get('deploy_path');

	// Stage-Umgebung = deploy_path enthält eine dieser Subdomain-Kennungen.
	$stageMarkers = ['relaunch.', 'stage.', 'staging.', 'beta.'];
	$isStage = false;
	foreach ($stageMarkers as $marker) {
		if (strpos($deployPath, $marker) !== false) {
			$isStage = true;
			break;
		}
	}

	if (!$isStage) {
		writeln('<comment>🔧 PRODUCTION environment – .htaccess bleibt unverändert (keine Basic-Auth)</comment>');
		return;
	}

	writeln('<comment>🔧 STAGE environment – füge Basic-Auth in .htaccess ein</comment>');

	$authSnippet = '{{release_path}}/{{typo3_webroot}}/.htaccess.stage-auth';
	$targetPath = '{{release_path}}/{{typo3_webroot}}/.htaccess';

	// Auth-Snippet vorhanden?
	if (!test("[ -f $authSnippet ]")) {
		writeln('<comment>⚠ Warning: .htaccess.stage-auth nicht gefunden, überspringe Basic-Auth</comment>');
		return;
	}

	// .htaccess vorhanden? (sollte via rsync da sein)
	if (!test("[ -f $targetPath ]")) {
		writeln('<comment>⚠ Warning: .htaccess nicht gefunden, überspringe Basic-Auth</comment>');
		return;
	}

	// Idempotenz: nur einlagern, wenn noch kein Auth-Block enthalten ist
	if (test("grep -q '{{stage_auth_marker}}' $targetPath")) {
		writeln('<comment>  → Basic-Auth bereits vorhanden, überspringe</comment>');
	} else {
		// Snippet OBEN an die .htaccess anhängen (prepend)
		run("cat $authSnippet $targetPath > $targetPath.tmp && mv $targetPath.tmp $targetPath");
		writeln('<info>  ✓ Basic-Auth-Block in .htaccess eingefügt</info>');
	}

	// Platzhalter durch echten Pfad zur .htpasswd ersetzen
	$htpasswdPath = $deployPath . '/.htpasswd';
	run("sed -i 's|__HTPASSWD_PATH__|$htpasswdPath|g' $targetPath");
	writeln("<info>  → AuthUserFile: $htpasswdPath</info>");

	// Hinweis, falls .htpasswd fehlt (Auth greift sonst nicht bzw. sperrt aus)
	if (!test("[ -f $htpasswdPath ]")) {
		writeln('<comment>  ⚠ Achtung: ' . $htpasswdPath . ' existiert noch nicht –</comment>');
		writeln('<comment>    Basic-Auth greift erst, sobald die .htpasswd angelegt ist.</comment>');
	}
});

// In die Deployment-Pipeline einbinden (nach dem rsync der Code-Basis)
after('deploy:update_code', 'deploy:htaccess');
