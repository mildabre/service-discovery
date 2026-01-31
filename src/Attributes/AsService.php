<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\Attributes;

use Attribute;
use Nette\InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
class AsService
{
    public function __construct(public readonly ?string $name = null)
    {
        if ($this->name === '') {
            throw new InvalidArgumentException("Empty string is not valid service name.");
        }
    }
}