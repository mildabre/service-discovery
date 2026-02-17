<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\DI;

use LogicException;
use Mildabre\ServiceDiscovery\Attributes\EventListener;
use Mildabre\ServiceDiscovery\Attributes\Service;
use Mildabre\ServiceDiscovery\Attributes\Excluded;
use Mildabre\ServiceDiscovery\Attributes\Autowire;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Extensions\InjectExtension;
use Nette\Loaders\RobotLoader;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

final class ServiceDiscoveryExtension extends CompilerExtension
{
    private const CacheFolder = '/service-discovery';
    public const TagEventListener = 'event.listener';

    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'in' => Expect::arrayOf('string')->default([]),
            'type' => Expect::arrayOf('string')->default([]),
            'enableInject' => Expect::arrayOf('string')->default([]),
            'lazy' => Expect::arrayOf('string')->default([]),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        $config = $this->getConfig();
        $definitions = [];

        if ($config->lazy && PHP_VERSION_ID < 80400) {
            throw new LogicException(self::class . ", configured lazy creation requires PHP 8.4 or newer. You are running " . PHP_VERSION);
        }

        foreach ($this->searchClasses($config->in) as $class) {
            try {
                $rc = new ReflectionClass($class);

            } catch (ReflectionException) {
                continue;
            }

            if ($rc->isAbstract() || $rc->isInterface() || $rc->getAttributes(Excluded::class) || $builder->findByType($class)) {
                continue;
            }

            $attribute = $rc->getAttributes(Service::class)[0] ?? null;
            if ($attribute) {
                $instance = $attribute->newInstance();
                $def = $builder->addDefinition($instance->name)
                    ->setType($class);
                $definitions[] = [$rc, $def];
                continue;
            }

            $attribute = $rc->getAttributes(EventListener::class)[0] ?? null;
            if ($attribute) {
                $def = $builder->addDefinition(null)
                    ->setType($class)
                    ->addTag(self::TagEventListener);
                $definitions[] = [$rc, $def];
                continue;
            }

            foreach ($config->type as $type) {
                if ($this->isClassOfType($rc, $type)) {
                    $def = $builder->addDefinition(null)
                        ->setType($class);
                    $definitions[] = [$rc, $def];
                    continue 2;
                }
            }
        }

        foreach ($definitions as [$rc, $def]) {
            $this->applyInject($rc, $def, $config);
            $this->applyLazyByType($rc, $def, $config);
            $this->applyLazyByAttribute($rc, $def);
            $this->applyAutowire($rc, $def);
        }
    }

    private function applyLazyByAttribute(ReflectionClass $rc, ServiceDefinition $def): void
    {
        $attribute = $rc->getAttributes(Service::class)[0] ?? null;
        if (!$attribute) {
            return;
        }

        if (PHP_VERSION_ID < 80400) {
            return;
        }

        $instance = $attribute->newInstance();
        if ($instance->lazy !== null) {
            $def->lazy = $instance->lazy;
        }
    }

    private function applyLazyByType(ReflectionClass $rc, ServiceDefinition $def, object $config): void
    {
        foreach ($config->lazy as $type) {
            if ($this->isClassOfType($rc, $type)) {
                $def->lazy = true;
                break;
            }
        }
    }

    private function applyInject(ReflectionClass $rc, ServiceDefinition $def, object $config): void
    {
        foreach ($config->enableInject as $type) {
            if ($this->isClassOfType($rc, $type)) {
                $def->addTag(InjectExtension::TagInject, true);
            }
        }
    }

    private function applyAutowire(ReflectionClass $rc, ServiceDefinition $def): void
    {
        $attribute = $this->getAttribute($rc, Autowire::class);
        if ($attribute) {
            $def->setAutowired($attribute->newInstance()->enabled);
        }
    }

    private function isClassOfType(ReflectionClass $rc, string $type): bool
    {
        $isInterface = interface_exists($type);
        return $isInterface && $rc->implementsInterface($type) || !$isInterface && $rc->isSubclassOf($type);
    }

    private function searchClasses(array $dirs): array
    {
        $loader = new RobotLoader;

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                throw new RuntimeException("Discovery directory '$dir' does not exists.");
            }

            $loader->addDirectory($dir);
        }

        $builder = $this->getContainerBuilder();
        $tempDir = $builder->parameters['tempDir'] . self::CacheFolder;
        $loader->setTempDirectory($tempDir);
        $loader->rebuild();

        return array_keys($loader->getIndexedClasses());
    }

    private function getAttribute(ReflectionClass $rc, string $class): ?ReflectionAttribute
    {
        while ($rc) {
            $attributes = $rc->getAttributes($class);
            if ($attributes) {
                if ($rc->isAbstract()) {
                    throw new LogicException(sprintf("%s, attribute '%s' cannot be used on abstract class.", $rc->name, $class));
                }

                return $attributes[0] ?? null;
            }

            $rc = $rc->getParentClass();
        };

        return null;
    }
}
