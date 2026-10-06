<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// SERVER-SIDE SBOM SETUP (opt-in)
// ============================================================================
// Creates a persistent sbom/ directory one level ABOVE deploy_path (sibling
// to backups/, same reasoning: survives release rotation, shared across
// stage/production of the same customer account) and uploads
// server-backup-sbom.sh so it can be cron-scheduled on the server.
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

    // Root path = one level above deploy_path (customer account root) - same
    // base as the backups/bin directories in tasks/backups.php.
    $providerRoot = trim(run("cd $deployPath && cd .. && pwd"));
    $sbomPath = $providerRoot . '/sbom';
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
    writeln("<comment>   📂 $providerRoot/sbom/</comment>");
    writeln("<comment>   ℹ️  Cronjob still needs to be added manually (crontab -e), e.g.:</comment>");
    writeln("<comment>      0 3 * * * $binPath/server-backup-sbom.sh >> $sbomPath/cron.log 2>&1</comment>");
});

// Run after deploy:setup_backups, not just deploy:shared - needs bin/ to
// already exist for the script upload.
after('deploy:setup_backups', 'deploy:setup_sbom');