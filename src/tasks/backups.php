<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// SERVER-SIDE BACKUP & BIN SETUP
// ============================================================================
// Creates persistent backups/ and bin/ directories one level ABOVE deploy_path
// (so they survive release rotation and are shared across stage/production of
// the same customer account) and uploads the server-side backup scripts that
// the local ~/typo3-backup orchestrator calls over SSH.
//
// The scripts are shipped WITH this package (server-bin/) so all projects stay
// in sync from a single source. Keep the script names and their CLI flags
// (-o/-n/-f) stable: the ~/typo3-backup orchestrator depends on that contract.
// ============================================================================

// Directory in this package that holds the server-side scripts. Overridable.
if (!has('server_bin_source')) {
    set('server_bin_source', realpath(__DIR__ . '/../../server-bin'));
}

// Which scripts to upload to the server's bin/ directory.
if (!has('server_bin_scripts')) {
    set('server_bin_scripts', [
        'server-backup.sh',
        'server-backup-files.sh',
        'nas-pull.sh',
    ]);
}

desc('Setup backups and bin directories outside deploy_path');
task('deploy:setup_backups', function () {
    $deployPath = get('deploy_path');
    $sourceDir = get('server_bin_source');

    writeln("<comment>🗂️  Setting up backups and bin directories...</comment>");
    writeln("<comment>   Deploy path: $deployPath</comment>");

    // Root path = one level above deploy_path (customer account root).
    $providerRoot = trim(run("cd $deployPath && cd .. && pwd"));
    writeln("<comment>   Provider root: $providerRoot</comment>");

    $backupsPath = $providerRoot . '/backups';
    $binPath = $providerRoot . '/bin';

    // ------------------------------------------------------------------------
    // BACKUPS DIRECTORY
    // ------------------------------------------------------------------------
    writeln('');
    writeln('<comment>📦 Backups Directory:</comment>');

    if (test("[ -d $backupsPath ]")) {
        $fileCount = trim(run("find $backupsPath -type f 2>/dev/null | wc -l || echo '0'"));
        writeln("<info>   ✓ Backups directory already exists ($fileCount file(s)) - leaving untouched</info>");
    } else {
        run("mkdir -p $backupsPath");
        run("chmod 755 $backupsPath");
        writeln("<info>   ✓ Created backups directory (0755): $backupsPath</info>");
    }

    // Ship a deny-all .htaccess so backups are never web-reachable.
    $htaccessTarget = $backupsPath . '/.htaccess';
    if (!test("[ -f $htaccessTarget ]")) {
        $denyAll = "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
        run("cat > $htaccessTarget <<'HTACCESS'\n$denyAll\nHTACCESS");
        run("chmod 644 $htaccessTarget");
        writeln("<info>   ✓ Wrote deny-all .htaccess (security protection)</info>");
    }

    // ------------------------------------------------------------------------
    // BIN DIRECTORY + SCRIPT UPLOAD
    // ------------------------------------------------------------------------
    writeln('');
    writeln('<comment>🔧 Bin Directory:</comment>');

    if (test("[ -d $binPath ]")) {
        writeln("<info>   ✓ Bin directory already exists - leaving untouched</info>");
    } else {
        run("mkdir -p $binPath");
        run("chmod 755 $binPath");
        writeln("<info>   ✓ Created bin directory (0755): $binPath</info>");
    }

    if ($sourceDir === false || !is_dir($sourceDir)) {
        writeln("<comment>   ⚠️  server-bin source not found ($sourceDir) - skipping script upload</comment>");
    } else {
        foreach (get('server_bin_scripts') as $script) {
            $local = $sourceDir . '/' . $script;
            $target = $binPath . '/' . $script;

            if (!file_exists($local)) {
                writeln("<comment>   ⚠️  $script not found in package server-bin/ - skipping</comment>");
                continue;
            }

            upload($local, $target);
            run("chmod +x $target");
            writeln("<info>   ✓ Uploaded + chmod +x: $script</info>");
        }
    }

    writeln('');
    writeln("<info>   ✅ Backups & Bin setup complete!</info>");
    writeln("<comment>   📂 $providerRoot/{backups,bin}/</comment>");
    writeln('');
    writeln("<comment>   ℹ️  Note: bin/.env (backup credentials) is NOT managed by deploy —</comment>");
    writeln("<comment>      it stays per-project on the server and is never in the public repo.</comment>");
});

// Wire into the pipeline after shared dirs are set up.
after('deploy:shared', 'deploy:setup_backups');