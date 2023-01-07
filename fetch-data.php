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
    CASE WHEN p_testing.is_in_testing=1 THEN true ELSE false END as is_in_testing
	from all_sources
	-- Is orphan ?
	LEFT JOIN orphaned_packages ON orphaned_packages.source = all_sources.source
	-- Select last upload
	INNER JOIN (
		SELECT MAX(uh.date) as last_upload, uh.source
		FROM upload_history uh
		WHERE uh.distribution = 'unstable' AND (
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
	 	AND as1.release IN('sid', 'bookworm')
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
	 	AND as2.release IN('bookworm')
		GROUP BY as2.source
	) as p_testing ON p_testing.source = all_sources.source

	WHERE distribution = 'debian' AND release IN('sid', 'bookworm')
	-- Filter packages without a recent last_upload
	AND uhl.last_upload < '2020-01-01'
	--AND (all_sources.bin LIKE '%php%' OR all_sources.source LIKE '%php%')
	-- Standards version are recent
	AND all_sources.standards_version NOT LIKE '4.6._'
    -- Manual excludes
	--AND source NOT IN ('phpldapadmin', 'phpsysinfo', 'php-fpdf')
	-- Manual maintainer trust excludes
	AND all_sources.maintainer_email NOT IN ('team+php-pecl@tracker.debian.org')
) as data
--WHERE (

	-- No Vcs Field
	-- (vcs_url IS NULL OR vcs_browser IS NULL )
	--vcs_url NOT LIKE '%salsa.debian.org%'
	--AND vcs_url NOT LIKE 'code.launchpad.net'
	--AND (last_ci_date < '2022-01-01' OR last_ci_date IS NULL)
--) AND is_in_testing = 1

ORDER BY last_upload ASC, nbr_packages_maint_email ASC, maintainer_email ASC;

SQL;

// See: https://udd-mirror.debian.net/
$user = 'udd-mirror';
$password = 'udd-mirror';
$dsn = 'pgsql:host=udd-mirror.debian.net;port=5432;dbname=udd;user=udd-mirror;password=udd-mirror';
$dbh = new PDO($dsn, $user, $password);

$sth = $dbh->prepare($sql);
$sth->execute();

$result = $sth->fetchAll(PDO::FETCH_ASSOC);
$data = json_encode(['packages' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.long-term.support/data/udd.json', $data);
