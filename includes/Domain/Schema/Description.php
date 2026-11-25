<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

/**
 * ACP Description object: at least one of plain/html/markdown must be present.
 */
final class Description
{
    public function __construct(
        public readonly ?string $plain = null,
        public readonly ?string $html = null,
        public readonly ?string $markdown = null,
    ) {
    }

    public function is_empty(): bool
    {
        return $this->plain === null && $this->html === null && $this->markdown === null;
    }

    public function to_array(): array
    {
        $out = [];
        if ($this->plain !== null) {
            $out['plain'] = $this->plain;
        }
        if ($this->html !== null) {
            $out['html'] = $this->html;
        }
        if ($this->markdown !== null) {
            $out['markdown'] = $this->markdown;
        }
        return $out;
    }
}
