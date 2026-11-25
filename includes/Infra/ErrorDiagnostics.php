<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

/**
 * Describes an unexpected error so FeedAgentX can tell which code on the
 * store failed, without WP_DEBUG and without reading the server's logs.
 *
 * Only the authenticated FeedAgentX connection receives this (every route
 * checks the read token first). Paths are made relative to the WordPress
 * root, and the message is shortened; nothing from the request is added.
 */
final class ErrorDiagnostics
{
    private const MAX_MESSAGE = 300;
    private const MAX_PLUGINS = 5;
    private const SELF_SLUG = 'feedagentx-connect';

    /**
     * @return array{exception:string, message:string, source:string, at:string, plugins:array<int,string>}
     */
    public static function describe(\Throwable $e): array
    {
        $plugins = [];
        $culprit = null;
        foreach (self::locations($e) as $file) {
            $source = self::source_of($file);
            if (str_starts_with($source, 'plugin:') || str_starts_with($source, 'mu-plugin:') || str_starts_with($source, 'theme:')) {
                $plugins[$source] = true;
                // The innermost code that is neither ours nor WooCommerce is
                // the likeliest culprit: a hook that ran inside our call.
                if ($culprit === null && ! in_array($source, ['plugin:' . self::SELF_SLUG, 'plugin:woocommerce'], true)) {
                    $culprit = $source;
                }
            }
        }

        return [
            'exception' => $e::class,
            'message' => self::clean($e->getMessage()),
            // Where it was thrown, unless a third-party frame is on the way.
            'source' => $culprit ?? self::source_of($e->getFile()),
            'at' => self::relative($e->getFile()) . ':' . $e->getLine(),
            'plugins' => array_slice(array_keys($plugins), 0, self::MAX_PLUGINS),
        ];
    }

    /**
     * "plugin:<slug>", "mu-plugin:<file>", "theme:<slug>", "wordpress" or "other".
     */
    public static function source_of(string $file): string
    {
        $file = self::normalise($file);
        foreach ([
            'plugin' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : null,
            'mu-plugin' => defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : null,
            'theme' => function_exists('get_theme_root') ? get_theme_root() : null,
        ] as $kind => $root) {
            if (! is_string($root) || $root === '') {
                continue;
            }
            $root = rtrim(self::normalise($root), '/') . '/';
            if (str_starts_with($file, $root)) {
                $rest = substr($file, strlen($root));
                $slug = strtok($rest, '/');
                if ($slug !== false && $slug !== '') {
                    return $kind . ':' . preg_replace('/\.php$/', '', $slug);
                }
            }
        }
        if (defined('ABSPATH')) {
            $abs = rtrim(self::normalise(ABSPATH), '/') . '/';
            if (str_starts_with($file, $abs . 'wp-includes/') || str_starts_with($file, $abs . 'wp-admin/')) {
                return 'wordpress';
            }
        }
        return 'other';
    }

    /** @return array<int, string> Throw location first, then callers. */
    private static function locations(\Throwable $e): array
    {
        $files = [$e->getFile()];
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && is_string($frame['file'])) {
                $files[] = $frame['file'];
            }
        }
        return $files;
    }

    private static function clean(string $message): string
    {
        if (defined('ABSPATH')) {
            $message = str_replace([rtrim(ABSPATH, '/\\') . '/', rtrim(self::normalise(ABSPATH), '/') . '/'], '', $message);
        }
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));
        if (function_exists('mb_substr')) {
            return mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE ? mb_substr($message, 0, self::MAX_MESSAGE - 1, 'UTF-8') . '…' : $message;
        }
        return strlen($message) > self::MAX_MESSAGE ? substr($message, 0, self::MAX_MESSAGE - 1) . '…' : $message;
    }

    private static function relative(string $file): string
    {
        $file = self::normalise($file);
        if (defined('ABSPATH')) {
            $abs = rtrim(self::normalise(ABSPATH), '/') . '/';
            if (str_starts_with($file, $abs)) {
                return substr($file, strlen($abs));
            }
        }
        if (defined('WP_CONTENT_DIR')) {
            $content = rtrim(self::normalise(WP_CONTENT_DIR), '/') . '/';
            if (str_starts_with($file, $content)) {
                return 'wp-content/' . substr($file, strlen($content));
            }
        }
        return basename($file);
    }

    private static function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
