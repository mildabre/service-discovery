<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Autowire
{
    public function __construct(public readonly bool $enabled = true)
    {}
}