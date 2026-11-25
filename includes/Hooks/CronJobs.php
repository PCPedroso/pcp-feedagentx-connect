<?php

namespace FeedAgentX\Hooks;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\ChangeLogRepository;
use FeedAgentX\Infra\Logger;
use FeedAgentX\Infra\SettingsRepository;

/**
 * Daily maintenance cron: rotates log files and prunes the change log.
 */
final class CronJobs
{
    public const DAILY_HOOK = 'faxc_daily_maintenance';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ChangeLogRepository $change_log,
        private readonly Logger $logger,
    ) {
    }

    public function register(): void
    {
        add_action(self::DAILY_HOOK, [$this, 'run_daily']);
    }

    public function run_daily(): void
    {
        $log_retention = $this->settings->get_log_retention_days();
        $log_deleted = $this->logger->rotate($log_retention);

        $change_retention = $this->settings->get_change_log_retention_days();
        $change_deleted = $this->change_log->purge_older_than($change_retention);

        Logger::info('Daily maintenance ran.', [
            'log_files_deleted' => $log_deleted,
            'change_log_rows_deleted' => $change_deleted,
        ]);
    }
}
