<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
class Service
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?bool $lazy = null,
    )
    {
        if ($this->name === '') {
            throw new InvalidArgumentException("Empty string is not valid service name.");
        }
    }
}