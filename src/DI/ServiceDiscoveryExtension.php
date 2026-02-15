<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\DI;

use LogicException;
use Mildabre\ServiceDiscovery\Attributes\EventListener;
use Mildabre\ServiceDiscovery\Attributes\Service;
use Mildabre\ServiceDiscovery\Attributes\Excluded;
use Mildabre\ServiceDiscovery\Attributes\Autowire;
use Nette\DI\CompilerExtension;
use Nette\DI\Extensions\InjectExtension;
use Nette\Loaders\RobotLoader;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
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
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        $config = $this->getConfig();
        $definitions = [];

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
                $isInterface = interface_exists($type);
                if ($isInterface  && $rc->implementsInterface($type) || !$isInterface && $rc->isSubclassOf($type)) {
                    $name = null;
                    $def = $builder->addDefinition($name)
                        ->setType($class);
                    $definitions[] = [$rc, $def];
                    continue 2;
                }
            }
        }

        foreach ($definitions as [$rc, $def]) {

            foreach ($config->enableInject as $type) {
                $isInterface = interface_exists($type);
                if ($isInterface  && $rc->implementsInterface($type) || !$isInterface && $rc->isSubclassOf($type)) {
                    $def->addTag(InjectExtension::TagInject, true);
                }
            }

            $autowireAttribute = $rc->getAttributes(Autowire::class)[0] ?? null;
            if ($autowireAttribute) {
                $def->setAutowired($autowireAttribute->newInstance()->enabled);
            }
        }
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

    private function hasAttribute(ReflectionClass $rc, string $attribute): bool
    {
        while ($rc) {
            if ($rc->getAttributes($attribute)) {
                if ($rc->isAbstract()) {
                    throw new LogicException(sprintf("%s, attribute '%s' cannot be used on abstract class.", $rc->name, $attribute));
                }

                return true;
            }

            $rc = $rc->getParentClass();
        };

        return false;
    }
}
