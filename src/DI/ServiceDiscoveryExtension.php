<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\DI;

use LogicException;
use Mildabre\ServiceDiscovery\Attributes\EventListener;
use Mildabre\ServiceDiscovery\Attributes\Service;
use Mildabre\ServiceDiscovery\Attributes\Excluded;
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
use stdClass;

final class ServiceDiscoveryExtension extends CompilerExtension
{
    private const CacheFolder = '/cache/mildabre.serviceDiscovery';
    public const RobotLoaderCacheSubFolder = '/robotLoader';
    public const TagEventListener = 'event.listener';

    /**
     * @var list<array{ReflectionClass, ServiceDefinition}>
     */
    private array $definitions = [];

    private static bool $booted = false;

    private static ?string $currentMtimeHash = null;

    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'in' => Expect::arrayOf('string')->default([]),
            'type' => Expect::arrayOf('string')->default([]),
            'enableInject' => Expect::arrayOf('string')->default([]),
            'lazy' => Expect::bool()->required(),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        $tempDir = $builder->parameters['tempDir'];

        /**
         * @var stdClass $config
         */
        $config = $this->getConfig();
        $definitions = [];

        if ($config->lazy && PHP_VERSION_ID < 80400) {
            throw new LogicException(self::class . ", configured lazy creation requires PHP 8.4 or newer. You are running " . PHP_VERSION);
        }

        if (!self::$booted) {
            throw new LogicException("Missing boot in 'Bootstrap.php', add before createContainer(): ServiceDiscoveryExtension::boot(\$tempDir);\n");
        }

        $this->validateConfiguration($config);

        $checker = new MetadataChecker($tempDir, self::CacheFolder);

        [$classes, $indexedClasses] = $this->searchClasses($config->in, $tempDir);

        $mtimeHash = self::$currentMtimeHash ?? $checker->computeMtimeHash($config->in);
        $attrSnapshot = $checker->computeAttrSnapshot($indexedClasses);
        $checker->saveSnapshot($config->in, $mtimeHash, $attrSnapshot['attrData'], $attrSnapshot['attrHash']);

        foreach ($classes as $class) {
            try {
                $rc = new ReflectionClass($class);

            } catch (ReflectionException) {
                continue;
            }

            if ($rc->isAbstract() || $rc->isInterface() || $this->getAttribute($rc, Excluded::class) || $builder->findByType($class)) {
                continue;
            }

            $attribute = $this->getAttribute($rc, Service::class);
            if ($attribute) {
                $def = $builder->addDefinition(null)
                    ->setType($class);
                $definitions[] = [$rc, $def];
                continue;
            }

            $attribute = $this->getAttribute($rc, EventListener::class);
            if ($attribute) {
                $def = $builder->addDefinition(null)
                    ->setType($class)
                    ->addTag(self::TagEventListener);
                $def->lazy = $config->lazy;
                $definitions[] = [$rc, $def];
                continue;
            }

            foreach ($config->type as $type) {
                if ($rc->isSubclassOf($type)) {
                    $def = $builder->addDefinition(null)
                        ->setType($class);
                    $def->lazy = $config->lazy;
                    $definitions[] = [$rc, $def];
                    continue 2;
                }
            }
        }

        foreach ($definitions as [$rc, $def]) {
            $this->applyLazy($rc, $def, $config);
            $this->applyEnableInject($rc, $def, $config);
        }

        $this->definitions = $definitions;
    }

    private function validateConfiguration(stdClass $config): void
    {
        foreach ($config->type as $type) {
            if (interface_exists($type)) {
                throw new LogicException("Interface '$type' is not allowed in 'serviceDiscovery.type' configuration, use abstract class, or register services manually.");
            }
            if (!class_exists($type)) {
                throw new LogicException(
                    "Type '$type' in 'serviceDiscovery.type' must be existing class."
                );
            }
        }

        foreach ($config->enableInject as $type) {
            if (interface_exists($type)) {
                throw new LogicException("Interface '$type' is not allowed in 'serviceDiscovery.enableInject', use abstract class instead.");
            }
            if (!class_exists($type)) {
                throw new LogicException("Type '$type' in 'serviceDiscovery.enableInject' must be existing class.");
            }
        }

        foreach ($config->type as $i => $typeA) {

            bdump([$i, $typeA]);

            foreach ($config->type as $j => $typeB) {
                if ($i >= $j) {
                    continue;
                }

                if (is_subclass_of($typeA, $typeB) || is_subclass_of($typeB, $typeA) || $typeA === $typeB) {
                    throw new LogicException(sprintf(
                        "Redundant type in 'serviceDiscovery.type': '%s' is subtype of '%s', remove '%s' - it's already covered by '%s'.",
                        $typeA, $typeB, $typeA, $typeB
                    ));
                }
            }
        }
    }

    private function applyLazy(ReflectionClass $rc, ServiceDefinition $def, stdClass $config): void
    {
        if (!$config->lazy) {
            return;
        }

        $attribute = $this->getAttribute($rc, Service::class);
        if ($attribute) {
            $def->lazy = $attribute->newInstance()->lazy;
        }
    }

    private function applyEnableInject(ReflectionClass $rc, ServiceDefinition $def, stdClass $config): void
    {
        foreach ($config->enableInject as $type) {
                if ($rc->isSubclassOf($type)) {
                $def->addTag(InjectExtension::TagInject);
            }
        }
    }

    /**
     * @return array{list<string>, array<string, string>} [classes, indexedClasses]
     */
    private function searchClasses(array $dirs, string $tempDir): array
    {
        $loader = new RobotLoader;

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                throw new RuntimeException("Discovery directory '$dir' does not exists.");
            }

            $loader->addDirectory($dir);
        }

        $loader->setTempDirectory($tempDir . self::CacheFolder . self::RobotLoaderCacheSubFolder);
        $loader->rebuild();

        $indexedClasses = $loader->getIndexedClasses();             // [className => path]
        return [array_keys($indexedClasses), $indexedClasses];
    }

    private function getAttribute(ReflectionClass $rc, string $class): ?ReflectionAttribute
    {
        $origin = $rc;
        while ($rc) {
            $attributes = $rc->getAttributes($class);
            if ($attributes) {
                if ($rc->name !== $origin->name) {
                    throw new LogicException(sprintf("%s, attribute '%s' must be placed directly on the class, not inherited from %s.", $origin->name, $class, $rc->name ));
                }
                if ($rc->isAbstract()) {
                    throw new LogicException(sprintf("%s, attribute '%s' cannot be used on abstract class.", $rc->name, $class));
                }

                return $attributes[0];
            }

            $rc = $rc->getParentClass();
        }

        return null;
    }

    /**
     * @return list<ReflectionClass>
     */
    public function getServices(): array
    {
        $result = [];
        foreach ($this->definitions as [$rc, $def]) {
            $result[] = $rc;
        }
        return $result;
    }

    public static function boot(string $tempDir): void
    {
        $checker = new MetadataChecker($tempDir, self::CacheFolder);
        self::$currentMtimeHash = $checker->check();
        self::$booted = true;
    }
}
