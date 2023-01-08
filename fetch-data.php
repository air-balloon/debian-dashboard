<?php


$sql = <<<'SQL'

SELECT * FROM (
	select DISTINCT
    all_sources.source,
	lci.last_ci_date, uhl.last_upload,orphaned_packages.bug as wnpp_O,
	pkg_cnt_maint.nbr_packages_maint_email,
	all_sources.standards_version,
	all_sources.maintainer_email, all_sources.uploaders,
	all_sources.vcs_url,all_sources.vcs_browser,
	all_sources.bin,
    CASE WHEN p_testing.is_in_testing=1 THEN true ELSE false END as is_in_testing,
    CASE WHEN p_experimental.is_in_experimental=1 THEN true ELSE false END as is_in_experimental,
    p_bugs.bugs_for_source as bugs,
    p_bad_bugs.bugs_for_source as bad_bugs

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

	WHERE distribution = 'debian' AND (release = 'sid' OR release = 'bookworm')
	-- Filter packages without a recent last_upload
	AND uhl.last_upload < '2020-01-01'
	-- Standards version are recent
	AND all_sources.standards_version NOT ILIKE '4.6._'
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

// See: https://udd-mirror.debian.net/
$user = 'udd-mirror';
$password = 'udd-mirror';
$dsn = 'pgsql:host=udd-mirror.debian.net;port=5432;dbname=udd;user=udd-mirror;password=udd-mirror';
echo 'Connecting ...' . PHP_EOL;
$dbh = new PDO($dsn, $user, $password);

$sth = $dbh->prepare($sql);
echo 'Querying ...' . PHP_EOL;
$sth->execute();
echo 'Fetching ...' . PHP_EOL;
$result = $sth->fetchAll(PDO::FETCH_ASSOC);
echo 'Building ...' . PHP_EOL;
$result = array_map(static function(array $e): array {
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
}, $result);
echo 'Saving ...' . PHP_EOL;
$data = json_encode(['packages' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json', $data);
echo 'Done.' . PHP_EOL;
