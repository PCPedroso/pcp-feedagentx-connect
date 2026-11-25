<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

/**
 * ACP Availability. Allowed `status` values per the flat (file-upload) spec:
 *   in_stock, out_of_stock, pre_order, backorder, unknown
 */
final class Availability
{
    public const IN_STOCK = 'in_stock';
    public const OUT_OF_STOCK = 'out_of_stock';
    public const BACKORDER = 'backorder';
    public const PRE_ORDER = 'pre_order';
    public const UNKNOWN = 'unknown';

    public const ALLOWED_STATUSES = [
        self::IN_STOCK,
        self::OUT_OF_STOCK,
        self::BACKORDER,
        self::PRE_ORDER,
        self::UNKNOWN,
    ];

    public function __construct(
        public readonly ?bool $available = null,
        public readonly ?string $status = null,
    ) {
    }

    public function to_array(): array
    {
        $out = [];
        if ($this->available !== null) {
            $out['available'] = $this->available;
        }
        if ($this->status !== null) {
            $out['status'] = $this->status;
        }
        return $out;
    }
}
