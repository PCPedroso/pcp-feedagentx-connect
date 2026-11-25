<?php

namespace FeedAgentX\Admin;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\MeasurementSettings;
use FeedAgentX\Infra\SettingsRepository;
use FeedAgentX\Measurement\Consent;
use FeedAgentX\Measurement\ConversionsApi;

/**
 * The "ChatGPT Ads Pixel" page: set up OpenAI's Measurement Pixel and
 * Conversions API, test them, and see what the server has sent.
 *
 * Works without a FeedAgentX account; nothing on this page talks to
 * FeedAgentX. It does sell it: a store that measures ChatGPT Ads sales is
 * a store that needs its products in ChatGPT Ads, which is what the
 * FeedAgentX product feed does. The pitch changes once FeedAgentX has read
 * this store's catalog. "Connected" only means FeedAgentX reads the
 * catalog: the plugin cannot know whether the feed is used in ChatGPT Ads,
 * ChatGPT Business Hub or neither, so never say the catalog is in either.
 */
final class PixelPage
{
    public const SLUG = 'feedagentx-pixel';

    private const NONCE_ACTION = 'faxc_pixel';
    private const NONCE_NAME = 'faxc_pixel_nonce';
    private const ANNOUNCE_META = 'faxc_pixel_announce_dismissed';

    private const ADS_MANAGER_URL = 'https://ads.openai.com/';
    private const PRICING_URL = 'https://www.feedagentx.com/pricing/';

    public function __construct(
        private readonly MeasurementSettings $settings,
        private readonly ConversionsApi $api,
        private readonly SettingsRepository $store,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_faxc_save_pixel', [$this, 'handle_save']);
        add_action('admin_post_faxc_test_pixel', [$this, 'handle_test']);
        add_action('admin_post_faxc_dismiss_pixel_announce', [$this, 'handle_dismiss_announce']);
        add_action('admin_notices', [$this, 'maybe_announce']);
        add_action('admin_init', [$this, 'add_privacy_policy_text']);
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::SLUG);
    }

    public function is_live(): bool
    {
        return $this->settings->is_enabled();
    }

    /** A FeedAgentX link, tagged so sign-ups from this page can be counted. */
    public static function tracked(string $url, string $placement, string $campaign = 'chatgpt-ads-pixel'): string
    {
        return add_query_arg([
            'utm_source' => 'wordpress',
            'utm_medium' => 'plugin',
            'utm_campaign' => $campaign,
            'utm_content' => $placement,
        ], $url);
    }

    /**
     * The FeedAgentX call to action for a spot on the page: sign up if
     * this store is not connected, open the app if it is.
     */
    private function feed_buttons(string $placement, bool $secondary = true): void
    {
        echo '<p class="faxc-actions">';
        if ($this->store->is_connected_to_app()) {
            printf(
                '<a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url(self::tracked(SettingsPage::app_url('stores'), $placement)),
                esc_html__('Open FeedAgentX', 'feedagentx-connect')
            );
        } else {
            printf(
                '<a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url(self::tracked(SettingsPage::app_url('sign-up'), $placement)),
                esc_html__('Create a free FeedAgentX account', 'feedagentx-connect')
            );
            if ($secondary) {
                printf(
                    ' <a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a> <a class="button-link" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    esc_url(self::tracked(SettingsPage::docs_url('getting-started/'), $placement)),
                    esc_html__('How it works', 'feedagentx-connect'),
                    esc_url(self::tracked(self::PRICING_URL, $placement)),
                    esc_html__('Plans and pricing', 'feedagentx-connect')
                );
            }
        }
        echo '</p>';
    }

    /** @return array<string, array{0: string, 1: string}> message key => [level, text] */
    public static function messages(): array
    {
        return [
            'pixel_saved' => ['success', __('Pixel settings saved.', 'feedagentx-connect')],
            'pixel_saved_live' => ['success', __('Saved. The pixel is now on your store. Send a test purchase below to check the server connection.', 'feedagentx-connect')],
            'pixel_invalid_id' => ['error', __('That Pixel ID does not look right. Copy it again from the Conversions tab in OpenAI Ads Manager: letters, numbers, dashes or underscores only.', 'feedagentx-connect')],
            'pixel_invalid_key' => ['error', __('That Conversions API key does not look right, so the saved key was kept. Copy it again without spaces.', 'feedagentx-connect')],
            'pixel_needs_key' =>['warning', __('Saved. Server purchases stay off until you add a Conversions API key.', 'feedagentx-connect')],
            'pixel_test_ok' => ['success', __('OpenAI accepted the test purchase. Your Pixel ID and Conversions API key work. Nothing was recorded in your account.', 'feedagentx-connect')],
            'pixel_test_failed' => ['error', __('OpenAI did not accept the test purchase. See the details under "Test your setup".', 'feedagentx-connect')],
            'pixel_test_unavailable' => ['warning', __('Turn on the pixel and server purchases, and save a Pixel ID and API key, before sending a test.', 'feedagentx-connect')],
        ];
    }

    // ───────────────────────── Handlers ─────────────────────────

    public function handle_save(): void
    {
        $this->verify_nonce();

        $pixel_id = trim(sanitize_text_field(wp_unslash((string) ($_POST['pixel_id'] ?? '')))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
        $enabled = ! empty($_POST['pixel_enabled']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
        $capi_enabled = ! empty($_POST['capi_enabled']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()

        $key = null; // keep the saved key
        $key_ok = true;
        if (! empty($_POST['capi_key_remove'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
            $key = '';
        } else {
            // Validated, not sanitised: sanitize_text_field() would strip
            // "%xx" sequences and silently corrupt a key.
            $typed = trim(wp_unslash((string) ($_POST['capi_key'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in verify_nonce(); key validated by the pattern below
            if ($typed !== '') {
                $key_ok = (bool) preg_match('/^[\x21-\x7E]{8,512}$/', $typed);
                $key = $key_ok ? $typed : null;
            }
        }

        $id_ok = $pixel_id === '' || MeasurementSettings::is_valid_pixel_id($pixel_id);
        $was_live = $this->settings->is_enabled();

        $this->settings->save([
            'enabled' => $enabled,
            // An invalid ID keeps the saved one rather than blanking it.
            'pixel_id' => $id_ok ? $pixel_id : $this->settings->get_pixel_id(),
            'capi_enabled' => $capi_enabled,
            'capi_key' => $key,
            'consent_mode' => sanitize_key((string) ($_POST['consent_mode'] ?? MeasurementSettings::CONSENT_AUTO)), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
            'matching' => ! empty($_POST['matching']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
            'debug' => ! empty($_POST['pixel_debug']), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_nonce()
        ]);

        if (! $id_ok) {
            $this->redirect('pixel_invalid_id');
        }
        if (! $key_ok) {
            $this->redirect('pixel_invalid_key');
        }
        if ($capi_enabled && $this->settings->get_capi_key() === '') {
            $this->redirect('pixel_needs_key');
        }
        $this->redirect(! $was_live && $this->settings->is_enabled() ? 'pixel_saved_live' : 'pixel_saved');
    }

    public function handle_test(): void
    {
        $this->verify_nonce();
        if (! $this->settings->is_capi_ready()) {
            $this->redirect('pixel_test_unavailable');
        }

        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'USD';
        $result = $this->api->send([[
            'id' => 'faxc-test-' . wp_generate_uuid4(),
            'type' => 'order_created',
            'timestamp_ms' => (int) round(microtime(true) * 1000),
            'action_source' => 'web',
            'source_url' => home_url('/'),
            'data' => [
                'type' => 'contents',
                'amount' => 1000,
                'currency' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD',
                'contents' => [[
                    'id' => 'faxc-test-item',
                    'name' => 'FeedAgentX test item',
                    'content_type' => 'product',
                    'quantity' => 1,
                ]],
            ],
        ]], true);

        $this->settings->record_test($result['ok'], $result['message']);
        $this->redirect($result['ok'] ? 'pixel_test_ok' : 'pixel_test_failed');
    }

    public function handle_dismiss_announce(): void
    {
        check_admin_referer('faxc_dismiss_pixel_announce');
        update_user_meta(get_current_user_id(), self::ANNOUNCE_META, time());
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    // ───────────────────────── Announcement ─────────────────────────

    /**
     * One dismissible note on the Dashboard and Plugins screens, until
     * the pixel is switched on or the note is dismissed.
     */
    public function maybe_announce(): void
    {
        if (! current_user_can('manage_options') || $this->settings->is_switched_on()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || ! in_array($screen->id, ['dashboard', 'plugins'], true)) {
            return;
        }
        if (get_user_meta(get_current_user_id(), self::ANNOUNCE_META, true)) {
            return;
        }

        printf(
            '<div class="notice notice-info"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button-link" style="margin-left:8px" href="%s">%s</a></p></div>',
            esc_html__('New in FeedAgentX: a free ChatGPT Ads Pixel.', 'feedagentx-connect'),
            esc_html__('Measure the sales your ChatGPT ads bring in, with OpenAI\'s Measurement Pixel and Conversions API. No code and no FeedAgentX account needed.', 'feedagentx-connect'),
            esc_url(self::url()),
            esc_html__('Set it up in 2 minutes', 'feedagentx-connect'),
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=faxc_dismiss_pixel_announce'), 'faxc_dismiss_pixel_announce')),
            esc_html__('Dismiss', 'feedagentx-connect')
        );
    }

    public function add_privacy_policy_text(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $text = '<p class="privacy-policy-tutorial">' . esc_html__('Suggested text if you turn on the ChatGPT Ads Pixel in FeedAgentX. Adjust it to what you actually use.', 'feedagentx-connect') . '</p>'
            . '<p><strong class="privacy-policy-tutorial">' . esc_html__('Suggested text:', 'feedagentx-connect') . ' </strong>'
            . esc_html__('We use OpenAI\'s Measurement Pixel and Conversions API to measure how our ads in ChatGPT perform. With your consent, the pixel sets the first-party cookies __oppref (up to 30 days) and __obref (up to 365 days) and reports pages and products you view, items you add to your cart, and purchases. For purchases, our server also sends OpenAI the order value and products, your IP address and browser, and your email address, phone number, name and address. Email, phone and name are converted to irreversible SHA-256 hashes before they are sent; city, region, postcode and country are sent as they are. OpenAI uses this to attribute purchases to ads. See OpenAI\'s privacy policy at https://openai.com/policies/privacy-policy/.', 'feedagentx-connect')
            . '</p>';
        wp_add_privacy_policy_content(__('FeedAgentX – ChatGPT Ads Pixel', 'feedagentx-connect'), wp_kses_post($text));
    }

    // ───────────────────────── Render ─────────────────────────

    /** @param bool $review_asked the page already shows the review request */
    public function render(bool $review_asked = false): void
    {
        $activity = $this->settings->activity();

        // Before setup the page sells and guides; once live it reports,
        // and points at the next step: the product feed.
        if (! $this->settings->is_enabled()) {
            $this->render_intro();
            $this->render_steps();
            $this->render_form();
        } else {
            $this->render_status($activity);
            if (! $this->store->is_connected_to_app()) {
                $this->render_next_step();
            }
            $this->render_recent($activity);
            $this->render_test($activity);
            $this->render_form();
        }
        $this->render_events();
        $this->render_showcase();
        $this->render_privacy();
        if (! $review_asked) {
            $this->render_review_ask($activity);
        }
    }

    private function render_intro(): void
    {
        echo '<section class="faxc-card faxc-hero">';
        echo '<h2>' . esc_html__('See which sales your ChatGPT ads bring in', 'feedagentx-connect')
            . ' <span class="faxc-pill is-free">' . esc_html__('Free', 'feedagentx-connect') . '</span></h2>';
        echo '<p class="faxc-lead">' . esc_html__('Add OpenAI\'s Measurement Pixel and Conversions API to your WooCommerce store without code. It works on its own; you do not need a FeedAgentX account.', 'feedagentx-connect') . '</p>';
        echo '<ul class="faxc-benefits">';
        foreach ([
            [__('The whole shopping journey', 'feedagentx-connect'), __('product views, add to cart, checkout and purchases, with prices and products.', 'feedagentx-connect')],
            [__('Purchases from your server too', 'feedagentx-connect'), __('ad blockers and closed tabs no longer hide sales from OpenAI.', 'feedagentx-connect')],
            [__('Every sale counted once', 'feedagentx-connect'), __('the browser and your server share one event ID per order.', 'feedagentx-connect')],
            [__('Privacy first', 'feedagentx-connect'), __('follows your cookie banner, and hashes customer details before they leave your store.', 'feedagentx-connect')],
            [__('Lines up with your product feed', 'feedagentx-connect'), __('products are reported with the same IDs as a FeedAgentX feed.', 'feedagentx-connect')],
        ] as [$title, $body]) {
            echo '<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><span><strong>' . esc_html($title) . '</strong> — ' . esc_html($body) . '</span></li>';
        }
        echo '</ul>';
        echo '<p class="description">' . esc_html__('OpenAI uses these events to report conversions and return on ad spend, and to run campaigns that optimize for purchases.', 'feedagentx-connect') . '</p>';

        echo '<div class="faxc-callout">';
        if ($this->store->is_connected_to_app()) {
            echo '<p><strong>' . esc_html__('Your store is connected to FeedAgentX.', 'feedagentx-connect') . '</strong> '
                . esc_html__('FeedAgentX builds this store\'s product feed. If you use it in ChatGPT Ads, this pixel measures the sales your ads bring in.', 'feedagentx-connect') . '</p>';
        } else {
            echo '<p><strong>' . esc_html__('The pixel measures the sales. The product feed brings them.', 'feedagentx-connect') . '</strong> '
                . esc_html__('Product ads in ChatGPT are built from a product feed in OpenAI\'s format. FeedAgentX builds that feed from this store, free for up to 500 products.', 'feedagentx-connect') . '</p>';
        }
        $this->feed_buttons('intro');
        echo '</div>';
        echo '</section>';
    }

    private function render_steps(): void
    {
        echo '<section class="faxc-card">';
        echo '<h3>' . esc_html__('Set up in three steps', 'feedagentx-connect') . '</h3>';
        echo '<ol class="faxc-steps">';
        echo '<li><strong>' . esc_html__('Create a pixel.', 'feedagentx-connect') . '</strong> ' . esc_html__('In OpenAI Ads Manager, open the Conversions tab, create a pixel and copy its Pixel ID.', 'feedagentx-connect') . '</li>';
        echo '<li><strong>' . esc_html__('Create a Conversions API key (recommended).', 'feedagentx-connect') . '</strong> ' . esc_html__('In the same tab, create a key so purchases are also sent from your server.', 'feedagentx-connect') . '</li>';
        echo '<li><strong>' . esc_html__('Paste, save and test.', 'feedagentx-connect') . '</strong> ' . esc_html__('Enter both below, save, then send a test purchase.', 'feedagentx-connect') . '</li>';
        if ($this->store->is_connected_to_app()) {
            echo '<li><strong>' . esc_html__('Your product feed is in FeedAgentX.', 'feedagentx-connect') . '</strong> ' . esc_html__('This store is already connected to FeedAgentX. For ChatGPT Ads, add the feed URL from ChatGPT channels → Ads (Connection tab) to OpenAI Ads Manager.', 'feedagentx-connect') . '</li>';
        } else {
            printf(
                '<li><strong>%s</strong> %s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
                esc_html__('Set up a product feed for ChatGPT Ads (optional).', 'feedagentx-connect'),
                esc_html__('Connect this store to FeedAgentX to build a validated product feed in OpenAI\'s format. Free for up to 500 products.', 'feedagentx-connect'),
                esc_url(self::tracked(SettingsPage::app_url('sign-up'), 'steps')),
                esc_html__('Start free', 'feedagentx-connect')
            );
        }
        echo '</ol>';
        printf(
            '<p><a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a> <a class="button-link" style="margin-left:8px" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_url(self::ADS_MANAGER_URL),
            esc_html__('Open OpenAI Ads Manager', 'feedagentx-connect'),
            esc_url(SettingsPage::docs_url('chatgpt-ads-pixel/')),
            esc_html__('Read the setup guide', 'feedagentx-connect')
        );
        echo '</section>';
    }

    private function render_status(array $activity): void
    {
        $s = $this->settings;

        if ($s->is_enabled()) {
            $pixel = ['is-on', __('On', 'feedagentx-connect'), __('Measuring on every page of your store.', 'feedagentx-connect')];
        } elseif ($s->is_switched_on()) {
            $pixel = ['is-warn', __('Needs a Pixel ID', 'feedagentx-connect'), __('Add your Pixel ID below to start measuring.', 'feedagentx-connect')];
        } else {
            $pixel = ['is-off', __('Off', 'feedagentx-connect'), __('Nothing is added to your store yet.', 'feedagentx-connect')];
        }

        if ($s->is_capi_ready()) {
            $server = ['is-on', __('On', 'feedagentx-connect'), __('Paid orders are also sent from your server.', 'feedagentx-connect')];
        } elseif ($s->is_capi_switched_on()) {
            $server = ['is-warn', $s->get_capi_key() === '' ? __('Needs an API key', 'feedagentx-connect') : __('Needs the pixel', 'feedagentx-connect'), __('Recommended: it catches sales the browser misses.', 'feedagentx-connect')];
        } else {
            $server = ['is-off', __('Off', 'feedagentx-connect'), __('Recommended: it catches sales the browser misses.', 'feedagentx-connect')];
        }

        if (Consent::cmp_active()) {
            $consent = ['is-on', __('Following your cookie banner', 'feedagentx-connect'), __('A consent plugin using the WP Consent API decides. Nothing is measured without marketing consent.', 'feedagentx-connect')];
        } elseif ($s->consent_mode() === MeasurementSettings::CONSENT_REQUIRE) {
            $consent = ['is-warn', __('Waiting for consent', 'feedagentx-connect'), __('No visitor is measured until your banner calls FeedAgentXPixel.grantConsent().', 'feedagentx-connect')];
        } else {
            $consent = ['is-off', __('No banner detected', 'feedagentx-connect'), __('Every visitor is measured. If you need consent, use a banner that supports the WP Consent API or choose "Wait for consent" below.', 'feedagentx-connect')];
        }

        echo '<section class="faxc-card faxc-live">';
        echo '<h2>' . esc_html__('Your ChatGPT Ads Pixel is live', 'feedagentx-connect')
            . ' <span class="faxc-pill is-free">' . esc_html__('Free', 'feedagentx-connect') . '</span></h2>';
        echo '<table class="faxc-status"><tbody>';
        foreach ([
            [__('Pixel on your store', 'feedagentx-connect'), $pixel],
            [__('Server purchases (Conversions API)', 'feedagentx-connect'), $server],
            [__('Cookie consent', 'feedagentx-connect'), $consent],
        ] as [$label, [$class, $state, $hint]]) {
            printf(
                '<tr><th>%s</th><td><span class="faxc-pill %s">%s</span> <span class="description">%s</span></td></tr>',
                esc_html($label),
                esc_attr($class),
                esc_html($state),
                esc_html($hint)
            );
        }

        $summary = sprintf(
            /* translators: 1: purchases sent, 2: purchases that failed */
            __('%1$d sent · %2$d failed', 'feedagentx-connect'),
            $activity['sent'],
            $activity['failed']
        );
        if ($activity['last_sent_at']) {
            $summary .= ' · ' . sprintf(
                /* translators: %s: time since, e.g. "5 mins" */
                __('last one %s ago', 'feedagentx-connect'),
                human_time_diff($activity['last_sent_at'])
            );
        }
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Purchases sent from your server', 'feedagentx-connect'), esc_html($summary));

        $contact = $this->store->last_app_contact();
        if ($this->store->is_connected_to_app() && $contact !== null) {
            printf(
                '<tr><th>%s</th><td><span class="faxc-pill is-on">%s</span> <span class="description">%s</span> <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></td></tr>',
                esc_html__('Product feed (FeedAgentX)', 'feedagentx-connect'),
                esc_html__('Connected to FeedAgentX', 'feedagentx-connect'),
                esc_html(sprintf(
                    /* translators: %s: time since, e.g. "5 mins" */
                    __('Catalog last read %s ago.', 'feedagentx-connect'),
                    human_time_diff($contact)
                )),
                esc_url(self::tracked(SettingsPage::app_url('stores'), 'status')),
                esc_html__('Open FeedAgentX', 'feedagentx-connect')
            );
        } else {
            printf(
                '<tr><th>%s</th><td><span class="faxc-pill is-warn">%s</span> <span class="description">%s</span> <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></td></tr>',
                esc_html__('Product feed (FeedAgentX)', 'feedagentx-connect'),
                esc_html__('Not set up', 'feedagentx-connect'),
                esc_html__('The pixel measures sales; product ads need your catalog as a feed.', 'feedagentx-connect'),
                esc_url(self::tracked(SettingsPage::app_url('sign-up'), 'status')),
                esc_html__('Set it up with FeedAgentX', 'feedagentx-connect')
            );
        }
        echo '</tbody></table>';
        echo '</section>';
    }

    /** Live pixel, no feed: the obvious next step, right under the status. */
    private function render_next_step(): void
    {
        echo '<section class="faxc-card faxc-next">';
        echo '<h3>' . esc_html__('Next: set up your product feed for ChatGPT Ads', 'feedagentx-connect') . '</h3>';
        echo '<p>' . esc_html__('Your pixel is ready to measure sales. Product ads in ChatGPT need your catalog as a product feed in OpenAI\'s format. FeedAgentX builds and checks it from this store and gives you a hosted feed URL for Ads Manager.', 'feedagentx-connect') . '</p>';
        $this->feed_buttons('next-step');
        echo '<p class="description">' . esc_html__('Free for up to 500 products, no card needed. Paid plans for bigger catalogs start with a 14-day free trial.', 'feedagentx-connect') . '</p>';
        echo '</section>';
    }

    private function render_form(): void
    {
        $s = $this->settings;
        $hint = $s->capi_key_hint();

        echo '<section class="faxc-card">';
        echo '<h3>' . esc_html__('Settings', 'feedagentx-connect') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<input type="hidden" name="action" value="faxc_save_pixel" />';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Pixel', 'feedagentx-connect') . '</th><td>';
        echo '<label><input type="checkbox" name="pixel_enabled" value="1" ' . checked($s->is_switched_on(), true, false) . ' /> '
            . esc_html__('Add the OpenAI Measurement Pixel to my store', 'feedagentx-connect') . '</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="faxc-pixel-id">' . esc_html__('Pixel ID', 'feedagentx-connect') . '</label></th><td>';
        echo '<input id="faxc-pixel-id" type="text" name="pixel_id" class="regular-text code" autocomplete="off" spellcheck="false" value="'
            . esc_attr((string) get_option(MeasurementSettings::KEY_PIXEL_ID, '')) . '" placeholder="' . esc_attr__('Paste your Pixel ID', 'feedagentx-connect') . '" />';
        echo '<p class="description">' . esc_html__('From the Conversions tab in OpenAI Ads Manager. It is public: it appears in your store\'s pages.', 'feedagentx-connect') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Server purchases', 'feedagentx-connect') . '</th><td>';
        echo '<label><input type="checkbox" name="capi_enabled" value="1" ' . checked($s->is_capi_switched_on(), true, false) . ' /> '
            . esc_html__('Also send purchases from my server with the Conversions API (recommended)', 'feedagentx-connect') . '</label>';
        echo '<p class="description">' . esc_html__('Sent in the background when an order is paid, and retried if OpenAI cannot be reached.', 'feedagentx-connect') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="faxc-capi-key">' . esc_html__('Conversions API key', 'feedagentx-connect') . '</label></th><td>';
        echo '<input id="faxc-capi-key" type="password" name="capi_key" class="regular-text code" autocomplete="new-password" spellcheck="false" value="" placeholder="'
            . esc_attr($hint !== '' ? __('Saved — leave empty to keep it', 'feedagentx-connect') : __('Paste your Conversions API key', 'feedagentx-connect')) . '" />';
        if ($hint !== '') {
            echo '<p class="description">' . sprintf(
                /* translators: %s: last four characters of the saved key */
                esc_html__('A key ending in %s is saved.', 'feedagentx-connect'),
                '<code>' . esc_html($hint) . '</code>'
            ) . ' <label style="margin-left:8px"><input type="checkbox" name="capi_key_remove" value="1" /> ' . esc_html__('Remove it', 'feedagentx-connect') . '</label></p>';
        }
        echo '<p class="description">' . esc_html__('Kept on your server and never shown again. It can only send events: it cannot change your ad account or spend money.', 'feedagentx-connect') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Customer matching', 'feedagentx-connect') . '</th><td>';
        echo '<label><input type="checkbox" name="matching" value="1" ' . checked($s->sends_customer_data(), true, false) . ' /> '
            . esc_html__('Send hashed customer details to match more sales to ads (recommended)', 'feedagentx-connect') . '</label>';
        echo '<p class="description">' . esc_html__('Email, phone and name are normalized and turned into SHA-256 hashes on your server, so OpenAI never receives them in plain text. City, region, postcode and country are sent as they are, as OpenAI requires.', 'feedagentx-connect') . '</p>';
        echo '</td></tr>';

        $mode = $s->consent_mode();
        echo '<tr><th scope="row">' . esc_html__('Cookie consent', 'feedagentx-connect') . '</th><td><fieldset>';
        printf(
            '<label><input type="radio" name="consent_mode" value="%s" %s /> <strong>%s</strong> — %s</label><br />',
            esc_attr(MeasurementSettings::CONSENT_AUTO),
            checked($mode, MeasurementSettings::CONSENT_AUTO, false),
            esc_html__('Follow my cookie banner', 'feedagentx-connect'),
            esc_html__('if your consent plugin supports the WP Consent API, wait for its marketing consent; without such a banner, measure every visitor.', 'feedagentx-connect')
        );
        printf(
            '<label><input type="radio" name="consent_mode" value="%s" %s /> <strong>%s</strong> — %s</label>',
            esc_attr(MeasurementSettings::CONSENT_REQUIRE),
            checked($mode, MeasurementSettings::CONSENT_REQUIRE, false),
            esc_html__('Wait for consent', 'feedagentx-connect'),
            esc_html__('measure nobody until consent is given, through the WP Consent API or your banner calling FeedAgentXPixel.grantConsent().', 'feedagentx-connect')
        );
        echo '<p class="description">' . esc_html__('Without consent, OpenAI\'s script is not loaded, no cookies are set and no server purchase is sent.', 'feedagentx-connect') . '</p>';
        echo '</fieldset></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Testing', 'feedagentx-connect') . '</th><td>';
        echo '<label><input type="checkbox" name="pixel_debug" value="1" ' . checked($s->is_debug(), true, false) . ' /> '
            . esc_html__('Show pixel activity in the browser console', 'feedagentx-connect') . '</label>';
        echo '<p class="description">' . esc_html__('Turn this off when you are done testing.', 'feedagentx-connect') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button(__('Save pixel settings', 'feedagentx-connect'));
        echo '</form>';
        echo '</section>';
    }

    private function render_test(array $activity): void
    {
        echo '<section class="faxc-card">';
        echo '<h3>' . esc_html__('Test your setup', 'feedagentx-connect') . '</h3>';

        echo '<h4>' . esc_html__('Server connection', 'feedagentx-connect') . '</h4>';
        echo '<p>' . esc_html__('Sends a sample purchase to OpenAI to check your Pixel ID and Conversions API key. OpenAI only validates it; nothing is recorded in your account.', 'feedagentx-connect') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<input type="hidden" name="action" value="faxc_test_pixel" />';
        submit_button(
            __('Send a test purchase', 'feedagentx-connect'),
            'secondary',
            'submit',
            false,
            $this->settings->is_capi_ready() ? [] : ['disabled' => 'disabled']
        );
        echo '</form>';

        $test = $activity['test'];
        if (is_array($test)) {
            printf(
                '<p><span class="faxc-pill %s">%s</span> <span class="description">%s</span></p>',
                esc_attr($test['ok'] ? 'is-on' : 'is-bad'),
                esc_html($test['ok'] ? __('Accepted', 'feedagentx-connect') : __('Not accepted', 'feedagentx-connect')),
                esc_html(sprintf(
                    /* translators: 1: time since, 2: details from OpenAI */
                    __('Last test %1$s ago. %2$s', 'feedagentx-connect'),
                    human_time_diff((int) $test['at']),
                    (string) $test['message']
                ))
            );
        }

        if (is_array($test) && $test['ok'] && ! $this->store->is_connected_to_app()) {
            printf(
                '<p class="faxc-inline-next">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
                esc_html__('Your measurement works. Next step:', 'feedagentx-connect'),
                esc_url(self::tracked(SettingsPage::app_url('sign-up'), 'test-ok')),
                esc_html__('set up a product feed for ChatGPT Ads with FeedAgentX', 'feedagentx-connect')
            );
        }

        echo '<h4>' . esc_html__('Browser pixel', 'feedagentx-connect') . '</h4>';
        echo '<p>' . esc_html__('Turn on "Show pixel activity in the browser console", open your store and browse a product. Lines starting with [FeedAgentX pixel] show each event. Attributed conversions appear in your Ads Manager reports after OpenAI\'s daily processing.', 'feedagentx-connect') . '</p>';
        printf(
            '<p><a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_url(home_url('/')),
            esc_html__('Open your store', 'feedagentx-connect')
        );
        echo '</section>';
    }

    private function render_events(): void
    {
        echo '<section class="faxc-card">';
        echo '<h3>' . esc_html__('What is measured', 'feedagentx-connect') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Event', 'feedagentx-connect') . '</th>'
            . '<th>' . esc_html__('When', 'feedagentx-connect') . '</th>'
            . '<th>' . esc_html__('Sent from', 'feedagentx-connect') . '</th>'
            . '</tr></thead><tbody>';
        $browser = __('Browser', 'feedagentx-connect');
        foreach ([
            [__('Page view', 'feedagentx-connect'), 'page_viewed', __('Every page', 'feedagentx-connect'), $browser],
            [__('Product view', 'feedagentx-connect'), 'contents_viewed', __('A product page opens', 'feedagentx-connect'), $browser],
            [__('Add to cart', 'feedagentx-connect'), 'items_added', __('A product is added to the cart', 'feedagentx-connect'), $browser],
            [__('Checkout started', 'feedagentx-connect'), 'checkout_started', __('The checkout page opens', 'feedagentx-connect'), $browser],
            [__('Purchase', 'feedagentx-connect'), 'order_created', __('The thank-you page, and when the order is paid', 'feedagentx-connect'), __('Browser and your server, counted once', 'feedagentx-connect')],
        ] as [$label, $name, $when, $from]) {
            printf(
                '<tr><td><strong>%s</strong> <code>%s</code></td><td>%s</td><td>%s</td></tr>',
                esc_html($label),
                esc_html($name),
                esc_html($when),
                esc_html($from)
            );
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Prices and products are included. Orders entered in the admin and subscription renewals are not sent as purchases.', 'feedagentx-connect') . '</p>';
        printf(
            '<p class="faxc-inline-next">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_html__('Products are reported with the same IDs (wc-123) a FeedAgentX product feed uses, so ads, feed and measured sales line up.', 'feedagentx-connect'),
            esc_url(self::tracked($this->store->is_connected_to_app() ? SettingsPage::app_url('stores') : SettingsPage::app_url('sign-up'), 'events')),
            $this->store->is_connected_to_app() ? esc_html__('Open your feed', 'feedagentx-connect') : esc_html__('Create your feed', 'feedagentx-connect')
        );
        echo '</section>';
    }

    private function render_recent(array $activity): void
    {
        if ($activity['recent'] === []) {
            return;
        }
        $labels = [
            'sent' => ['is-on', __('Sent', 'feedagentx-connect')],
            'retrying' => ['is-warn', __('Retrying', 'feedagentx-connect')],
            'failed' => ['is-bad', __('Failed', 'feedagentx-connect')],
            'skipped' => ['is-off', __('Not sent', 'feedagentx-connect')],
        ];

        echo '<section class="faxc-card">';
        echo '<h3>' . esc_html__('Recent server purchases', 'feedagentx-connect') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Order', 'feedagentx-connect') . '</th>'
            . '<th>' . esc_html__('Result', 'feedagentx-connect') . '</th>'
            . '<th>' . esc_html__('When', 'feedagentx-connect') . '</th>'
            . '<th>' . esc_html__('Details', 'feedagentx-connect') . '</th>'
            . '</tr></thead><tbody>';
        foreach (array_slice($activity['recent'], 0, 10) as $row) {
            [$class, $label] = $labels[$row['status']] ?? ['is-off', (string) $row['status']];
            $order = function_exists('wc_get_order') ? wc_get_order((int) $row['order']) : null;
            $order_cell = $order instanceof \WC_Order
                ? sprintf('<a href="%s">#%s</a>', esc_url($order->get_edit_order_url()), esc_html($order->get_order_number()))
                : '#' . esc_html((string) $row['order']);
            printf(
                '<tr><td>%s</td><td><span class="faxc-pill %s">%s</span></td><td>%s</td><td>%s</td></tr>',
                $order_cell, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
                esc_attr($class),
                esc_html($label),
                esc_html(sprintf(
                    /* translators: %s: time since, e.g. "5 mins" */
                    __('%s ago', 'feedagentx-connect'),
                    human_time_diff((int) $row['at'])
                )),
                esc_html((string) $row['message'])
            );
        }
        echo '</tbody></table>';
        echo '</section>';
    }

    private function render_privacy(): void
    {
        echo '<section class="faxc-card">';
        echo '<h3>' . esc_html__('What OpenAI receives', 'feedagentx-connect') . '</h3>';
        echo '<ul class="faxc-list">';
        echo '<li>' . esc_html__('From the browser: the pages and products viewed, items added to the cart, checkout and purchases, with prices. OpenAI\'s script sets two first-party cookies, __oppref (ad click, up to 30 days) and __obref (browser, up to 365 days).', 'feedagentx-connect') . '</li>';
        echo '<li>' . esc_html__('From your server, for paid orders: order value, currency and products, the customer\'s IP address and browser, and, if customer matching is on, hashed email, phone and name with city, region, postcode and country.', 'feedagentx-connect') . '</li>';
        echo '<li>' . esc_html__('Nothing is sent to FeedAgentX. Payment details are never read or sent.', 'feedagentx-connect') . '</li>';
        echo '</ul>';
        printf(
            '<p class="description">%s <a href="%s">%s</a></p>',
            esc_html__('Suggested wording for your privacy policy is in the policy guide.', 'feedagentx-connect'),
            esc_url(admin_url('options-privacy.php?tab=policyguide')),
            esc_html__('Open the privacy policy guide', 'feedagentx-connect')
        );
        echo '</section>';
    }

    /** The FeedAgentX pitch, or the way back into it for a connected store. */
    private function render_showcase(): void
    {
        $connected = $this->store->is_connected_to_app();
        echo '<section class="faxc-card faxc-showcase">';
        if ($connected) {
            echo '<h2>' . esc_html__('Manage your product feed in FeedAgentX', 'feedagentx-connect') . '</h2>';
            echo '<p class="faxc-lead">' . esc_html__('FeedAgentX checks this store\'s product feed against OpenAI\'s rules and keeps it in sync with your catalog. Choose products for ChatGPT Ads and ChatGPT Business Hub, fix what OpenAI would reject and check your Ads Manager imports there.', 'feedagentx-connect') . '</p>';
        } else {
            echo '<h2>' . esc_html__('Get your products into ChatGPT Ads', 'feedagentx-connect') . '</h2>';
            echo '<p class="faxc-lead">' . esc_html__('The pixel measures what happens after the click. To be clicked, your products first have to be in ChatGPT Ads, and product ads are built from a product feed in OpenAI\'s format. FeedAgentX builds it from this store.', 'feedagentx-connect') . '</p>';
        }
        echo '<div class="faxc-tiles">';
        foreach ([
            ['dashicons-list-view', __('Your catalog in OpenAI\'s format', 'feedagentx-connect'), __('Every product and variation mapped to the columns of OpenAI\'s product feed specification.', 'feedagentx-connect')],
            ['dashicons-shield', __('Problems caught before the import', 'feedagentx-connect'), __('Missing brands, invalid GTINs, private images and variant issues show up before OpenAI rejects a row.', 'feedagentx-connect')],
            ['dashicons-admin-links', __('A hosted feed URL for each channel', 'feedagentx-connect'), __('Add it once in Ads Manager or Business Hub; it is fetched on the schedule you set there.', 'feedagentx-connect')],
            ['dashicons-update', __('Prices and stock kept current', 'feedagentx-connect'), __('Paid plans send price and availability changes through the Ads API between imports.', 'feedagentx-connect')],
        ] as [$icon, $title, $body]) {
            printf(
                '<div class="faxc-tile"><span class="dashicons %s" aria-hidden="true"></span><strong>%s</strong><span>%s</span></div>',
                esc_attr($icon),
                esc_html($title),
                esc_html($body)
            );
        }
        echo '</div>';
        if (! $connected) {
            echo '<p class="faxc-plans"><strong>' . esc_html__('Free for up to 500 products, no card needed.', 'feedagentx-connect') . '</strong> '
                . esc_html__('Paid plans for bigger catalogs start with a 14-day free trial.', 'feedagentx-connect') . '</p>';
        }
        $this->feed_buttons('showcase');
        if (! $connected) {
            printf(
                '<p class="description">%s <a href="%s">%s</a></p>',
                esc_html__('Already have an account?', 'feedagentx-connect'),
                esc_url(admin_url('admin.php?page=' . SettingsPage::FEED_SLUG)),
                esc_html__('Connect this store from the Product Feed page.', 'feedagentx-connect')
            );
        }
        echo '</section>';
    }

    private function render_review_ask(array $activity): void
    {
        if ($activity['sent'] > 0) {
            echo '<p class="faxc-review">' . sprintf(
                /* translators: %s: link to leave a review */
                esc_html__('Is the pixel working for you? %s helps other WooCommerce stores find it.', 'feedagentx-connect'),
                '<a href="' . esc_url(ReviewPrompt::REVIEW_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('A short review on WordPress.org', 'feedagentx-connect') . '</a>'
            ) . '</p>';
        }
    }

    public static function css(): string
    {
        return '
            .faxc-hero h2, .faxc-live h2 { margin-top: 0; font-size: 1.4em; }
            .faxc-live { border-left: 4px solid #00a32a; }
            .faxc-lead { font-size: 14px; margin: 0 0 12px; }
            .faxc-benefits { margin: 0 0 12px; }
            .faxc-benefits li { display: flex; gap: 6px; align-items: flex-start; margin-bottom: 6px; }
            .faxc-benefits .dashicons { color: #00a32a; flex: none; }
            .faxc-steps { margin-left: 20px; }
            .faxc-steps li { margin-bottom: 6px; }
            .faxc-list { list-style: disc; margin-left: 20px; }
            .faxc-status { border-collapse: collapse; width: 100%; }
            .faxc-status th { text-align: left; width: 240px; padding: 8px 12px 8px 0; vertical-align: top; font-weight: 600; }
            .faxc-status td { padding: 8px 0; }
            .faxc-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; line-height: 1.6; background: #f0f0f1; color: #50575e; white-space: nowrap; }
            .faxc-pill.is-on { background: #edfaef; color: #00711f; }
            .faxc-pill.is-warn { background: #fcf9e8; color: #8a6d00; }
            .faxc-pill.is-bad { background: #fcf0f1; color: #b32d2e; }
            .faxc-pill.is-free { background: #2271b1; color: #fff; vertical-align: middle; }
            .faxc-card h4 { margin: 16px 0 4px; }
            .faxc-review { color: #646970; }
            .faxc-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0 4px; }
            .faxc-actions .button-link { margin-left: 4px; }
            .faxc-callout { background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 12px 16px; margin-top: 16px; }
            .faxc-callout p:first-child { margin-top: 0; }
            .faxc-next { border-left: 4px solid #2271b1; background: #f6fbff; }
            .faxc-next h3 { font-size: 1.15em; }
            .faxc-inline-next { background: #f6fbff; border-left: 3px solid #2271b1; padding: 8px 12px; }
            .faxc-showcase { border-top: 4px solid #2271b1; }
            .faxc-showcase h2 { margin-top: 0; font-size: 1.4em; }
            .faxc-tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
            .faxc-tile { display: flex; flex-direction: column; gap: 4px; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 14px; background: #fcfcfc; }
            .faxc-tile .dashicons { color: #2271b1; font-size: 22px; width: 22px; height: 22px; }
            .faxc-tile span:last-child { color: #50575e; }
            .faxc-plans { font-size: 14px; }
            @media (max-width: 782px) { .faxc-status th { width: auto; display: block; } .faxc-status td { display: block; } .faxc-tiles { grid-template-columns: 1fr; } }
        ';
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

    private function redirect(string $msg): never
    {
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'faxc_msg' => $msg], admin_url('admin.php')));
        exit;
    }
}
