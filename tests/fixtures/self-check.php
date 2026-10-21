<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/Helpers/selfcheck.php';

echo json_encode(selfCheckKeys(selfCheckViolations($argv[1])), JSON_THROW_ON_ERROR);
