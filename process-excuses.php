<?php

if (! extension_loaded('yaml')) {
	echo 'Missing ext-yaml. Hint: apt install php-yaml' . PHP_EOL;
	exit(1);
}

require_once __DIR__ . '/src/UDD.php';

if (! file_exists(__DIR__ . '/excuses.yaml')) {
    $excusesYaml = Udd::fetch('https://release.debian.org/britney/excuses.yaml');
    file_put_contents(__DIR__ . '/excuses.yaml', $excusesYaml);
}

$excusesYaml = file_get_contents(__DIR__ . '/excuses.yaml');
$excusesYaml = yaml_parse($excusesYaml);

$dashboardData = [];

foreach ($excusesYaml['sources'] as $item) {

    if ($item['migration-policy-verdict'] === 'PASS' && $item['is-candidate'] && $item['new-version'] === '-') {
        echo 'Will be removed: ' . $item['source'] . PHP_EOL;
        $dashboardData[] = [
            'state' => 'PENDING_REMOVAL',
            'source' => $item['source'],
        ];
        continue;
    }

    if ($item['migration-policy-verdict'] === 'PASS' && $item['is-candidate']) {
        echo 'Will migrate: ' . $item['source'] . PHP_EOL;
        $dashboardData[] = [
            'state' => 'WILL_MIGRATE',
            'source' => $item['source'],
        ];
        continue;
    }

    if ($item['migration-policy-verdict'] === 'REJECTED_NEEDS_APPROVAL') {
        echo 'Needs approval: ' . $item['source'] . PHP_EOL;
        $dashboardData[] = [
            'state' => 'REJECTED_NEEDS_APPROVAL',
            'source' => $item['source'],
        ];
        continue;
    }

    if ($item['migration-policy-verdict'] === 'REJECTED_BLOCKED_BY_ANOTHER_ITEM') {
        echo 'Blocked by other: ' . $item['source'] . PHP_EOL;
        $dashboardData[] = [
            'state' => 'REJECTED_BLOCKED_BY_ANOTHER_ITEM',
            'source' => $item['source'],
        ];
        continue;
    }

    if ($item['migration-policy-verdict'] === 'REJECTED_WAITING_FOR_ANOTHER_ITEM') {
        echo 'Waiting for other: ' . $item['source'] . PHP_EOL;
        $dashboardData[] = [
            'state' => 'REJECTED_WAITING_FOR_ANOTHER_ITEM',
            'source' => $item['source'],
        ];
        continue;
    }

    if ($item['migration-policy-verdict'] === 'REJECTED_PERMANENTLY' && in_array('newerintesting', $item['reason'])) {
        echo 'Newer exists: ' . $item['source'] . PHP_EOL;
        continue;
    }

    if (in_array('missingbuild', $item['reason'])) {
        echo 'Missing build: ' . $item['source'] . PHP_EOL;
        $dashboardData[] = [
            'state' => 'MISSING_BUILD',
            'source' => $item['source'],
        ];
        continue;
    }

    if (in_array('autopkgtest', $item['reason'])) {
        echo 'Missing tests: ' . $item['source'] . PHP_EOL;
        $extra = '';
        if (isset($item['policy_info']) && isset($item['policy_info']['autopkgtest'])) {
            foreach ($item['policy_info']['autopkgtest'] as $pkg => $ciItem) {
                if ($pkg === 'verdict') {
                    continue;
                }
                $archs = [];
                foreach ($ciItem as $arch => $ciInfo) {
                    if (in_array($ciInfo[0], ['PASS', 'NEUTRAL', 'ALWAYSFAIL', 'IGNORE-FAIL'])) {
                        continue;
                    }
                    $archs[] = '' . $arch . ':' . $ciInfo[0];
                }
                if (count($item['policy_info']['autopkgtest']) > 1 && count($archs) > 0) {
                    $extra .= '[' . $pkg . '] ';
                    $extra .= implode(', ', $archs) . PHP_EOL;
                }
            }
        }

        $dashboardData[] = [
            'state' => 'MISSING_TESTS',
            'source' => $item['source'],
            'extra' => $extra,
        ];

        continue;
    }

    if (isset($item['policy_info']) && isset($item['policy_info']['age']) && $item['policy_info']['age']['verdict'] === 'REJECTED_TEMPORARILY') {
        echo 'Must wait: ' . $item['source'] . PHP_EOL;
        $dashboardData[] = [
            'state' => 'IS_WAITING',
            'source' => $item['source'],
        ];
        continue;
    }

    if ($item['migration-policy-verdict'] === 'REJECTED_PERMANENTLY') {
        echo 'Perm reject: ' . $item['source'] . PHP_EOL;
        continue;
    }

    var_dump($item);
}

echo 'Saving ...' . PHP_EOL;
$data = json_encode($dashboardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/packageExcuses.json', $data);
echo 'Done.' . PHP_EOL;
