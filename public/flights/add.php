<?php

declare(strict_types=1);

// Single-flight entry now uses the multi-leg trip builder (one empty leg).
header('Location: /trips/builder.php', true, 302);
exit;
