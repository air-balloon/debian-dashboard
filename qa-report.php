#!/bin/php
<?php

require_once __DIR__ . '/src/UDD.php';

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
