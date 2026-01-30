<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\DI;

use Bite\Exceptions\NotFound\FileNotFoundException;
use Mildabre\ServiceDiscovery\Attributes\AsService;
use Mildabre\ServiceDiscovery\Attributes\EnableInject;
use Mildabre\ServiceDiscovery\Attributes\Excluded;
use Nette\DI\CompilerExtension;
use Nette\DI\Extensions\InjectExtension;
use Nette\Loaders\RobotLoader;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use ReflectionClass;
use ReflectionException;


final class ServiceDiscoveryExtension extends CompilerExtension
{
    private const string Prefix = 'discovered';
    private const string CacheFolder = '/service-discovery';

    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'in' => Expect::arrayOf('string')->default([]),
            'extends' => Expect::arrayOf('string')->default([]),
            'implements' => Expect::arrayOf('string')->default([]),
            'stripPrefixes' => Expect::arrayOf('string')->default([]),
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

            if ($rc->getAttributes(AsService::class)) {
                $name = $this->createServiceName($class, $config->stripPrefixes);
                $definition = $builder->addDefinition($name)->setType($class);
                $definitions[] = [$rc, $definition];
                continue;
            }

            foreach ($config->extends as $type) {
                if ($rc->isSubclassOf($type)) {
                    $name = $this->createServiceName($class, $config->stripPrefixes);
                    $definition = $builder->addDefinition($name)->setType($class);
                    $definitions[] = [$rc, $definition];
                    continue 2;
                }
            }

            foreach ($config->implements as $interface) {
                if ($rc->implementsInterface($interface)) {
                    $name = $this->createServiceName($class, $config->stripPrefixes);
                    $definition = $builder->addDefinition($name)->setType($class);
                    $definitions[] = [$rc, $definition];
                    continue 2;
                }
            }
        }

        foreach ($definitions as [$rc, $definition]) {
            if ($this->hasAttributeRecursive($rc, EnableInject::class)) {
                $definition->addTag(InjectExtension::TagInject, true);
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

    private function createServiceName(string $class, array $stripPrefixes): string
    {
        foreach ($stripPrefixes as $prefix) {
            $prefix = trim($prefix, '\\');
            if (str_starts_with($class, $prefix . '\\')) {
                $class = substr($class, strlen($prefix) + 1);
                break;
            }
        }

        $parts = explode('\\', $class);
        $last = array_pop($parts);
        $parts = array_map(function ($p) {
            return lcfirst($p);
        }, $parts);

        $service = implode('.', $parts);

        if ($service !== '') {
            $service .= '.' . $last;

        } else {
            $service = $last;
        }

        return self::Prefix . '.' . $service;
    }

    private function hasAttributeRecursive(ReflectionClass $rc, string $attribute): bool
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
