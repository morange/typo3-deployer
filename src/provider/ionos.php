<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// Provider profile: IONOS
// ============================================================================
// IONOS exposes versioned CLI binaries with a "-cli" suffix. IONOS also caps
// the number of inodes per account, so keep MAX_FILES set in shared/.env — the
// deploy:check_file_count guard reads it.
// ============================================================================

set('php', '/usr/bin/php8.3-cli');

// ssh_multiplexing not verified for IONOS — leaving the core default (off).