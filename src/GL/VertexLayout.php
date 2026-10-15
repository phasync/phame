<?php

namespace Phame\GL;

/** The attributes of one interleaved vertex: where each starts, and the stride. Build it with add(), in location order. */
final class VertexLayout
{
    /** @var list<array{location: int, size: int, type: int, normalized: bool, offset: int}> */
    public private(set) array $attributes = [];
    public private(set) int $stride = 0;

    private const TYPE_SIZE = [GL::FLOAT => 4, GL::UNSIGNED_BYTE => 1];

    public function add(int $location, int $components, int $type = GL::FLOAT, bool $normalized = false): self
    {
        $this->attributes[] = ['location' => $location, 'size' => $components, 'type' => $type, 'normalized' => $normalized, 'offset' => $this->stride];
        $this->stride += $components * self::TYPE_SIZE[$type];
        return $this;
    }
}
