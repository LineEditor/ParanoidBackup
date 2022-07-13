<?php

require 'autoload.php';
require 'phpseclib/autoload.php';

use Backup\{ParanoidBackup};

$pb = new ParanoidBackup(__DIR__);
$pb->exec();
?>