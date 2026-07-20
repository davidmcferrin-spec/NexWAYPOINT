<?php

declare(strict_types=1);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$qs = $id > 0 ? ('?id=' . $id) : '';
header('Location: /trips/builder.php' . $qs, true, 302);
exit;
