<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

/**
 * File-based logger with daily rotation.
 *
 * Writes to wp-content/uploads/feedagentx-logs/feedagentx-YYYY-MM-DD.log.
 * Directory is protected from direct web access via .htaccess.
 *
 * Static facade is available (Logger::info, Logger::error) for convenience
 * when DI is not yet wired.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const LEVEL_WEIGHTS = [
        self::DEBUG => 0,
        self::INFO => 1,
        self::WARNING => 2,
        self::ERROR => 3,
    ];

    private static ?self $instance = null;

    private string $log_dir;
    private string $min_level;
    private bool $enabled;
    private bool $directory_ready = false;

    public function __construct(?string $log_dir = null, ?string $min_level = null, ?bool $enabled = null)
    {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $log_dir ?? trailingslashit($upload_dir['basedir']) . 'feedagentx-logs';
        $this->min_level = $min_level ?? (string) get_option('faxc_log_level', self::INFO);
        $this->enabled = $enabled ?? (bool) get_option('faxc_log_enabled', true);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function set_instance(self $instance): void
    {
        self::$instance = $instance;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::instance()->write(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::instance()->write(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::instance()->write(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::instance()->write(self::ERROR, $message, $context);
    }

    public function write(string $level, string $message, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }
        if (! isset(self::LEVEL_WEIGHTS[$level])) {
            return;
        }
        if (self::LEVEL_WEIGHTS[$level] < (self::LEVEL_WEIGHTS[$this->min_level] ?? 1)) {
            return;
        }

        if (! $this->ensure_directory()) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s', 1);
        $context_json = $context ? ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $entry = sprintf("[%s] %s: %s%s\n", $timestamp, strtoupper($level), $message, $context_json);

        $file = $this->log_dir . '/feedagentx-' . current_time('Y-m-d', 1) . '.log';

        // Suppress errors — logger must never break the request.
        @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Delete log files older than $retention_days. Returns count deleted.
     */
    public function rotate(int $retention_days): int
    {
        if (! is_dir($this->log_dir)) {
            return 0;
        }

        $threshold = time() - max(1, $retention_days) * DAY_IN_SECONDS;
        $deleted = 0;

        $files = glob($this->log_dir . '/feedagentx-*.log') ?: [];
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                wp_delete_file($file);
                if (! file_exists($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Returns log file paths sorted newest first.
     *
     * @return string[]
     */
    public function get_log_files(): array
    {
        if (! is_dir($this->log_dir)) {
            return [];
        }
        $files = glob($this->log_dir . '/feedagentx-*.log') ?: [];
        rsort($files);
        return $files;
    }

    public function get_log_dir(): string
    {
        return $this->log_dir;
    }

    public function set_min_level(string $level): void
    {
        if (isset(self::LEVEL_WEIGHTS[$level])) {
            $this->min_level = $level;
        }
    }

    public function set_enabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    private function ensure_directory(): bool
    {
        if ($this->directory_ready) {
            return true;
        }

        if (! is_dir($this->log_dir)) {
            if (! wp_mkdir_p($this->log_dir)) {
                return false;
            }
        }

        // Apache 2.4 + 2.2 compatible deny-all.
        $htaccess = $this->log_dir . '/.htaccess';
        if (! file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"
            );
        }

        // IIS — equivalent deny-all rule.
        $webconfig = $this->log_dir . '/web.config';
        if (! file_exists($webconfig)) {
            @file_put_contents(
                $webconfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration>\n"
                . "  <system.webServer>\n"
                . "    <authorization>\n"
                . "      <deny users=\"*\" />\n"
                . "    </authorization>\n"
                . "  </system.webServer>\n"
                . "</configuration>\n"
            );
        }

        // Prevent directory listing on hosts that ignore .htaccess.
        $index = $this->log_dir . '/index.php';
        if (! file_exists($index)) {
            @file_put_contents($index, "<?php // Silence is golden\n");
        }

        $this->directory_ready = true;
        return true;
    }
}
