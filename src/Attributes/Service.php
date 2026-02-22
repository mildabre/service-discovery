<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Service
{
    public function __construct(
        public readonly bool $lazy = true,
    )
    {}
}