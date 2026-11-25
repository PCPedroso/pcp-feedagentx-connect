<?php

namespace FeedAgentX\Admin;

defined('ABSPATH') || exit;

/**
 * Admin notices for environment problems we can't resolve at activation
 * (e.g. WooCommerce deactivated after our plugin was activated).
 */
final class Notices
{
    public function __construct(
        private readonly \FeedAgentX\Infra\SettingsRepository $settings,
    ) {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'maybe_render']);
    }

    public function maybe_render(): void
    {
        if (! class_exists('WooCommerce')) {
            $this->render(
                __('FeedAgentX requires WooCommerce to be active. Please install and activate WooCommerce.', 'feedagentx-connect'),
                'error'
            );
        }
    }

    private function render(string $message, string $level = 'warning'): void
    {
        $allowed_levels = ['error', 'warning', 'success', 'info'];
        if (! in_array($level, $allowed_levels, true)) {
            $level = 'info';
        }
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr($level),
            esc_html($message)
        );
    }
}
