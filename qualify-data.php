<?php

if (! extension_loaded('mailparse')) {
    echo 'Missing ext-mailparse. Hint: apt install php-mailparse';
    exit(1);
}

echo 'Reading ...' . PHP_EOL;
$result = file_get_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json');
$result = json_decode($result, true, JSON_THROW_ON_ERROR);

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

echo 'Building ...' . PHP_EOL;
$result['packages'] = array_map(static function(array $e): array {
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
    ksort($e);
    return $e;
}, $result['packages']);

echo 'Saving ...' . PHP_EOL;
$data = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json', $data);
echo 'Done.' . PHP_EOL;
