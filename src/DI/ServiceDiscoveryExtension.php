<?php

declare(strict_types=1);

namespace Bite\ServiceDiscovery\DI;

use LogicException;
use Bite\ServiceDiscovery\Attributes\EventListener;
use Bite\ServiceDiscovery\Attributes\Factory;
use Bite\ServiceDiscovery\Attributes\Service;
use Bite\ServiceDiscovery\Attributes\Excluded;
use Bite\ServiceDiscovery\Attributes\Transient;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Extensions\InjectExtension;
use Nette\Loaders\RobotLoader;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use RuntimeException;
use stdClass;

final class ServiceDiscoveryExtension extends CompilerExtension
{
    private const string CacheFolder = '/cache/mildabre.serviceDiscovery';

    public const string CacheSubFolder = '/robotLoader';

    public const string TagEventListener = 'event.listener';

    public const string TagTransient = 'bite.transient';        // for manual transient registration tags: [bite.transient]

    private const array ExclusiveClassAttributes = [Service::class, EventListener::class, Excluded::class, Transient::class];

    /**
     * @var list<array{ReflectionClass, ServiceDefinition, Lifecycle}>
     */
    private array $definitions = [];

    /**
     * @var list<array{ReflectionClass, FactoryDefinition}>
     */
    private array $factoryDefinitions = [];

    /**
     * @var array<string, bool>
     */
    private array $factoryTargetClasses = [];

    private static bool $booted = false;

    private static ?string $currentMtimeHash = null;

    private array $discoveredClasses = [];

    private bool $beforeCompileDone = false;

    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'in' => Expect::arrayOf('string')->default([]),
            'lazy' => Expect::bool()->default(false),

        ])->otherItems(
            Expect::structure([
                'type' => Expect::string()->required(),
                'lifecycle' => Expect::anyOf('singleton', 'transient')->default('singleton'),
                'lazy' => Expect::bool()->default(false),
            ]),
        );
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        $tempDir = $builder->parameters['tempDir'];

        /**
         * @var stdClass $config
         */
        $config = $this->getConfig();

        // 'in' default = %appDir%, handled here rather than via Expect::default(), because a Schema default would remain the literal
        // string '%appDir%' (parameter expansion happens on the raw NEON values before Schema fills in the missing keys).
        if ($config->in === []) {
            $config->in = [$builder->parameters['appDir']];
        }

        if ($config->lazy && PHP_VERSION_ID < 80400) {
            throw new LogicException(self::class . ", configured lazy creation requires PHP 8.4 or newer. You are running " . PHP_VERSION);
        }

        foreach ($this->getGroups($config) as $groupName => $group) {
            if ($group->lazy && PHP_VERSION_ID < 80400) {
                throw new LogicException(self::class . ", discovery group '$groupName' has lazy: true, which requires PHP 8.4 or newer. You are running " . PHP_VERSION);
            }
        }

        if (!self::$booted) {
            throw new LogicException("Missing extension boot in 'Bootstrap.php', add the boot before createContainer(): according to the README.md");
        }

        $this->validateConfiguration($config);

        $checker = new MetadataChecker($tempDir, self::CacheFolder);

        [$classes, $indexedClasses] = $this->searchClasses($config->in, $tempDir);

        $mtimeHash = self::$currentMtimeHash ?? $checker->computeMtimeHash($config->in);
        $attrSnapshot = $checker->computeAttrSnapshot($indexedClasses);
        $checker->saveSnapshot($config->in, $mtimeHash, $attrSnapshot['attrData'], $attrSnapshot['attrHash']);

        $this->discoveredClasses = $classes;
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();

        /**
         * @var stdClass $config
         */
        $config = $this->getConfig();
        $groups = $this->getGroups($config);

        $definitions = [];
        $factoryDefinitions = [];

        // First pass: validate attribute usage and collect #[Factory] target classes, so the second pass can exclude them from a duplicate registration.
        $this->factoryTargetClasses = [];

        foreach ($this->discoveredClasses as $class) {
            try {
                $rc = new ReflectionClass($class);
            } catch (ReflectionException) {
                continue;
            }

            $this->validateExclusiveAttributes($rc);
            $this->validateFactoryAttributeOnlyOnInterface($rc);
            $this->validateTransientNotOnInterface($rc);

            if (!$rc->isInterface() || !$rc->getAttributes(Factory::class)) {
                continue;
            }

            $this->validateFactoryInterface($rc);

            $targetClass = $this->resolveFactoryTargetClass($rc);
            if ($targetClass !== null) {
                $this->factoryTargetClasses[$targetClass] = true;
            }
        }

        // Second pass: actual registration.
        foreach ($this->discoveredClasses as $class) {
            try {
                $rc = new ReflectionClass($class);

            } catch (ReflectionException) {
                continue;
            }

            if ($this->getAttribute($rc, Excluded::class) || $builder->findByType($class)) {
                continue;
            }

            if ($rc->isInterface()) {
                $attribute = $rc->getAttributes(Factory::class);
                if ($attribute) {
                    $def = $builder->addFactoryDefinition(null)
                        ->setImplement($class);

                    $def->addTag(InjectExtension::TagInject);

                    $factoryDefinitions[] = [$rc, $def];
                }
                continue;
            }

            if ($rc->isAbstract()) {
                continue;
            }

            if (isset($this->factoryTargetClasses[$rc->name])) {
                if ($this->getAttribute($rc, Service::class) || $this->getAttribute($rc, EventListener::class) || $this->getAttribute($rc, Transient::class)) {
                    throw new LogicException(sprintf(
                        "%s, class is the return type of a '%s' interface and must not also carry '#[Service]', '#[EventListener]' or '#[Transient]'. Remove the attribute or stop creating it via a factory.",
                        $rc->name,
                        Factory::class,
                    ));
                }
                continue;
            }

            $attribute = $this->getAttribute($rc, Service::class);
            if ($attribute) {
                $def = $builder->addDefinition(null)
                    ->setType($class);
                $definitions[] = [$rc, $def, Lifecycle::Singleton];
                continue;
            }

            $attribute = $this->getAttribute($rc, EventListener::class);
            if ($attribute) {
                $def = $builder->addDefinition(null)
                    ->setType($class)
                    ->addTag(self::TagEventListener);
                $def->lazy = $config->lazy;
                $definitions[] = [$rc, $def, Lifecycle::Singleton];
                continue;
            }

            $attribute = $this->getAttribute($rc, Transient::class);
            if ($attribute) {
                $def = $builder->addDefinition(null)
                    ->setType($class);
                $definitions[] = [$rc, $def, Lifecycle::Transient];
                continue;
            }

            // Third pass matching: a class may legitimately implement/extend more than one
            // group's type. This is fine as long as all matching groups agree on the effective
            // registration (lifecycle + lazy) - anything else would make the outcome depend on
            // group order in the config, which we refuse to resolve silently via priority.
            $matchedGroups = array_filter($groups, fn($group) => $rc->isSubclassOf($group->type));

            if (count($matchedGroups) > 1) {
                $lifecycles = array_unique(array_map(fn($g) => $g->lifecycle, $matchedGroups));
                $lazies = array_unique(array_map(fn($g) => $g->lazy, $matchedGroups));

                if (count($lifecycles) > 1 || count($lazies) > 1) {
                    throw new LogicException(sprintf(
                        "%s matches multiple discovery groups with conflicting configuration (%s) — resolving this by group priority/order would make registration order-dependent. Align the groups' lifecycle/lazy settings, or add an explicit '#[Service]'/'#[Transient]' attribute to opt this class out of discovery groups.",
                        $rc->name,
                        implode(', ', array_map(
                            fn($name, $g) => "$name (lifecycle: $g->lifecycle, lazy: " . ($g->lazy ? 'true' : 'false') . ')',
                            array_keys($matchedGroups),
                            $matchedGroups,
                        )),
                    ));
                }
            }

            if ($matchedGroups !== []) {
                $group = reset($matchedGroups);
                $def = $builder->addDefinition(null)
                    ->setType($class);
                $def->lazy = $group->lazy;
                $definitions[] = [$rc, $def, Lifecycle::from($group->lifecycle)];
            }
        }

        foreach ($definitions as [$rc, $def, $lifecycle]) {
            $this->applyLazy($rc, $def, $config);
            $def->addTag(InjectExtension::TagInject);
        }

        $this->definitions = $definitions;
        $this->factoryDefinitions = $factoryDefinitions;
        $this->beforeCompileDone = true;
    }

    public function afterCompile(ClassType $class): void
    {
        $transientNames = [];

        foreach ($this->definitions as [, $def, $lifecycle]) {
            if ($lifecycle === Lifecycle::Transient) {
                $transientNames[] = $def->getName();
            }
        }

        foreach (array_keys($this->getContainerBuilder()->findByTag(self::TagTransient)) as $name) {
            $transientNames[] = $name;
        }

        if ($transientNames === []) {
            return;
        }

        $class->addConstant('TransientServices', array_fill_keys($transientNames, true))
            ->setVisibility('private');

        if ($class->hasMethod('getService')) {
            $class->removeMethod('getService');
        }

        $method = $class->addMethod('getService')
            ->setReturnType('object')
            ->addBody(<<<'PHP'
                if (isset(self::TransientServices[$name])) {
                    return $this->createService($name);
                }
                return parent::getService($name);
                PHP);
        $method->addParameter('name')->setType('string');
    }

    /**
     * @return array<string, stdClass> [groupName => group] – all from config except reserved top-level keys 'in'/'lazy'.
     */
    private function getGroups(stdClass $config): array
    {
        $groups = get_object_vars($config);
        unset($groups['in'], $groups['lazy']);
        return $groups;
    }

    private function validateConfiguration(stdClass $config): void
    {
        $groups = $this->getGroups($config);

        foreach ($groups as $groupName => $group) {
            if (!class_exists($group->type) && !interface_exists($group->type)) {
                throw new LogicException("Discovery group '$groupName': type $group->type must be an existing class or interface.");
            }
        }

        $names = array_keys($groups);
        foreach ($names as $i => $nameA) {
            foreach ($names as $j => $nameB) {
                if ($i >= $j) {
                    continue;
                }

                $typeA = $groups[$nameA]->type;
                $typeB = $groups[$nameB]->type;

                if ($typeA === $typeB) {
                    throw new LogicException("Discovery groups '$nameA' and '$nameB' use the same type $typeA, remove one of them.");
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

    private function validateExclusiveAttributes(ReflectionClass $rc): void
    {
        $found = [];
        foreach (self::ExclusiveClassAttributes as $attrClass) {
            if ($rc->getAttributes($attrClass)) {
                $found[] = $attrClass;
            }
        }

        if (count($found) > 1) {
            throw new LogicException(sprintf(
                "%s, class must not combine multiple attributes at once: %s. Use exactly one of #[Service], #[EventListener], #[Excluded], #[Transient].",
                $rc->name,
                implode(', ', $found),
            ));
        }
    }

    private function validateFactoryAttributeOnlyOnInterface(ReflectionClass $rc): void
    {
        if (!$rc->isInterface() && $rc->getAttributes(Factory::class)) {
            throw new LogicException(sprintf(
                "%s, attribute '%s' is allowed only on interfaces, not on a class.",
                $rc->name,
                Factory::class,
            ));
        }
    }

    private function validateTransientNotOnInterface(ReflectionClass $rc): void
    {
        if ($rc->isInterface() && $rc->getAttributes(Transient::class)) {
            throw new LogicException(sprintf(
                "%s, attribute '%s' is allowed only on classes, not on an interface. Lifecycle is a property of the implementation, not of the contract.",
                $rc->name,
                Transient::class,
            ));
        }
    }

    private function validateFactoryInterface(ReflectionClass $rc): void
    {
        $parents = $rc->getInterfaceNames();
        if ($parents) {
            throw new LogicException(sprintf(
                "%s, attribute '%s' interface must not extend another interface (%s).",
                $rc->name,
                Factory::class,
                implode(', ', $parents),
            ));
        }

        $methods = $rc->getMethods();

        if (count($methods) !== 1) {
            throw new LogicException(sprintf(
                "%s, attribute '%s' requires interface with exactly one method (create/get).",
                $rc->name,
                Factory::class,
            ));
        }

        $method = $methods[0];
        if (!$method->hasReturnType()) {
            throw new LogicException(sprintf(
                "%s::%s(), method must declare a return type to be used as '%s'.",
                $rc->name,
                $method->name,
                Factory::class,
            ));
        }
    }

    private function resolveFactoryTargetClass(ReflectionClass $rc): ?string
    {
        $method = $rc->getMethods()[0];
        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && !$returnType->isBuiltin()) {
            return ltrim($returnType->getName(), '\\');
        }

        return null;
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

        $loader->setTempDirectory($tempDir . self::CacheFolder . self::CacheSubFolder);
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
        if (!$this->beforeCompileDone) {
            throw new LogicException(self::class . ", method 'getServices()' called before beforeCompile() is done.");
        }

        $result = [];
        foreach ($this->definitions as [$rc, $def, $lifecycle]) {
            $result[] = $rc;
        }
        return $result;
    }

    /**
     * @return list<ReflectionClass>
     */
    public function getFactories(): array
    {
        if (!$this->beforeCompileDone) {
            throw new LogicException(self::class . ", method 'getFactories()' called before beforeCompile() is done.");
        }

        $result = [];
        foreach ($this->factoryDefinitions as [$rc, $def]) {
            $result[] = $rc;
        }
        return $result;
    }

    /**
     * @return list<string> class names created by #[Factory] interfaces
     */
    public function getFactoryTargetClasses(): array
    {
        if (!$this->beforeCompileDone) {
            throw new LogicException(self::class . ", method 'getFactoryTargetClasses()' called before beforeCompile() is done.");
        }

        return array_keys($this->factoryTargetClasses);
    }

    public static function boot(string $tempDir): void
    {
        $checker = new MetadataChecker($tempDir, self::CacheFolder);
        self::$currentMtimeHash = $checker->check();
        self::$booted = true;
    }
}