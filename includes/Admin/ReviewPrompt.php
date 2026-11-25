<?php

namespace FeedAgentX\Admin;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\MeasurementSettings;
use FeedAgentX\Infra\SettingsRepository;

/**
 * One request for a WordPress.org review, on the plugin's own pages only.
 *
 * It waits for proof that the plugin is doing its job and for a week of
 * use, so nobody is asked before they can judge it:
 *
 *   - the feed side works when FeedAgentX has read this catalog recently
 *     (the same "Connected" the Product Feed page shows); or
 *   - the pixel works when it is live and OpenAI accepted a test purchase
 *     or a real server purchase, with no failure since.
 *
 * The plugin cannot see whether OpenAI downloaded or processed a feed, so
 * that is not a signal here.
 *
 * Dismissing, or following the review link, is remembered per user and
 * the request never returns. Nothing is offered in exchange for a review.
 */
final class ReviewPrompt
{
    public const DISMISS_META = 'faxc_review_prompt_dismissed';
    public const REVIEW_URL = 'https://wordpress.org/support/plugin/feedagentx-connect/reviews/#new-post';
    public const SUPPORT_FORUM_URL = 'https://wordpress.org/support/plugin/feedagentx-connect/';

    /** Never ask during the first week on a site. */
    public const MIN_AGE = 7 * DAY_IN_SECONDS;

    private const ACTION = 'faxc_dismiss_review_prompt';

    public function __construct(
        private readonly SettingsRepository $store,
        private readonly MeasurementSettings $pixel,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle_dismiss']);
    }

    public function is_due(?int $now = null): bool
    {
        if (! current_user_can('manage_options')) {
            return false;
        }
        if (get_user_meta(get_current_user_id(), self::DISMISS_META, true)) {
            return false;
        }
        if (($now ?? time()) - $this->store->installed_at() < self::MIN_AGE) {
            return false;
        }
        return $this->success_signal() !== null;
    }

    /** @return 'feed'|'pixel'|null what is working, feed first */
    public function success_signal(): ?string
    {
        if ($this->store->is_connected_to_app()) {
            return 'feed';
        }
        if (! $this->pixel->is_enabled()) {
            return null;
        }
        $activity = $this->pixel->activity();
        $test = $activity['test'];
        if ($test !== null && empty($test['ok'])) {
            // The merchant's latest check failed: not the moment to ask.
            return null;
        }
        $last_ok_at = max(
            $activity['sent'] > 0 ? (int) $activity['last_sent_at'] : 0,
            $test !== null ? (int) ($test['at'] ?? 0) : 0
        );
        $last_error_at = (int) ($activity['last_error']['at'] ?? 0);
        return $last_ok_at > 0 && $last_ok_at >= $last_error_at ? 'pixel' : null;
    }

    /** Prints the request if it is due. Returns whether it was printed. */
    public function maybe_render(): bool
    {
        if (! $this->is_due()) {
            return false;
        }
        $headline = $this->success_signal() === 'feed'
            ? __('FeedAgentX is reading your catalog.', 'feedagentx-connect')
            : __('Your pixel is live and working.', 'feedagentx-connect');

        printf(
            '<div class="notice notice-info faxc-review-prompt"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">%s</a> <a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a> <a class="button-link" style="margin-left:8px" href="%s">%s</a></p></div>',
            esc_html($headline),
            esc_html__('If the plugin is useful to you, an honest review on WordPress.org helps other WooCommerce stores find it. If something is not working, tell us in the support forum instead and we will look into it.', 'feedagentx-connect'),
            esc_url($this->action_url('review')),
            esc_html__('Write a review', 'feedagentx-connect'),
            esc_url(self::SUPPORT_FORUM_URL),
            esc_html__('Support forum', 'feedagentx-connect'),
            esc_url($this->action_url('dismiss')),
            esc_html__('Don\'t show this again', 'feedagentx-connect')
        );
        return true;
    }

    /**
     * Both links record the dismissal; "review" then goes on to
     * WordPress.org, so someone who has just reviewed is not asked again.
     */
    public function handle_dismiss(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'feedagentx-connect'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        update_user_meta(get_current_user_id(), self::DISMISS_META, time());

        $go = isset($_GET['faxc_go']) ? sanitize_key((string) $_GET['faxc_go']) : '';
        if ($go === 'review') {
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed WordPress.org address, not user input
            wp_redirect(self::REVIEW_URL);
            exit;
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . SettingsPage::FEED_SLUG));
        exit;
    }

    private function action_url(string $go): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION . '&faxc_go=' . $go), self::ACTION);
    }
}
