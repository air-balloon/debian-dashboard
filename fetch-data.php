<?php

// SELECT CONCAT('- #', id, ' (', title, ')') FROM bugs WHERE source = 'rtpg'

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
    last_upload_maint.last_upload as last_upload_maint,
    last_signed_upload_maint.last_upload as last_signed_upload_maint,
    popcon_src.insts as popcon_installs,
    popcon_src.vote as popcon_votes,
    popcon_src.recent as popcon_recent,
    popcon_src.nofiles as popcon_nofiles,
    popcon_src.source as popcon_source

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
echo 'Saving ...' . PHP_EOL;
$data = json_encode(['packages' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(__DIR__ . '/debian.dashboard.air-balloon.cloud/data/udd.json', $data);
echo 'Done.' . PHP_EOL;
