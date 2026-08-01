<?php

declare(strict_types=1);

namespace Deployer;

// ============================================================================
// Provider profile: HostEurope
// ============================================================================

// HostEurope did not multiplex reliably in the reference project.
set('ssh_multiplexing', false);

// Dotted, versioned CLI binary.
set('php', '/usr/bin/php8.3');