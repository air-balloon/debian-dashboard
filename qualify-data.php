<?php
echo 'Reading ...' . PHP_EOL;
$result = file_get_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json');
$result = json_decode($result, true, JSON_THROW_ON_ERROR);

echo 'Building ...' . PHP_EOL;
$result['packages'] = array_map(static function(array $e): array {
    $e['is_team_maintained'] = false;
    if ($e['maintainer_email'] !== null && str_contains($e['maintainer_email'], '@lists.alioth.debian.org')) {
        $e['is_team_maintained'] = true;
    }
    if ($e['maintainer_email'] !== null && str_contains($e['maintainer_email'], '@tracker.debian.org')) {
        $e['is_team_maintained'] = true;
    }
    if ($e['maintainer_email'] !== null && str_contains($e['maintainer_email'], '@lists.debian.org')) {
        $e['is_team_maintained'] = true;
    }
    if ($e['maintainer_email'] !== null && str_contains($e['maintainer_email'], '@qa.debian.org')) {
        $e['is_team_maintained'] = true;
    }
    $e['score'] = 0;
    if ($e['last_ci_date'] === null) {
        $e['score'] -= 10;
    }
    if ($e['nbr_packages_maint_email'] < 10) {
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
    return $e;
}, $result['packages']);

echo 'Saving ...' . PHP_EOL;
$data = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json', $data);
echo 'Done.' . PHP_EOL;
