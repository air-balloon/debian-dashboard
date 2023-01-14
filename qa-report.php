#!/bin/php
<?php

class UDD {

    private ?PDO $conn;

    public function __construct() {
        //echo 'Connecting ...' . PHP_EOL;
        $this->connectUdd();
    }

    public function __destruct() {
        $this->conn = null;
    }

    public function connectUdd(): void {
        // See: https://udd-mirror.debian.net/
        $user = 'udd-mirror';
        $password = 'udd-mirror';
        $dsn = 'pgsql:host=udd-mirror.debian.net;port=5432;dbname=udd;user=udd-mirror;password=udd-mirror';
        $this->conn = new PDO($dsn, $user, $password);
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function upstreamStatus(string $package): ?array {
        if (! file_exists(__DIR__ . '/upstream-status.json')) {
            $upstreamStatus = self::fetch('https://udd.debian.org/cgi-bin/upstream-status.json.cgi');
            file_put_contents(__DIR__ . '/upstream-status.json', $upstreamStatus);
        }

        $upstreamStatus = file_get_contents(__DIR__ . '/upstream-status.json');
        $upstreamStatus = json_decode($upstreamStatus, true);

        foreach ($upstreamStatus as $packageData) {
            if ($packageData['package'] === $package) {
                return $packageData;
            }
        }

        return null;
    }

    public function lastUpload(string $package): ?DateTimeImmutable {
        $sql = <<<'SQL'
            SELECT MAX(uh.date) as last_upload
            FROM upload_history uh
            WHERE uh.source = ? AND (
                uh.distribution = 'unstable' OR uh.distribution = 'sid' OR uh.distribution = 'experimental'
            ) AND (
                    -- Do not allow nmu from holger (because of uploads for buildinfo files)
                    uh.nmu = false OR (uh.nmu = true AND uh.signed_by_email != 'holger@layer-acht.org')
                )
            GROUP BY uh.source
        SQL;

        $sth = $this->conn->prepare($sql);
        $sth->execute([$package]);
        $data = $sth->fetch(PDO::FETCH_ASSOC);
        return $data === false ? null : new DateTimeImmutable($data['last_upload']) ?? false;
    }

    public function bugList(string $package): array {
        $sql = <<<'SQL'
            SELECT bugs.id, REPLACE(bugs.title, ?, '') as title, string_agg(DISTINCT ut.tag, ',') as tags
            FROM bugs
            LEFT JOIN bugs_usertags ut ON ut.id = bugs.id
            WHERE source = ?
            GROUP by bugs.id, title
        SQL;

        $sth = $this->conn->prepare($sql);
        $sth->execute([$package . ': ', $package]);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }


    public function getDependents(string $package): array {
        $sql = <<<'SQL'
            SELECT source, release, distribution FROM sources WHERE build_depends LIKE :package OR build_depends_indep LIKE :package
            UNION
            SELECT source, release, distribution FROM packages WHERE depends LIKE :package OR pre_depends LIKE :package
        SQL;

        $sth = $this->conn->prepare($sql);
        $sth->execute([':package' => '%' . $package . '%']);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSourceInfo(string $package, string $release): array|false {
        $sql = <<<'SQL'
            SELECT * FROM sources WHERE source = ? AND distribution = 'debian' AND release = ?
        SQL;

        $sth = $this->conn->prepare($sql);
        $sth->execute([$package, $release]);
        return $sth->fetch(PDO::FETCH_ASSOC);
    }

    public function getFTBFSBugs(string $package): array {
        $sql = <<<'SQL'
            SELECT bugs.id, title, tag
            FROM bugs
            LEFT JOIN bugs_usertags ut ON ut.id = bugs.id
            WHERE done = '' AND (title LIKE '%FTBFS%' OR tag LIKE '%ftbfs%')
            AND (source = ? OR package = ? OR affected_packages LIKE ? OR affected_sources LIKE ?)
        SQL;

        $sth = $this->conn->prepare($sql);
        $sth->execute([$package, $package, '%' . $package . '%', '%' . $package . '%']);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetch(string $url): string {
        $options = [
            'http' => [
                'ignore_errors' => true,
                'header' => "User-Agent: Debian Quality Dashboard (williamdes@wdes.fr)\r\n"
            ],
        ];
        $context  = stream_context_create($options);
        $data = file_get_contents($url, false, $context);

        if (str_contains($http_response_header[0], '404')) {
            return '';
        }

        return $data;
    }

    public function checkList(string $package): array {
        $sourceInfo = $this->getSourceInfo($package, 'sid');
        $isInSid = $sourceInfo !== false;
        if ($sourceInfo === false) {
            $sourceInfo = $this->getSourceInfo($package, 'experimental');
        }
        $sourceFolder = 'https://sources.debian.org/data/' . $sourceInfo['component'] . '/' . $package[0] . '/' . $package . '/' . $sourceInfo['version'];
        $copyrightContents = self::fetch($sourceFolder . '/debian/copyright');
        $watchContents = self::fetch($sourceFolder . '/debian/watch');
        $checks = [];
        $texts = [];
        $criteria = [];

        $checks['Debian copyright file uses DEP-5 format'] = str_contains($copyrightContents, '/packaging-manuals/copyright-format/1.0/');

        $FTBFSBugs = $this->getFTBFSBugs($package);

        if ($sourceInfo['component'] !== 'main') {
            $checks['Component is: ' . $sourceInfo['component'] . ' (!)'] = true;
        }

        $checks['Has FTBFS bugs'] = count($FTBFSBugs) > 0;
        $checks['No d/watch file'] = empty($watchContents);
        $checks['No d/copyright file'] = empty($copyrightContents);
        $dependents = $this->getDependents($package);
        $checks['No reverse dependencies'] = count($dependents) === 0;
        if ($checks['No reverse dependencies']) {
            $criteria[] = 'no rdeps';
        }
        foreach ($dependents as $d) {
            $texts[] = sprintf('Dependent: %s on %s from %s', $d['source'], $d['release'], $d['distribution']);
        }

        $checks['Missing autopkgtests'] = $sourceInfo['testsuite'] === null;
        $standardsUpToDate = str_contains($sourceInfo['standards_version'], '4.6')
                            || str_contains($sourceInfo['standards_version'], '4.5');
        $checks['Standards are outdated'] = ! $standardsUpToDate;

        $checks['Outdated version in Debian'] = '?';
        $upstreamStatus = $this->upstreamStatus($package);
        if ($upstreamStatus !== null) {
            $checks['Outdated version in Debian'] = match ($upstreamStatus['status']) {
                'newer package available' => true,
                'error' => '!',
                default => $upstreamStatus['status'],
            };
            // var_dump($upstreamStatus);
            if ($upstreamStatus['status'] === 'error') {
                $checks['Debian watch file has an error (!)'] = true;
            }
        }

        if ($checks['Outdated version in Debian']) {
            $criteria[] = 'outdated';
        }

        $checks['Missing build tests'] = '?';
        $rulesContents = self::fetch($sourceFolder . '/debian/rules');
        if (empty($rulesContents)) {
            $checks['Debian rules file not found (!)'] = true;
        } else {
            $texts[] = 'Debian rules: ' . $sourceFolder . '/debian/rules';
        }

        if (str_contains($rulesContents, 'override_dh_auto_test')) {
            $checks['Missing build tests'] = false;
        }

        $checks['Missing Debian Vcs-* fields'] = $sourceInfo['vcs_url'] === null && $sourceInfo['vcs_browser'] === null;
        $checks['Broken Vcs target'] = '?';

        if (str_contains($sourceInfo['vcs_url'], 'anonscm.debian.org')){
            $checks['Broken Vcs target'] = true;
        }

        // No fields, so not broken
        if ($checks['Missing Debian Vcs-* fields']){
            $checks['Broken Vcs target'] = false;
        }

        $checks['Not maintained'] = '?';
        $checks['Upstream provides tests'] = '?';

        $checks['Found on GitLab salsa'] = '?';
        if (getenv('GITLAB_TOKEN') !== false) {
            $token = getenv('GITLAB_TOKEN');
            $salsaProjects = self::fetch(
                'https://salsa.debian.org/api/v4/search?access_token=' . $token .'&scope=projects&search=' . $package
            );
            $salsaProjects = json_decode($salsaProjects, true);
            if (! is_array($salsaProjects) || isset($salsaProjects['message'])) {
                $texts[] = 'GitLab Salsa API: ' . json_encode($salsaProjects, JSON_PRETTY_PRINT);
            } else {
                foreach ($salsaProjects as $p) {
                    $texts[] = sprintf('Project: [%s](%s)', $p['path_with_namespace'], $p['web_url']);
                }
            }
            $checks['Found on GitLab salsa'] = count($salsaProjects) > 0;
        }

        $sourceInfoBookworm = $this->getSourceInfo($package, 'bookworm');
        $checks['Not in testing'] = $sourceInfoBookworm === false;
        $sourceInfoBullseye = $this->getSourceInfo($package, 'bookworm');
        $checks['Not in stable'] = $sourceInfoBullseye === false;
        $sourceInfoExperimental = $this->getSourceInfo($package, 'experimental');
        $checks['Not in experimental'] = $sourceInfoExperimental === false;

        if (! $isInSid) {
            $checks['Not in sid (!)'] = ! $isInSid;
            $criteria[] = 'not in sid';
        }

        return [$checks, $texts, $criteria];
    }

    public function packageReport(string $package): void {
        $linkCounter = 0;
        $links = [];

        echo 'Package: ftp.debian.org' . PHP_EOL;
        echo 'Severity: normal' . PHP_EOL;
        echo 'Usertags: rm-request' . PHP_EOL;
        echo 'User: ftp.debian.org@packages.debian.org' . PHP_EOL;
        echo 'Usertags: remove' . PHP_EOL;
        echo '' . PHP_EOL;
        echo 'Hi,' . PHP_EOL;
        echo '' . PHP_EOL;
        echo 'Please proceed to deleting the package: ' . $package . PHP_EOL;
        echo '' . PHP_EOL;
        $lastUpload = $this->lastUpload($package);
        echo 'Last upload: ' . $lastUpload->format('Y-m-d') . PHP_EOL;
        echo '' . PHP_EOL;
        echo 'List of checks (source code: https://github.com/air-balloon/debian-dashboard#qa-report ):' . PHP_EOL;
        echo '' . PHP_EOL;

        [$checks, $texts, $criteria] = $this->checkList($package);

        foreach ($checks as $check => $result) {
            echo '- [' . (is_bool($result) ? ($result ? 'x' : ' ') : $result) . '] ' . $check . PHP_EOL;
        }

        if (count($texts) > 0) {
            echo '' . PHP_EOL;
            echo 'Informations reported:' . PHP_EOL;
            echo '' . PHP_EOL;
            foreach ($texts as $text) {
                echo '- ' . $text . PHP_EOL;
            }
        }

        //echo '' . PHP_EOL;
        $bugs = $this->bugList($package);
        echo '' . PHP_EOL;
        echo 'Please also close the following bugs:' . PHP_EOL;
        echo '' . PHP_EOL;

        foreach ($bugs as $bug) {
            echo '- #[' . $bug['id'] . '][' . ++$linkCounter . '] (' . $bug['title'] . ')';
            echo ($bug['tags'] !== null ? ' [' . $bug['tags'] . ']' : '') . PHP_EOL;
            $links[$linkCounter] = 'https://bugs.debian.org/' . $bug['id'];
        }

        echo '' . PHP_EOL;
        echo 'Found on my QA dashboard of FTP RM candidates: https://debian.dashboard.air-balloon.cloud/en/dashboard-ftp-rm-candidates' . PHP_EOL;
        echo 'You may close and deny my requests if you think I am mistaken, all my requests are manual.' . PHP_EOL;
        echo '' . PHP_EOL;

        foreach ($links as $linkId => $link) {
            echo '[' . $linkId . ']: ' . $link . PHP_EOL;
        }
        echo '' . PHP_EOL;
        echo '--' . PHP_EOL;
        echo 'William Desportes' . PHP_EOL;

        echo '' . PHP_EOL;
        echo '' . PHP_EOL;
        $criteria[] = 'no upload since ' . $lastUpload->format('Y');
        //'upstream dead'
        echo 'Subject: RM: ' . $package . ' -- RoQA; ' . implode(', ', $criteria) . PHP_EOL;
    }
}

$shortopts  = '';
$shortopts .= 'p:';  // Required value

$longopts  = [];
$options = getopt($shortopts, $longopts);

if (empty($options['p'])) {
    echo 'Help: GITLAB_TOKEN="glpat-xxxx-xxx" ./qa-report.php -p package-name' . PHP_EOL;
    echo 'Help: ./qa-report -p package-name' . PHP_EOL;
    exit(1);
}

$udd = new UDD();
$udd->packageReport($options['p']);
