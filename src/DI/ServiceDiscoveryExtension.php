<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\DI;

use LogicException;
use Mildabre\ServiceDiscovery\Attributes\Autowire;
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
        $config = $this->getConfig();
        $definitions = [];

        if ($config->lazy && PHP_VERSION_ID < 80400) {
            throw new LogicException(self::class . ", configured lazy creation requires PHP 8.4 or newer. You are running " . PHP_VERSION);
        }

        if (!self::$booted) {
            throw new LogicException("Missing boot in 'Bootstrap.php', add before createContainer(): ServiceDiscoveryExtension::boot(\$tempDir);\n");
        }

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
                    $def->lazy = $config->lazy;
                    $definitions[] = [$rc, $def];
                    continue 2;
                }
            }
        }

        foreach ($definitions as [$rc, $def]) {
            $this->applyLazy($rc, $def, $config);
            $this->applyInject($rc, $def, $config);
            $this->applyAutowire($rc, $def);
        }

        $this->definitions = $definitions;
    }

    private function applyLazy(ReflectionClass $rc, ServiceDefinition $def, object $config): void
    {
        if (!$config->lazy) {
            return;
        }

        $attribute = $rc->getAttributes(Service::class)[0] ?? null;
        if (!$attribute) {
            return;
        }

        $def->lazy = $attribute->newInstance()->lazy;
    }

    private function applyInject(ReflectionClass $rc, ServiceDefinition $def, object $config): void
    {
        foreach ($config->enableInject as $type) {
            if ($this->isClassOfType($rc, $type)) {
                $def->addTag(InjectExtension::TagInject);
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
        while ($rc) {
            $attributes = $rc->getAttributes($class);
            if ($attributes) {
                if ($rc->isAbstract()) {
                    throw new LogicException(sprintf("%s, attribute '%s' cannot be used on abstract class.", $rc->name, $class));
                }

                return $attributes[0] ?? null;
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
            if ($def instanceof ServiceDefinition) {
                $result[] = $rc;
            }
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
