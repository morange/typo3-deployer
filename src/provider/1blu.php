<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// Provider profile: 1blu
// ============================================================================
// 1blu's default `php` shebang resolves to an old PHP, so the TYPO3 binary must
// be invoked through an explicit interpreter. The core already routes every
// call through {{bin/php}}, so we only pin the correct binary here. The path is
// a 1blu symlink tracking the current PHP 8.3 patch release.
// ============================================================================

set('php', '/opt/php83/bin/php');

// ssh_multiplexing not verified for 1blu — leaving the core default (off).