<?php
/**
 * Plugin Name:       FeedAgentX – Product Feed & Pixel for ChatGPT Business Hub and ChatGPT Ads
 * Plugin URI:        https://www.feedagentx.com
 * Description:       Turn your WooCommerce catalog into a checked product feed in OpenAI's format for ChatGPT Business Hub and ChatGPT Ads, built by FeedAgentX.com, plus a free conversion pixel (OpenAI's Measurement Pixel and Conversions API).
 * Version:           2.5.1
 * Author:            FeedAgentX Team
 * Author URI:        https://www.feedagentx.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       feedagentx-connect
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * WC requires at least: 5.0
 * WC tested up to:   11.3
 */

if (! defined('ABSPATH')) {
    exit;
}

define('FAXC_VERSION', '2.5.1');
define('FAXC_PLUGIN_FILE', __FILE__);
define('FAXC_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Composer autoloader (PSR-4: FeedAgentX\\ → includes/).
$autoload = FAXC_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

/**
 * Activation: requires WooCommerce, then runs migrations.
 */
register_activation_hook(__FILE__, static function (): void {
    if (! class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('FeedAgentX requires WooCommerce. Please install and activate WooCommerce, then try again.', 'feedagentx-connect'),
            esc_html__('Plugin Activation Error', 'feedagentx-connect'),
            ['back_link' => true]
        );
    }
    \FeedAgentX\Infra\Installer::activate();
});

register_deactivation_hook(__FILE__, [\FeedAgentX\Infra\Installer::class, 'deactivate']);

/**
 * Declare HPOS compatibility.
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Boot the plugin once all plugins are loaded so WooCommerce classes are available.
 */
add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        // Notices class will inform the admin user; REST/hooks shouldn't run.
        if (is_admin()) {
            \FeedAgentX\Plugin::instance()->notices()->register();
        }
        return;
    }

    try {
        \FeedAgentX\Plugin::instance()->boot();
    } catch (\Throwable $e) {
        // Last-resort logging — boot must never fatal-error the site.
        if (class_exists(\FeedAgentX\Infra\Logger::class)) {
            \FeedAgentX\Infra\Logger::error('Plugin failed to boot', [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Re-throw in dev so devs notice.
            throw $e;
        }
    }
});
