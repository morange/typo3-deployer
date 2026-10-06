<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// SERVER-SIDE SBOM SETUP + GENERATION (opt-in)
// ============================================================================
// Creates a persistent sbom/ directory INSIDE deploy_path (sibling to
// current/releases/shared - survives release rotation, but kept separate per
// environment/host, unlike backups/bin which intentionally sit one level
// above deploy_path and are shared across stage+production of the same
// customer account), uploads server-backup-sbom.sh, and then runs it against
// THIS release right away (deploy:generate_sbom below) - nothing changes
// between deploys, so a fresh SBOM exactly when the release goes live is the
// right cadence, not a periodic cron. The only cron a consuming project needs
// is its own NAS-pull job fetching both the DB backup and this SBOM - see
// server-bin/nas-pull.sh. server-backup-sbom.sh stays independently runnable
// (manually or ad hoc) for a one-off snapshot outside a deploy.
//
// Disabled by default - a project opts in explicitly because it additionally
// requires cyclonedx/cyclonedx-php-composer in ITS OWN composer.json (project-
// specific, not shared here) for `composer CycloneDX:make-sbom` to resolve:
//
//   set('sbom_enabled', true);
// ============================================================================

if (!has('sbom_enabled')) {
    set('sbom_enabled', false);
}

desc('Setup sbom directory and upload server-backup-sbom.sh (opt-in via sbom_enabled)');
task('deploy:setup_sbom', function () {
    if (!get('sbom_enabled')) {
        return;
    }

    $deployPath = get('deploy_path');
    $sourceDir = get('server_bin_source');

    writeln("<comment>🧾 Setting up sbom directory...</comment>");

    // sbom/ lives INSIDE deploy_path (sibling of current/releases/shared) -
    // per environment, unlike bin/ which stays one level above (shared across
    // stage/production), same base as in tasks/backups.php.
    $providerRoot = trim(run("cd $deployPath && cd .. && pwd"));
    $sbomPath = $deployPath . '/sbom';
    $binPath = $providerRoot . '/bin';

    if (test("[ -d $sbomPath ]")) {
        $fileCount = trim(run("find $sbomPath -type f 2>/dev/null | wc -l || echo '0'"));
        writeln("<info>   ✓ sbom directory already exists ($fileCount file(s)) - leaving untouched</info>");
    } else {
        run("mkdir -p $sbomPath");
        run("chmod 755 $sbomPath");
        writeln("<info>   ✓ Created sbom directory (0755): $sbomPath</info>");
    }

    // Ship a deny-all .htaccess so SBOM files are never web-reachable.
    $htaccessTarget = $sbomPath . '/.htaccess';
    if (!test("[ -f $htaccessTarget ]")) {
        $denyAll = "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
        run("cat > $htaccessTarget <<'HTACCESS'\n$denyAll\nHTACCESS");
        run("chmod 644 $htaccessTarget");
        writeln("<info>   ✓ Wrote deny-all .htaccess (security protection)</info>");
    }

    // Upload server-backup-sbom.sh into the (already-existing) bin/ directory.
    // bin/ itself is provisioned by deploy:setup_backups, which must therefore
    // run first - see the after() ordering below.
    if ($sourceDir === false || !is_dir($sourceDir)) {
        writeln("<comment>   ⚠️  server-bin source not found ($sourceDir) - skipping script upload</comment>");
    } else {
        $script = 'server-backup-sbom.sh';
        $local = $sourceDir . '/' . $script;
        $target = $binPath . '/' . $script;

        if (!file_exists($local)) {
            writeln("<comment>   ⚠️  $script not found in package server-bin/ - skipping</comment>");
        } else {
            upload($local, $target);
            run("chmod +x $target");
            writeln("<info>   ✓ Uploaded + chmod +x: $script</info>");
        }
    }

    writeln('');
    writeln("<info>   ✅ SBOM setup complete!</info>");
    writeln("<comment>   📂 $sbomPath/</comment>");
});

// Run after deploy:setup_backups, not just deploy:shared - needs bin/ to
// already exist for the script upload.
after('deploy:setup_backups', 'deploy:setup_sbom');

desc('Generate a fresh SBOM for this release (opt-in via sbom_enabled)');
task('deploy:generate_sbom', function () {
    if (!get('sbom_enabled')) {
        return;
    }

    $deployPath = get('deploy_path');
    $releasePath = get('release_path');
    $sbomPath = $deployPath . '/sbom';
    $providerRoot = trim(run("cd $deployPath && cd .. && pwd"));
    $script = $providerRoot . '/bin/server-backup-sbom.sh';

    if (!test("[ -f $script ]")) {
        writeln("<comment>⚠ server-backup-sbom.sh not found at $script - skipping SBOM generation</comment>");
        return;
    }

    if (!test("[ -f $releasePath/composer.json ]")) {
        writeln('<comment>⚠ No composer.json in release yet - skipping SBOM generation (first deployment?)</comment>');
        return;
    }

    writeln('<comment>🧾 Generating SBOM for this release...</comment>');

    try {
        // -r pins it to THIS release (not the still-live "current" symlink),
        // so the SBOM reflects exactly what is about to go live.
        run("$script -r $releasePath -o $sbomPath");
        writeln("<info>   ✓ SBOM generated in $sbomPath</info>");
    } catch (\Throwable $exception) {
        writeln('<comment>⚠ SBOM generation failed (non-fatal): ' . $exception->getMessage() . '</comment>');
    }
});

// Run right after the directory/script are provisioned, still before the
// symlink switch - the release has its own composer.json/vendor by now
// (deploy:update_code already ran), so analyzing {{release_path}} is safe
// even though it is not "current" yet.
after('deploy:setup_sbom', 'deploy:generate_sbom');