<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// Provider profile: all-inkl
// ============================================================================
// all-inkl exposes versioned CLI binaries without a dot separator (php83).
// ============================================================================

set('php', '/usr/bin/php83');

// ssh_multiplexing not verified for all-inkl — leaving the core default (off).