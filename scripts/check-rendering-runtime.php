<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/rendering_capabilities.php';

$report = renderingCapabilitiesCheck();
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
exit($report['ready'] ? 0 : 1);
