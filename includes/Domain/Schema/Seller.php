<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

final class Seller
{
    /**
     * @param Link[] $links
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $url = null,
        public readonly array $links = [],
    ) {
    }

    public function to_array(): array
    {
        $out = [];
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }
        if (! empty($this->links)) {
            $out['links'] = array_map(static fn (Link $l): array => $l->to_array(), $this->links);
        }
        return $out;
    }
}
