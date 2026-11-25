<?php

namespace FeedAgentX\Admin;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\ChangeLogRepository;
use FeedAgentX\Infra\SettingsRepository;
use FeedAgentX\Services\HealthService;
use FeedAgentX\Services\TokenService;

/**
 * The FeedAgentX admin menu: three pages, one job each.
 *
 *   - Product Feed (feedagentx):          connect the store to FeedAgentX
 *   - ChatGPT Ads Pixel (feedagentx-pixel): OpenAI's Measurement Pixel + Conversions API (PixelPage)
 *   - Diagnostics (feedagentx-diagnostics): health, endpoints, rate limits, logs
 *
 * Every feed decision (which products go to Ads, seller details,
 * policies, countries, brand) is made in FeedAgentX, not here.
 *
 * All write actions go through the token service or settings repository so
 * we never touch wp_options directly.
 */
final class SettingsPage
{
    public const FEED_SLUG = 'feedagentx';
    public const DIAGNOSTICS_SLUG = 'feedagentx-diagnostics';

    private const NONCE_ACTION = 'faxc_settings';
    private const NONCE_NAME = 'faxc_nonce';

    /** A result message was printed on this page load. */
    private bool $message_shown = false;

    /**
     * The brand "F" as a single-colour 20×20 glyph. WordPress repaints
     * a data-URI menu icon in the admin colour scheme, so it sits with
     * the other menu icons instead of as a dark tile.
     */
    private const MENU_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M3.4 1.5h13.2v3.97H7.65v2.92h6.15v3.78H7.65v6.33H3.4z"/></svg>';

    public function __construct(
        private readonly TokenService $tokens,
        private readonly SettingsRepository $settings,
        private readonly HealthService $health,
        private readonly ChangeLogRepository $change_log,
        private readonly PixelPage $pixel_page,
        private readonly ReviewPrompt $review_prompt,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_faxc_save_settings', [$this, 'handle_save']);
        add_action('admin_post_faxc_rotate_token', [$this, 'handle_rotate_token']);
        add_action('admin_post_faxc_clear_change_log', [$this, 'handle_clear_change_log']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('load-toplevel_page_' . self::FEED_SLUG, [$this, 'redirect_old_tabs']);
        add_filter('plugin_action_links_' . plugin_basename(FAXC_PLUGIN_FILE), [$this, 'action_links']);
        $this->pixel_page->register();
        $this->review_prompt->register();
    }

    public function add_menu(): void
    {
        add_menu_page(
            __('FeedAgentX', 'feedagentx-connect'),
            __('FeedAgentX', 'feedagentx-connect'),
            'manage_options',
            self::FEED_SLUG,
            [$this, 'render_feed_page'],
            'data:image/svg+xml;base64,' . base64_encode(self::MENU_ICON_SVG),
            56
        );
        // The first submenu item repeats the parent; name it for what it opens.
        add_submenu_page(self::FEED_SLUG, __('Product Feed', 'feedagentx-connect'), __('Product Feed', 'feedagentx-connect'), 'manage_options', self::FEED_SLUG, [$this, 'render_feed_page']);
        add_submenu_page(self::FEED_SLUG, __('ChatGPT Ads Pixel', 'feedagentx-connect'), __('ChatGPT Ads Pixel', 'feedagentx-connect'), 'manage_options', PixelPage::SLUG, [$this, 'render_pixel_page']);
        add_submenu_page(self::FEED_SLUG, __('Diagnostics', 'feedagentx-connect'), __('Diagnostics', 'feedagentx-connect'), 'manage_options', self::DIAGNOSTICS_SLUG, [$this, 'render_diagnostics_page']);
    }

    /**
     * Before 2.4 these were tabs of one page (`&tab=...`). Old bookmarks,
     * notices and guides keep working.
     */
    public function redirect_old_tabs(): void
    {
        if (! isset($_GET['tab'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing
            return;
        }
        $tab = sanitize_key((string) $_GET['tab']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing
        $page = match ($tab) {
            'pixel' => PixelPage::SLUG,
            'diagnostics' => self::DIAGNOSTICS_SLUG,
            default => self::FEED_SLUG,
        };
        wp_safe_redirect(admin_url('admin.php?page=' . $page));
        exit;
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function action_links(mixed $links): mixed
    {
        if (! is_array($links)) {
            return $links;
        }
        return array_merge([
            'faxc-pixel' => $this->pixel_page->is_live()
                ? sprintf('<a href="%s">%s</a>', esc_url(PixelPage::url()), esc_html__('ChatGPT Ads Pixel', 'feedagentx-connect'))
                : sprintf('<a href="%s"><strong>%s</strong></a>', esc_url(PixelPage::url()), esc_html__('Set up ChatGPT Ads Pixel', 'feedagentx-connect')),
            'faxc-feed' => sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=' . self::FEED_SLUG)), esc_html__('Product Feed', 'feedagentx-connect')),
        ], $links);
    }

    // ───────────────────────── Pages ─────────────────────────

    public function render_feed_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $this->open_page(
            __('Product Feed', 'feedagentx-connect'),
            __('Connect this store to FeedAgentX to build a validated product feed in OpenAI\'s format for ChatGPT Business Hub and/or ChatGPT Ads (OpenAI Ads Manager).', 'feedagentx-connect')
        );
        $this->render_review_prompt();
        $this->render_connection_status();
        if (! $this->settings->is_connected_to_app()) {
            $this->render_connection_guide();
        }
        $this->render_token_card();
        $this->render_pixel_promo();
        $this->close_page();
    }

    public function render_pixel_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $this->open_page(
            __('ChatGPT Ads Pixel', 'feedagentx-connect'),
            __('OpenAI\'s Measurement Pixel and Conversions API for your store. Free, no FeedAgentX account needed.', 'feedagentx-connect')
        );
        $this->pixel_page->render($this->render_review_prompt());
        $this->close_page();
    }

    public function render_diagnostics_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $this->open_page(
            __('Diagnostics', 'feedagentx-connect'),
            __('Versions, catalog totals and the technical settings of the FeedAgentX connection.', 'feedagentx-connect')
        );
        $this->render_health_card();
        $this->render_endpoints_card();
        $this->render_diagnostics_form();
        $this->close_page();
    }

    private function open_page(string $title, string $tagline): void
    {
        echo '<div class="wrap faxc-wrap">';
        echo '<h1 class="faxc-title"><img src="' . esc_url(plugins_url('assets/brand-mark.svg', FAXC_PLUGIN_FILE)) . '" width="36" height="36" alt="' . esc_attr__('FeedAgentX', 'feedagentx-connect') . '" />' . esc_html($title) . '</h1>';
        echo '<p class="faxc-tagline">' . esc_html($tagline) . '</p>';
        // WordPress moves admin notices to just after the first heading.
        $this->render_messages();
    }

    /**
     * The review request, on the Product Feed and pixel pages only, and
     * never stacked on the result of something the merchant just did
     * (a failed test, a rotated token).
     */
    private function render_review_prompt(): bool
    {
        return ! $this->message_shown && $this->review_prompt->maybe_render();
    }

    private function close_page(): void
    {
        echo '</div>';
    }

    // ───────────────────────── Save handlers ─────────────────────────

    public function handle_save(): void
    {
        $this->verify_nonce();
        $this->settings->update(SettingsRepository::KEY_RATE_LIMIT_PER_MINUTE, max(1, absint(wp_unslash($_POST['rate_limit_per_minute'] ?? 120)))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
        $this->settings->update(SettingsRepository::KEY_UNAUTH_RATE_LIMIT_PER_MINUTE, absint(wp_unslash($_POST['unauth_rate_limit_per_minute'] ?? 10))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
        $this->settings->update(SettingsRepository::KEY_LOG_RETENTION_DAYS, max(1, absint(wp_unslash($_POST['log_retention_days'] ?? 14)))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
        $this->settings->update(SettingsRepository::KEY_CHANGE_LOG_RETENTION_DAYS, max(1, absint(wp_unslash($_POST['change_log_retention_days'] ?? 30)))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
        $this->settings->update(SettingsRepository::KEY_LOG_ENABLED, ! empty($_POST['log_enabled'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
        $level = sanitize_key(wp_unslash((string) ($_POST['log_level'] ?? 'info'))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
        $this->settings->update(SettingsRepository::KEY_LOG_LEVEL, in_array($level, ['debug', 'info', 'warning', 'error'], true) ? $level : 'info');
        $this->redirect(self::DIAGNOSTICS_SLUG, 'saved');
    }

    public function handle_rotate_token(): void
    {
        $this->verify_nonce();
        $this->tokens->rotate();
        $this->redirect(self::FEED_SLUG, 'token_rotated');
    }

    public function handle_clear_change_log(): void
    {
        $this->verify_nonce();
        // truncate() records the cleared history as retired, so FeedAgentX
        // re-reads the whole catalog instead of skipping the changes that
        // were deleted before it fetched them.
        $this->change_log->truncate();
        // The Diagnostics page reads the cached snapshot; show the empty log now.
        $this->health->forget();
        $this->redirect(self::DIAGNOSTICS_SLUG, 'change_log_cleared');
    }

    // ───────────────────────── Render helpers ─────────────────────────

    /**
     * Where this plugin points merchants. Filterable so a self-hosted or
     * staging deployment does not have to patch the plugin.
     */
    public static function app_url(string $path = ''): string
    {
        $base = defined('FEEDAGENTX_APP_URL') ? (string) FEEDAGENTX_APP_URL : 'https://app.feedagentx.com';
        /** @var string $base */
        $base = apply_filters('feedagentx_app_url', $base);
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /** The public setup guides. Filterable like app_url(). */
    public static function docs_url(string $path = ''): string
    {
        /** @var string $base */
        $base = apply_filters('feedagentx_docs_url', 'https://www.feedagentx.com/docs');
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private function render_messages(): void
    {
        $msg = isset($_GET['faxc_msg']) ? sanitize_key((string) $_GET['faxc_msg']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only message key
        $messages = [
            'saved' => ['success', __('Settings saved.', 'feedagentx-connect')],
            'token_rotated' => ['success', __('A new API token has been generated. Update FeedAgentX.com with the new value.', 'feedagentx-connect')],
            'change_log_cleared' => ['success', __('Change log cleared. FeedAgentX will re-read the whole catalog on its next sync.', 'feedagentx-connect')],
        ] + PixelPage::messages();
        if (isset($messages[$msg])) {
            [$level, $text] = $messages[$msg];
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($level), esc_html($text));
            $this->message_shown = true;
        }
    }

    /** Shown once FeedAgentX has read this catalog: the store is in use. */
    private function render_connection_status(): void
    {
        $contact = $this->settings->last_app_contact();
        if (! $this->settings->is_connected_to_app() || $contact === null) {
            return;
        }
        echo '<section class="faxc-card faxc-live">';
        printf(
            '<h2>%s <span class="faxc-pill is-on">%s</span></h2>',
            esc_html__('Connected to FeedAgentX', 'feedagentx-connect'),
            esc_html__('Connected', 'feedagentx-connect')
        );
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: time since, e.g. "5 mins" */
            __('FeedAgentX last read your catalog %s ago. Choose products for ChatGPT Business Hub and/or ChatGPT Ads, fix feed issues and get each channel\'s feed URL there.', 'feedagentx-connect'),
            human_time_diff($contact)
        )) . '</p>';
        printf(
            '<p><a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_url(PixelPage::tracked(self::app_url('stores'), 'status', 'product-feed')),
            esc_html__('Open FeedAgentX', 'feedagentx-connect')
        );
        $this->render_business_hub_note('business-hub-status');
        echo '</section>';
    }

    /**
     * ChatGPT Business Hub takes the same OpenAI feed format as Ads Manager,
     * from its own FeedAgentX feed URL. OpenAI's statements, plus our dated
     * test (13 Nov 2026), with OpenAI's conditions; the guide has the steps.
     * FeedAgentX sees downloads, not imports: never say "connected" or "live".
     */
    private function render_business_hub_note(string $placement): void
    {
        printf(
            '<p class="description">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_html__('ChatGPT Business Hub: FeedAgentX gives you a Business Hub feed URL with its own username and password, separate from the Ads Manager URL. Add it in Business Hub as a Hosted URL; we recommend Daily refresh. In our test on 13 Nov 2026, Business Hub\'s Hosted URL option, with a username and password, processed every row of a FeedAgentX feed. You need an approved (identity-verified) business profile first. OpenAI\'s product feed specification says the standard upload currently targets the US; Business Hub asks which countries and currencies you sell in. A feed does not guarantee that ChatGPT recommends your products.', 'feedagentx-connect'),
            esc_url(PixelPage::tracked(self::docs_url('chatgpt-business-hub/'), $placement, 'product-feed')),
            esc_html__('Read the Business Hub guide', 'feedagentx-connect')
        );
    }

    /** The free pixel, offered from the feed page until it is switched on. */
    private function render_pixel_promo(): void
    {
        if ($this->pixel_page->is_live()) {
            return;
        }
        echo '<section class="faxc-card faxc-next">';
        printf(
            '<h3>%s <span class="faxc-pill is-free">%s</span></h3>',
            esc_html__('Measure the sales your ChatGPT ads bring in', 'feedagentx-connect'),
            esc_html__('Free', 'feedagentx-connect')
        );
        echo '<p>' . esc_html__('Turn on the ChatGPT Ads Pixel: OpenAI\'s Measurement Pixel and Conversions API for this store, with purchases sent from your server too. It uses the same product IDs as your feed.', 'feedagentx-connect') . '</p>';
        printf(
            '<p><a class="button button-primary" href="%s">%s</a></p>',
            esc_url(PixelPage::url()),
            esc_html__('Set up the pixel', 'feedagentx-connect')
        );
        echo '</section>';
    }

    private function render_connection_guide(): void
    {
        echo '<section class="faxc-card" aria-labelledby="faxc-get-started">';
        echo '<h2 id="faxc-get-started">' . esc_html__('Connect your store in three steps', 'feedagentx-connect') . '</h2>';
        echo '<p>' . esc_html__('The product feed is prepared in FeedAgentX: which products go to ChatGPT Business Hub and ChatGPT Ads, seller details, policies, countries and brands are all set there. You need a FeedAgentX account; the Free plan covers up to 500 products and one ChatGPT channel of your choice, Business Hub or Ads.', 'feedagentx-connect') . '</p>';
        echo '<ol style="margin-left:20px">';
        echo '<li><strong>' . esc_html__('Create an account or sign in.', 'feedagentx-connect') . '</strong> ' . esc_html__('Open FeedAgentX using the buttons below.', 'feedagentx-connect') . '</li>';
        echo '<li><strong>' . esc_html__('Connect this WooCommerce store.', 'feedagentx-connect') . '</strong> ' . esc_html__('In FeedAgentX, add a store using the Store URL below and the API token in the next card. Wait for the first catalog sync.', 'feedagentx-connect') . '</li>';
        echo '<li><strong>' . esc_html__('Set up your product feed.', 'feedagentx-connect') . '</strong> ' . esc_html__('For ChatGPT Business Hub, create the Business Hub feed URL in FeedAgentX and add it in Business Hub as a Hosted URL with its username and password. For ChatGPT Ads, open ChatGPT channels → Ads in FeedAgentX, add the hosted feed URL from its Connection tab to OpenAI Ads Manager and connect the optional Ads API after the first import. Downloading a CSV for Business Hub is only a fallback: Business Hub showed a 20 MB limit for manual uploads in our test (13 Nov 2026), and an upload does not refresh.', 'feedagentx-connect') . '</li>';
        echo '</ol>';
        $this->render_business_hub_note('business-hub-guide');
        echo '<p><label for="faxc-store-url"><strong>' . esc_html__('Store URL', 'feedagentx-connect') . '</strong></label></p>';
        echo '<input id="faxc-store-url" type="text" readonly class="large-text code" value="' . esc_attr(home_url('/')) . '" onclick="this.select()" />';
        echo '<p class="description">' . esc_html__('Select and copy this address. Keep your API token private; do not include it in a URL or support message.', 'feedagentx-connect') . '</p>';
        echo '<p style="display:flex;flex-wrap:wrap;gap:8px">';
        foreach ([
            ['sign-up', __('Create an account', 'feedagentx-connect'), 'button button-primary'],
            ['stores', __('Sign in / Open FeedAgentX', 'feedagentx-connect'), 'button'],
            ['guide', __('Setup guide', 'feedagentx-connect'), 'button'],
            ['support', __('Support', 'feedagentx-connect'), 'button'],
            ['forum', __('Support forum', 'feedagentx-connect'), 'button'],
        ] as [$path, $label, $class]) {
            $url = match ($path) {
                // WordPress.org, open to everyone: no account and no campaign tags.
                'forum' => ReviewPrompt::SUPPORT_FORUM_URL,
                'guide' => PixelPage::tracked(self::docs_url('getting-started/'), 'guide', 'product-feed'),
                default => PixelPage::tracked(self::app_url($path), 'guide', 'product-feed'),
            };
            printf('<a class="%s" href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_attr($class), esc_url($url), esc_html($label));
        }
        echo '</p>';
        echo '<p class="description">' . esc_html__('Links open in a new tab. The setup guides and the WordPress.org support forum are public; Support requires a FeedAgentX account. Installing this plugin does not launch ads or guarantee product placement.', 'feedagentx-connect') . '</p>';
        echo '</section>';
    }

    private function render_token_card(): void
    {
        $token = $this->tokens->ensure_exists();
        $generated = $this->settings->get_token_generated_at();

        echo '<div class="faxc-card">';
        echo '<h3>' . esc_html__('API Token', 'feedagentx-connect') . '</h3>';
        echo '<p class="description">' . esc_html__('Paste this value into FeedAgentX.com when connecting your store. It lets FeedAgentX read your catalog; it cannot change anything in WordPress.', 'feedagentx-connect') . '</p>';
        echo '<input type="text" readonly class="large-text code" value="' . esc_attr($token) . '" onclick="this.select()" />';
        if ($generated) {
            echo '<p class="description">' . sprintf(
                /* translators: %s: timestamp */
                esc_html__('Generated: %s UTC', 'feedagentx-connect'),
                esc_html($generated)
            ) . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<input type="hidden" name="action" value="faxc_rotate_token" />';
        submit_button(
            __('Rotate token', 'feedagentx-connect'),
            'secondary',
            'submit',
            false,
            ['onclick' => "return confirm('" . esc_js(__('Rotate the token? Existing connections will fail until updated.', 'feedagentx-connect')) . "');"]
        );
        echo '</form>';
        echo '</div>';
    }

    private function render_health_card(): void
    {
        $health = $this->health->snapshot();

        echo '<div class="faxc-card"><h3>' . esc_html__('System health', 'feedagentx-connect') . '</h3>';
        echo '<table class="widefat striped"><tbody>';
        $rows = [
            __('Plugin version', 'feedagentx-connect') => $health['plugin_version'] ?? '',
            __('Schema version', 'feedagentx-connect') => $health['schema_version'] ?? '',
            __('WooCommerce version', 'feedagentx-connect') => $health['woo_version'] ?? __('— not installed —', 'feedagentx-connect'),
            __('PHP version', 'feedagentx-connect') => $health['php_version'] ?? '',
            __('Currency', 'feedagentx-connect') => $health['currency'] ?? '',
            __('Store country', 'feedagentx-connect') => $health['store_country'] ?? '',
            __('Total products', 'feedagentx-connect') => $health['totals']['products'] ?? 0,
            __('Total variations', 'feedagentx-connect') => $health['totals']['variations'] ?? 0,
            __('Pending changes', 'feedagentx-connect') => $health['totals']['pending_changes'] ?? 0,
            __('Last change at', 'feedagentx-connect') => $health['last_change_at'] ?? __('— never —', 'feedagentx-connect'),
            __('ChatGPT Ads Pixel', 'feedagentx-connect') => $this->pixel_page->is_live() ? __('On', 'feedagentx-connect') : __('Off', 'feedagentx-connect'),
        ];
        foreach ($rows as $label => $value) {
            echo '<tr><th style="width:200px">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function render_endpoints_card(): void
    {
        $rest_base = get_rest_url(null, 'feedagentx/v1');
        echo '<div class="faxc-card"><h3>' . esc_html__('REST endpoints', 'feedagentx-connect') . '</h3>';
        echo '<p class="description">' . esc_html__('FeedAgentX reads your catalog from these. Every request needs the API token from the Product Feed page.', 'feedagentx-connect') . '</p>';
        $endpoints = [
            'health' => __('Health', 'feedagentx-connect'),
            'feed/full' => __('Full feed (paginated)', 'feedagentx-connect'),
            'feed/changes' => __('Changes', 'feedagentx-connect'),
            'feed/changes/ack' => __('Acknowledge (POST)', 'feedagentx-connect'),
            'item/{id}' => __('Single item', 'feedagentx-connect'),
        ];
        echo '<table class="widefat striped"><tbody>';
        foreach ($endpoints as $path => $label) {
            echo '<tr><th style="width:160px">' . esc_html($label) . '</th><td><code>' . esc_html($rest_base . '/' . $path) . '</code></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function render_diagnostics_form(): void
    {
        echo '<div class="faxc-card"><h3>' . esc_html__('Connection settings', 'feedagentx-connect') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<input type="hidden" name="action" value="faxc_save_settings" />';

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>' . esc_html__('Rate limit — authenticated (req/min)', 'feedagentx-connect') . '</th><td>';
        echo '<input type="number" min="1" name="rate_limit_per_minute" value="' . esc_attr((string) $this->settings->get_rate_limit_per_minute()) . '" />';
        echo '<p class="description">' . esc_html__('Per-token quota for valid Bearer requests. It cannot be turned off; the default of 120 is enough for FeedAgentX.', 'feedagentx-connect') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Rate limit — unauth (req/min/IP)', 'feedagentx-connect') . '</th><td>';
        echo '<input type="number" min="0" name="unauth_rate_limit_per_minute" value="' . esc_attr((string) $this->settings->get_unauth_rate_limit_per_minute()) . '" />';
        echo '<p class="description">' . esc_html__('Per-IP quota for failed/missing-token requests. Defends against brute force and log spam. 0 disables.', 'feedagentx-connect') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Log retention (days)', 'feedagentx-connect') . '</th><td>';
        echo '<input type="number" min="1" name="log_retention_days" value="' . esc_attr((string) $this->settings->get_log_retention_days()) . '" /></td></tr>';

        echo '<tr><th>' . esc_html__('Change log retention (days)', 'feedagentx-connect') . '</th><td>';
        echo '<input type="number" min="1" name="change_log_retention_days" value="' . esc_attr((string) $this->settings->get_change_log_retention_days()) . '" /></td></tr>';

        echo '<tr><th>' . esc_html__('Logging', 'feedagentx-connect') . '</th><td>';
        $log_enabled = (bool) get_option(SettingsRepository::KEY_LOG_ENABLED, true);
        echo '<label><input type="checkbox" name="log_enabled" value="1" ' . checked($log_enabled, true, false) . ' /> ' . esc_html__('Enable logging', 'feedagentx-connect') . '</label>';
        $level = (string) get_option(SettingsRepository::KEY_LOG_LEVEL, 'info');
        echo '<select name="log_level" style="margin-left:12px">';
        foreach (['debug', 'info', 'warning', 'error'] as $lvl) {
            printf('<option value="%s" %s>%s</option>', esc_attr($lvl), selected($level, $lvl, false), esc_html($lvl));
        }
        echo '</select></td></tr>';
        echo '</table>';

        submit_button();
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<input type="hidden" name="action" value="faxc_clear_change_log" />';
        submit_button(
            __('Clear change log', 'feedagentx-connect'),
            'delete',
            'submit',
            false,
            ['onclick' => "return confirm('" . esc_js(__('Delete every entry in the change log? FeedAgentX will then re-read the whole catalog on its next sync.', 'feedagentx-connect')) . "');"]
        );
        echo '</form>';
        echo '</div>';
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (! str_contains($hook_suffix, self::FEED_SLUG)) {
            return;
        }
        $css = '
            .faxc-wrap { max-width: 880px; }
            .faxc-title { display: flex; align-items: center; gap: 10px; }
            .faxc-tagline { color: #646970; margin: 0 0 16px; }
            .faxc-card { background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin: 16px 0; border-radius: 4px; }
            .faxc-card h3 { margin-top: 0; }
        ' . PixelPage::css();
        wp_register_style('faxc-admin', false, [], FAXC_VERSION);
        wp_add_inline_style('faxc-admin', $css);
        wp_enqueue_style('faxc-admin');
    }

    private function verify_nonce(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'feedagentx-connect'));
        }
        $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_NAME])) : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Security check failed.', 'feedagentx-connect'));
        }
    }

    private function redirect(string $page, string $msg): never
    {
        wp_safe_redirect(add_query_arg(['page' => $page, 'faxc_msg' => $msg], admin_url('admin.php')));
        exit;
    }
}
