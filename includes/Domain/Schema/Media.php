<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

final class Media
{
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_MODEL_3D = 'model_3d';

    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly ?string $alt_text = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
    }

    public function to_array(): array
    {
        $out = [
            'type' => $this->type,
            'url' => $this->url,
        ];
        if ($this->alt_text !== null) {
            $out['alt_text'] = $this->alt_text;
        }
        if ($this->width !== null) {
            $out['width'] = $this->width;
        }
        if ($this->height !== null) {
            $out['height'] = $this->height;
        }
        return $out;
    }
}
