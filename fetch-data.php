<?php

if (! extension_loaded('pgsql')) {
	echo 'Missing ext-pdo-pgsql. Hint: apt install php-pdo-pgsql' . PHP_EOL;
	exit(1);
}

require_once __DIR__ . '/src/UDD.php';

$cleanupRow = static function (array $row): array {
    unset(
        $row['nbr_packages_maint_email'],
        $row['last_upload_maint'],
        $row['last_signed_upload_maint'],
        $row['popcon_installs'],
        $row['popcon_votes'],
        $row['popcon_recent'],
        $row['popcon_nofiles'],
        $row['popcon_source'],
    );
    ksort($row);
    return $row;
};

$popconRow = static function (array $row): array {
    return [
        'source' => $row['source'],
        'popcon_installs' => $row['popcon_installs'],
        'popcon_votes' => $row['popcon_votes'],
        'popcon_recent' => $row['popcon_recent'],
        'popcon_nofiles' => $row['popcon_nofiles'],
        'popcon_source' => $row['popcon_source'],
    ];
};

$maintRow = static function (array $row): array {
    return [
        'maintainer_email' => $row['maintainer_email'],
        'nbr_packages_maint_email' => $row['nbr_packages_maint_email'],
        'last_upload_maint' => $row['last_upload_maint'],
        'last_signed_upload_maint' => $row['last_signed_upload_maint'],
    ];
};

echo 'Connecting ...' . PHP_EOL;
$udd = new UDD();
echo 'Querying ...' . PHP_EOL;
$result = $udd->getDashboardData();

$popcons = array_map($popconRow, $result);
$newPopcons = [];
foreach ($popcons as $popconBlock) {
    $src = $popconBlock['source'];
    unset($popconBlock['source']);
    $newPopcons[$src] = $popconBlock;
}
$popcons = $newPopcons;
$maintainers = array_map($maintRow, $result);
$maints = [];
foreach ($maintainers as $maintBlock) {
    $src = $maintBlock['maintainer_email'];
    unset($maintBlock['maintainer_email']);
    $maints[$src] = $maintBlock;
}
$maintainers = $maints;
$result = array_map($cleanupRow, $result);

echo 'Saving ...' . PHP_EOL;
$data = json_encode(['packages' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json', $data);
echo 'Done.' . PHP_EOL;

echo 'Querying PHP/WEB ...' . PHP_EOL;
$result = $udd->getPHPWebDashboardData();


$popconsWeb = array_map($popconRow, $result);

foreach ($popconsWeb as $popconBlock) {
    $src = $popconBlock['source'];
    unset($popconBlock['source']);
    $popcons[$src] = $popconBlock;
}

$maintainersWeb = array_map($maintRow, $result);

foreach ($maintainersWeb as $maintBlock) {
    $src = $maintBlock['maintainer_email'];
    unset($maintBlock['maintainer_email']);
    $maintainers[$src] = $maintBlock;
}

$result = array_map($cleanupRow, $result);
echo 'Saving ...' . PHP_EOL;
$data = json_encode(['packages' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd-php-web.json', $data);
echo 'Done.' . PHP_EOL;


echo 'Saving popcons...' . PHP_EOL;
ksort($popcons);
$data = json_encode(['popcons' => $popcons], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/uddPopcons.json', $data);
echo 'Done.' . PHP_EOL;

echo 'Saving maintainers...' . PHP_EOL;
ksort($maintainers);
$data = json_encode(['maintainers' => $maintainers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/uddMaintainers.json', $data);
echo 'Done.' . PHP_EOL;