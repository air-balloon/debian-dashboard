<?php

if (! extension_loaded('mailparse')) {
    echo 'Missing ext-mailparse. Hint: apt install php-mailparse' . PHP_EOL;
    exit(1);
}

echo 'Reading ...' . PHP_EOL;
$result = file_get_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json');
$result = json_decode($result, true, JSON_THROW_ON_ERROR);

echo 'Reading popcons ...' . PHP_EOL;
$popcons = file_get_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/uddPopcons.json');
$popcons = json_decode($popcons, true, JSON_THROW_ON_ERROR)['popcons'];

echo 'Reading maintainers ...' . PHP_EOL;
$maintainers = file_get_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/uddMaintainers.json');
$maintainers = json_decode($maintainers, true, JSON_THROW_ON_ERROR)['maintainers'];

echo 'Reading php/web...' . PHP_EOL;
$resultWebPhp = file_get_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd-php-web.json');
$resultWebPhp = json_decode($resultWebPhp, true, JSON_THROW_ON_ERROR);

echo 'Re-building source ...' . PHP_EOL;
$result['packages'] = array_map(static function(array $e): array {
    ksort($e);
    return $e;
}, $result['packages']);

echo 'Saving ...' . PHP_EOL;
$data = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json', $data);

function is_team_email(string $email): bool {
    if (str_contains($email, '@lists.alioth.debian.org')) {
        return true;
    }
    if (str_contains($email, '@tracker.debian.org')) {
        return true;
    }
    if (str_contains($email, '@lists.debian.org')) {
        return true;
    }
    if (str_contains($email, '@qa.debian.org')) {
        return true;
    }
    return false;
}

$qualifyPackage = static function(array $e) use ($maintainers): array {
    $e['is_team_maintained'] = false;
    $uploaders = mailparse_rfc822_parse_addresses($e['uploaders']);
    foreach ($uploaders as $uploader) {
        if (is_team_email($uploader['address'])) {
            $e['is_team_maintained'] = true;
            break;
        }
    }
    if ($e['maintainer_email'] !== null && is_team_email($e['maintainer_email'])) {
        $e['is_team_maintained'] = true;
    }
    $e['score'] = 0;
    if ($e['last_ci_date'] === null) {
        $e['score'] -= 10;
    }

    if ($maintainers[$e['maintainer_email']]['nbr_packages_maint_email'] < 10) {
        $e['score'] -= 20;
    }
    if ($e['vcs_url'] === null || str_contains($e['vcs_url'], 'anonscm.debian.org')) {
        $e['score'] -= 20;
    }
    if ($e['vcs_browser'] === null) {
        $e['score'] -= 20;
    }

    if (str_contains($e['vcs_url'], 'salsa.debian.org')) {
        $e['score'] += 20;
    }

    if (stripos('3.', $e['standards_version']) === 0) {
        $e['score'] -= 20;
    }
    if (stripos('4.0', $e['standards_version']) === 0) {
        $e['score'] -= 10;
    }
    if ($e['is_in_testing']) {
        $e['score'] -= 20;
    }
    if ($e['is_in_experimental']) {
        $e['score'] += 50;
    }
    $r = new DateTimeImmutable($e['last_upload']);
    $lastUploadYear = (int) $r->format('Y');
    if ($lastUploadYear === 2021) {
        $e['score'] -= 10;
    }
    if ($lastUploadYear === 2020) {
        $e['score'] -= 20;
    }
    if ($lastUploadYear === 2019) {
        $e['score'] -= 30;
    }
    if ($lastUploadYear === 2018) {
        $e['score'] -= 40;
    }
    if ($lastUploadYear < 2019) {
        $e['score'] -= 50;
    }
    ksort($e);
    return $e;
};

$injectData = static function(array $p) use ($popcons, $maintainers): array {
    $p = array_merge($p, $popcons[$p['source']]);
    $p = array_merge($p, $maintainers[$p['maintainer_email']]);
    ksort($p);
    return $p;
};

echo 'Building ...' . PHP_EOL;
$result['packages'] = array_map($qualifyPackage, $result['packages']);

$originalPackagesList = $result['packages'];

$result['packages'] = array_map($injectData, $result['packages']);

echo 'Saving ...' . PHP_EOL;
$data = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/uddWeb.json', $data);
echo 'Done.' . PHP_EOL;


echo 'Building web/php...' . PHP_EOL;
$resultWebPhp['packages'] = array_map($qualifyPackage, $resultWebPhp['packages']);
$resultWebPhp['packages'] = array_map($injectData, $resultWebPhp['packages']);

echo 'Saving ...' . PHP_EOL;
$data = json_encode($resultWebPhp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/uddWebPhp.json', $data);
echo 'Done.' . PHP_EOL;


echo 'Building RM candidates ...' . PHP_EOL;
$result['packages'] = array_filter($originalPackagesList, static function(array $p) use ($popcons): bool {
    $r = new DateTimeImmutable($p['last_upload']);
    $lastUploadYear = (int) $r->format('Y');
    return $lastUploadYear < 2016
        && $popcons[$p['source']]['popcon_votes'] < 30
        && $p['release_count'] <= 3
        && $p['is_team_maintained'] === false;
});

$result['packages'] = array_map($injectData, $result['packages']);
$result['packages'] = array_values($result['packages']);

echo 'Saving ...' . PHP_EOL;
$data = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/uddFtpRmCandidates.json', $data);
echo 'Done.' . PHP_EOL;

echo 'Building neglected packages ...' . PHP_EOL;
$result['packages'] = array_filter($originalPackagesList, static function(array $p) use ($popcons): bool {
    $r = new DateTimeImmutable($p['last_upload']);
    $lastUploadYear = (int) $r->format('Y');
    return $lastUploadYear < 2017
        && $popcons[$p['source']]['popcon_votes'] > 30;
});

$result['packages'] = array_map($injectData, $result['packages']);
$result['packages'] = array_values($result['packages']);

echo 'Saving ...' . PHP_EOL;
$data = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/uddNeglected.json', $data);
echo 'Done.' . PHP_EOL;

echo 'Building abandoned packages ...' . PHP_EOL;
$result['packages'] = array_filter($originalPackagesList, static function(array $p) use ($maintainers): bool {
    $r = new DateTimeImmutable($p['last_upload']);
    $lastUploadYear = (int) $r->format('Y');
    $lum = new DateTimeImmutable($maintainers[$p['maintainer_email']]['last_upload_maint']);
    $lastMaintainerUploadYear = (int) $lum->format('Y');
    return $lastUploadYear < 2017
        && $lastMaintainerUploadYear < 2019;
});

$result['packages'] = array_map($injectData, $result['packages']);
$result['packages'] = array_values($result['packages']);

echo 'Saving ...' . PHP_EOL;
$data = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/uddAbandoned.json', $data);
echo 'Done.' . PHP_EOL;
