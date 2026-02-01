<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\DI;

use Bite\Exceptions\NotFound\FileNotFoundException;
use Mildabre\ServiceDiscovery\Attributes\AsEventListener;
use Mildabre\ServiceDiscovery\Attributes\AsService;
use Mildabre\ServiceDiscovery\Attributes\EnableInject;
use Mildabre\ServiceDiscovery\Attributes\Excluded;
use Mildabre\ServiceDiscovery\Attributes\NoAutowire;
use Nette\DI\CompilerExtension;
use Nette\DI\Extensions\InjectExtension;
use Nette\Loaders\RobotLoader;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use ReflectionClass;
use ReflectionException;

final class ServiceDiscoveryExtension extends CompilerExtension
{
    private const string CacheFolder = '/service-discovery';
    public const string TagEventListener = 'event.listener';

    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'in' => Expect::arrayOf('string')->default([]),
            'extends' => Expect::arrayOf('string')->default([]),
            'implements' => Expect::arrayOf('string')->default([]),
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

            if ($rc->isAbstract() || $rc->getAttributes(Excluded::class) || $rc->isInterface() || $builder->findByType($class)) {
                continue;
            }

            $attribute = $rc->getAttributes(AsService::class)[0] ?? null;
            if ($attribute) {
                $instance = $attribute->newInstance();
                $def = $builder->addDefinition($instance->name)
                    ->setType($class);
                $definitions[] = [$rc, $def];
                continue;
            }

            $attribute = $rc->getAttributes(AsEventListener::class)[0] ?? null;
            if ($attribute) {
                $def = $builder->addDefinition(null)
                    ->setType($class)
                    ->addTag(self::TagEventListener);
                $definitions[] = [$rc, $def];
                continue;
            }

            foreach ($config->extends as $type) {
                if ($rc->isSubclassOf($type)) {
                    $name = null;
                    $def = $builder->addDefinition($name)
                        ->setType($class);
                    $definitions[] = [$rc, $def];
                    continue 2;
                }
            }

            foreach ($config->implements as $interface) {
                if ($rc->implementsInterface($interface)) {
                    $name = null;
                    $def = $builder->addDefinition($name)
                        ->setType($class);
                    $definitions[] = [$rc, $def];
                    continue 2;
                }
            }
        }

        foreach ($definitions as [$rc, $def]) {
            if ($this->hasAttribute($rc, EnableInject::class)) {
                $def->addTag(InjectExtension::TagInject, true);
            }

            if ($rc->getAttributes(NoAutowire::class)) {
                $def->setAutowired(false);
            }
        }
    }

    private function searchClasses(array $dirs): array
    {
        $loader = new RobotLoader;

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                throw new FileNotFoundException("Discovery directory '$dir' does not exists.");
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
                return true;
            }
            $rc = $rc->getParentClass();
        }

        return false;
    }
}
