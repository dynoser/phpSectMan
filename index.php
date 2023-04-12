<?php
namespace dynoser\sectman;

require_once 'vendor/autoload.php';

require_once 'src/SectMan.php';

if (empty($argv[1])) die("source file argument required");

$src_file_name = $argv[1];
$base_dir = isset($argv[2]) ? $argv[2] : null;

$sm = new SectMan($src_file_name, $base_dir);

$info = $sm->load();

print_r($info);

$sm->divideToFiles();
