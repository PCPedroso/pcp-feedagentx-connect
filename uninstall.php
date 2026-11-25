<?php
/**
 * Uninstall handler — fires when the user deletes the plugin from
 * Plugins → Installed Plugins. Removes all FeedAgentX data.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (class_exists(\FeedAgentX\Infra\Installer::class)) {
    \FeedAgentX\Infra\Installer::uninstall();
}
