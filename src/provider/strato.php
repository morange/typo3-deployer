<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// Provider profile: Strato
// ============================================================================
// Strato ships no dedicated CLI SAPI — php83 runs as cgi-fcgi. Without
// register_argc_argv, $argv is empty and the TYPO3 / composer CLIs receive no
// arguments. The flag below fixes that for every PHP call (typo3, cron, …).
// ============================================================================

set('php', '/usr/bin/php83');
set('php_flags', '-d register_argc_argv=1');

// ssh_multiplexing not verified for Strato — leaving the core default (off).