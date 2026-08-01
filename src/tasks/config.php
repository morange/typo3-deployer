<?php

declare(strict_types=1);

namespace Deployer;

// Sync non-secret shared config files (settings.php, additional.php) from the
// fresh release checkout into shared/, so they stay Deployer shared_files
// (symlinked, persistent across releases) but automatically track the Git
// repository content instead of requiring a manual scp after every edit.

desc('Sync settings.php/additional.php from release into shared/');
task('deploy:sync_shared_config', function () {
	writeln('');
	writeln('<comment>🔄 Syncing shared config files from release into shared/...</comment>');

	run('mkdir -p {{deploy_path}}/shared/config/system');

	$files = [
		'config/system/settings.php',
		'config/system/additional.php',
	];

	foreach ($files as $file) {
		$releaseFile = "{{release_path}}/$file";
		$sharedFile = "{{deploy_path}}/shared/$file";

		if (test("[ -f $releaseFile ]")) {
			run("cp $releaseFile $sharedFile");
			writeln("<info>  ✓ Synced to shared: $file</info>");
		} else {
			writeln("<comment>  ⚠ Not found in release, skipping: $file</comment>");
		}
	}

	writeln('');
});