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

    public function getDashboardData(): array {
        $sql = <<<'SQL'

        SELECT * FROM (
            select DISTINCT
            all_sources.source,
            all_sources.section,
            lci.last_ci_date, uhl.last_upload,orphaned_packages.bug as wnpp_O,
            pkg_cnt_maint.nbr_packages_maint_email,
            all_sources.standards_version,
            all_sources.maintainer_email, all_sources.uploaders,
            all_sources.vcs_url,all_sources.vcs_browser,
            all_sources.bin,
            CASE WHEN p_testing.is_in_testing=1 THEN true ELSE false END as is_in_testing,
            CASE WHEN p_experimental.is_in_experimental=1 THEN true ELSE false END as is_in_experimental,
            p_bugs.bugs_for_source as bugs,
            p_bad_bugs.bugs_for_source as bad_bugs,
            p_old_bugs.bugs_for_source as old_bugs,
            last_upload_maint.last_upload as last_upload_maint,
            last_signed_upload_maint.last_upload as last_signed_upload_maint,
            popcon_src.insts as popcon_installs,
            popcon_src.vote as popcon_votes,
            popcon_src.recent as popcon_recent,
            popcon_src.nofiles as popcon_nofiles,
            popcon_src.source as popcon_source,
            CASE WHEN p_other_suites.release_count IS NULL THEN 0 ELSE p_other_suites.release_count END as release_count

            from all_sources
            -- Is orphan ?
            LEFT JOIN orphaned_packages ON orphaned_packages.source = all_sources.source
            -- Select last upload
            INNER JOIN (
                SELECT MAX(uh.date) as last_upload, uh.source
                FROM upload_history uh
                WHERE (
                    uh.distribution = 'unstable' OR uh.distribution = 'sid' OR uh.distribution = 'experimental'
                ) AND (
                        -- Do not allow nmu from holger (because of uploads for buildinfo files)
                        uh.nmu = false OR (uh.nmu = true AND uh.signed_by_email != 'holger@layer-acht.org')
                    )
                GROUP BY uh.source
            ) as uhl ON uhl.source = all_sources.source
            -- Count packages for by maintainer
            INNER JOIN (
                SELECT COUNT(DISTINCT as1.source) as nbr_packages_maint_email, as1.maintainer_email
                FROM all_sources as1
                WHERE as1.distribution = 'debian'
                AND (as1.release = 'sid' OR as1.release = 'bookworm')
                GROUP BY as1.maintainer_email
            ) as pkg_cnt_maint ON pkg_cnt_maint.maintainer_email = all_sources.maintainer_email
            -- select last CI date
            LEFT JOIN (
                SELECT MAX(ci.date) as last_ci_date, ci.source
                FROM ci
                GROUP BY ci.source
            ) as lci ON lci.source = all_sources.source
            -- Is in other releases than unstable ?
            LEFT JOIN (
                SELECT COUNT(*) as release_count, as2.source
                FROM all_sources as2
                WHERE as2.distribution = 'debian'
                AND as2.release != 'sid'
                GROUP BY as2.source
            ) as p_other_suites ON p_other_suites.source = all_sources.source
            -- Is in testing ?
            LEFT JOIN (
                SELECT COUNT(*) as is_in_testing, as2.source
                FROM all_sources as2
                WHERE as2.distribution = 'debian'
                AND as2.release = 'bookworm'
                GROUP BY as2.source
            ) as p_testing ON p_testing.source = all_sources.source
            -- Is in experimental ?
            LEFT JOIN (
                SELECT COUNT(*) as is_in_experimental, as2.source
                FROM all_sources as2
                WHERE as2.distribution = 'debian'
                AND as2.release = 'experimental'
                GROUP BY as2.source
            ) as p_experimental ON p_experimental.source = all_sources.source
            -- Source has bugs
            LEFT JOIN (
                SELECT COUNT(*) as bugs_for_source, b1.source
                FROM bugs b1
                WHERE b1.severity != 'fixed' AND b1.severity != 'wishlist'
                AND b1.done = ''
                GROUP BY b1.source
            ) as p_bugs ON p_bugs.source = all_sources.source
            -- Source has bad bugs
            LEFT JOIN (
                SELECT COUNT(*) as bugs_for_source, b2.source
                FROM bugs b2
                WHERE (
                    b2.severity = 'critical' OR b2.severity = 'serious'
                    OR b2.severity = 'important' OR b2.severity = 'grave'
                )
                AND b2.done = ''
                GROUP BY b2.source
            ) as p_bad_bugs ON p_bad_bugs.source = all_sources.source
            -- Source has very old bugs
            LEFT JOIN (
                SELECT COUNT(*) as bugs_for_source, b3.source
                FROM bugs b3
                WHERE b3.done = '' AND b3.id < 800000
                GROUP BY b3.source
            ) as p_old_bugs ON p_old_bugs.source = all_sources.source
            -- Last upload of maintainer
            LEFT JOIN (
                SELECT MAX(uh1.date) as last_upload, uh1.changed_by_email as email
                    FROM upload_history uh1
                    GROUP BY uh1.changed_by_email
            ) as last_upload_maint ON last_upload_maint.email = all_sources.maintainer_email
            -- Last signed upload of maintainer
            LEFT JOIN (
                SELECT MAX(uh2.date) as last_upload, uh2.signed_by_email as email
                    FROM upload_history uh2
                    GROUP BY uh2.signed_by_email
            ) as last_signed_upload_maint ON last_signed_upload_maint.email = all_sources.maintainer_email
            -- Popcon
            LEFT JOIN (
                SELECT ppcs.insts, ppcs.vote, ppcs.recent, ppcs.nofiles, ppcs.source
                FROM popcon_src ppcs
            ) as popcon_src ON popcon_src.source = all_sources.source

            WHERE distribution = 'debian' AND (release = 'sid' OR release = 'bookworm' OR release = 'experimental')
            -- Filter packages without a recent last_upload
            AND uhl.last_upload < :last_upload
            -- Standards version are recent
            AND all_sources.standards_version NOT ILIKE :standards_version
        ) as data
        --WHERE (

            -- No Vcs Field
            -- (vcs_url IS NULL OR vcs_browser IS NULL )
            --vcs_url NOT ILIKE '%salsa.debian.org%'
            --AND vcs_url NOT ILIKE 'code.launchpad.net'
            --AND (last_ci_date < '2022-01-01' OR last_ci_date IS NULL)
        --) AND is_in_testing = 1
        ORDER BY data.source ASC;

        SQL;

        $sth = $this->conn->prepare($sql);
        $sth->execute([
            ':last_upload' => '2020-01-01',
            ':standards_version' => '4.6._',
        ]);
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

    public function getLastMaintainerActivity(string $email): DateTimeImmutable|false {
        $sql = <<<'SQL'
            SELECT MAX(uh.date) as last_upload
            FROM upload_history uh
            WHERE uh.changed_by_email = :email OR uh.signed_by_email = :email
        SQL;

        $sth = $this->conn->prepare($sql);
        $sth->execute([':email' => $email]);
        $data = $sth->fetch(PDO::FETCH_ASSOC);
        if ($data === false) {
            return false;
        }
        return new DateTimeImmutable($data['last_upload']);
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

    public function checkList(string $package, DateTimeImmutable $lastUpload): array {
        $sourceInfo = $this->getSourceInfo($package, 'sid');
        $isInSid = $sourceInfo !== false;
        if ($sourceInfo === false) {
            $sourceInfo = $this->getSourceInfo($package, 'experimental');
        }
        $sourceFolder = 'https://sources.debian.org/data/' . $sourceInfo['component'] . '/' . $package[0] . '/' . $package . '/' . $sourceInfo['version'];
        $copyrightContents = self::fetch($sourceFolder . '/debian/copyright');
        $watchContents = self::fetch($sourceFolder . '/debian/watch');
        $qci = [];
        $prc = [];
        $infos = [];
        $texts = [];
        $criteria = [];

        $qci['d/copyright uses DEP-5'] = str_contains($copyrightContents, '/packaging-manuals/copyright-format/1.0/');
        $qci['d/copyright uses early DEP-5 Format-Specification'] = str_contains($copyrightContents, 'Format-Specification: http://anonscm.debian.org/viewvc/dep/web/deps/dep5.mdwn');

        if ($qci['d/copyright uses early DEP-5 Format-Specification'] === false) {
            unset($qci['d/copyright uses early DEP-5 Format-Specification']);
        }

        $FTBFSBugs = $this->getFTBFSBugs($package);

        if ($sourceInfo['component'] !== 'main') {
            $infos['Component is: ' . $sourceInfo['component'] . ' (!)'] = true;
        }

        $prc['Has FTBFS bugs'] = count($FTBFSBugs) > 0;
        $qci['Has d/watch file'] = ! empty($watchContents);
        if (empty($copyrightContents)) {
            $prc['Has d/copyright file (!)'] = false;
        } else {
            $texts[] = 'Debian copyright: ' . $sourceFolder . '/debian/copyright';
        }

        $dependents = $this->getDependents($package);

        $dependents = array_filter($dependents, static function ($p) use ($package): bool {
            return $p['source'] !== $package;
        });

        $prc['Has reverse dependencies'] = count($dependents) > 0;
        if ($prc['Has reverse dependencies']) {
            $criteria[] = 'no rdeps';
        }
        foreach ($dependents as $d) {
            $texts[] = sprintf('Dependent: %s on %s from %s', $d['source'], $d['release'], $d['distribution']);
        }

        $qci['Has autopkgtests'] = $sourceInfo['testsuite'] !== null;
        $standardsUpToDate = str_contains($sourceInfo['standards_version'], '4.');
        $qci['Standards are somewhat up-to-date'] = $standardsUpToDate;

        $prc['Up to date with upstream'] = '?';
        $upstreamStatus = $this->upstreamStatus($package);
        if ($upstreamStatus !== null) {
            $prc['Up to date with upstream'] = match ($upstreamStatus['status']) {
                'newer package available' => false,
                'error' => '!',
                default => $upstreamStatus['status'],
            };
            // var_dump($upstreamStatus);
            if ($upstreamStatus['status'] === 'error') {
                $qci['d/watch works (!)'] = false;
            }
        }

        if ($prc['Up to date with upstream']) {
            $criteria[] = 'outdated';
        }

        $qci['Has build tests'] = '?';
        $rulesContents = self::fetch($sourceFolder . '/debian/rules');
        if (empty($rulesContents)) {
            $qci['d/rules was found (!)'] = false;
        } else {
            $texts[] = 'Debian rules: ' . $sourceFolder . '/debian/rules';
        }

        if (str_contains($rulesContents, 'override_dh_auto_test')) {
            $qci['Has build tests'] = true;
        }

        $qci['Has Debian Vcs-* fields'] = $sourceInfo['vcs_url'] !== null || $sourceInfo['vcs_browser'] !== null;
        $qci['Vcs target is valid'] = '?';

        if ($qci['Has Debian Vcs-* fields']) {
            $vcsWatch = self::fetch(
                'https://qa.debian.org/cgi-bin/vcswatch?json=on&package=' . $package
            );
            $vcsWatch = json_decode($vcsWatch, true);

            if ($vcsWatch['status'] === 'ERROR') {
                $qci['Vcs target is valid'] = false;
                $texts[] = 'Vcs error: ' . $vcsWatch['error'];
            }

            if ($vcsWatch['status'] === 'COMMITS') {
                $qci['Vcs target is valid'] = true;
            }

            if (! in_array($vcsWatch['status'], ['COMMITS', 'ERROR'])) {
                $qci['Vcs target is valid'] = $vcsWatch['status'];
            }
        }

        // No fields, so not broken
        if (! $qci['Has Debian Vcs-* fields']){
            $qci['Vcs target is valid'] = 'SKIP';
        }

        $lastUploadYear = (int) $lastUpload->format('Y');
        $currentYear = (int) date('Y');
        // Example: 2020 > 2012
        // Example: 2013 > 2012
        $prc['Last upload was more than 3 years ago'] = $currentYear - 3 > $lastUploadYear;
        $prc['Last upload was more than 10 years ago'] = $currentYear - 10 > $lastUploadYear;

        $lastMaintainedUploadYear = $this->getLastMaintainerActivity($sourceInfo['maintainer_email']);
        $infos['Last activity of the maintainer on Debian'] = $lastMaintainedUploadYear->format('Y-m-d');

        $qci['Found on GitLab salsa'] = '?';
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
            $qci['Found on GitLab salsa'] = count($salsaProjects) > 0;
        }

        $sourceInfoBookworm = $this->getSourceInfo($package, 'bookworm');
        $prc['In testing'] = $sourceInfoBookworm !== false;
        $sourceInfoBullseye = $this->getSourceInfo($package, 'bookworm');
        $prc['In stable'] = $sourceInfoBullseye !== false;
        $sourceInfoExperimental = $this->getSourceInfo($package, 'experimental');
        $prc['In experimental'] = $sourceInfoExperimental !== false;

        if (! $isInSid) {
            $prc['In sid (!)'] = $isInSid;
            $criteria[] = 'in sid';
        }

        return [
            [
                'Quality control indicators' => &$qci,
                'Package removal criteria' => &$prc,
                'Informations' => &$infos,
            ], $texts, $criteria
        ];
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
        echo 'Last upload: ' . $lastUpload->format('Y-m-d');
        $currentYear = (int) date('Y');
        $lastUploadYear = (int) $lastUpload->format('Y');
        echo ', ' . $currentYear - $lastUploadYear . ' years ago.' . PHP_EOL;
        echo '' . PHP_EOL;
        echo 'List of checks (source code: https://github.com/air-balloon/debian-dashboard#qa-report ):' . PHP_EOL;

        [$checks, $texts, $criteria] = $this->checkList($package, $lastUpload);

        foreach ($checks as $checkListName => $checkList) {
            if ($checkList === []) {
                continue;
            }
            echo '' . PHP_EOL;
            echo $checkListName . ':' . PHP_EOL;
            echo '' . PHP_EOL;
            foreach ($checkList as $check => $result) {
                $passFail = is_bool($result) ? ($result ? 'PASS' : 'FAIL') : $result;
                if ($checkListName === 'Package removal criteria') {
                    $passFail = is_bool($result) ? ($result ? 'YES' : 'NO') : $result;
                }
                echo '- ' . $check . ': ' . $passFail . PHP_EOL;
            }
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
        echo 'Package bugs:' . PHP_EOL;
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