<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (class_exists('DirkDrutschmann\\CommonbookingsAdditionalFeatures\\UserManagement\\UserManagement')) {
    DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\UserManagement::uninstall();
}
