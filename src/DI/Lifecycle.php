<?php

declare(strict_types=1);

namespace Bite\ServiceDiscovery\DI;

enum Lifecycle: string
{
    case Singleton = 'singleton';
    case Transient = 'transient';
}