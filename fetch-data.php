<?php

if (! extension_loaded('pgsql')) {
	echo 'Missing ext-pdo-pgsql. Hint: apt install php-pdo-pgsql' . PHP_EOL;
	exit(1);
}

require_once __DIR__ . '/src/UDD.php';

echo 'Connecting ...' . PHP_EOL;
$udd = new UDD();
echo 'Querying ...' . PHP_EOL;
$result = $udd->getDashboardData();
echo 'Saving ...' . PHP_EOL;
$data = json_encode(['packages' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json', $data);
echo 'Done.' . PHP_EOL;
