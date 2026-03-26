<?= '<?php'?>

$serviceContainer = \Propel\Runtime\Propel::getStandardServiceContainer();
$serviceContainer->initDatabaseMapFromDumps(<?= $databaseNameToTableMapDumpsExport ?>);
