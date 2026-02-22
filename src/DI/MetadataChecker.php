<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\DI;

use FilesystemIterator;
use Mildabre\ServiceDiscovery\Attributes\EventListener;
use Mildabre\ServiceDiscovery\Attributes\Excluded;
use Mildabre\ServiceDiscovery\Attributes\Service;
use Nette\Loaders\RobotLoader;
use Nette\Utils\FileSystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

final class MetadataChecker
{
    private const MetaFile = '/discovery.meta';

    private const WatchedClassAttributes = [Service::class, Excluded::class, EventListener::class];

    private static ?string $controllerBase = null;

    private static ?string $httpAccessBase = null;

    private readonly string $cacheDir;

    public function __construct(
        private readonly string $tempDir,
        string $cacheFolder,
    ) {
        $this->cacheDir = $tempDir . $cacheFolder;
    }

    public static function watchControllerMethods(string $controllerBase, string $httpAccessBase): void
    {
        self::$controllerBase = $controllerBase;
        self::$httpAccessBase = $httpAccessBase;
    }

    public function check(): ?string
    {
        $meta = $this->loadMeta();
        if ($meta === null) {
            $this->invalidateContainer();               // paranoid
            return null;
        }

        $loader = new RobotLoader;
        $loader->setTempDirectory($this->cacheDir . ServiceDiscoveryExtension::RobotLoaderCacheSubFolder);
        foreach ($meta['dirs'] as $dir) {
            $loader->addDirectory($dir);                // must be identical with SDE::searchClasses() - for generateCacheKey()
        }

        $indexed = $loader->getIndexedClasses();        // no scan, read from file

        if ($indexed === []) {
            return null;                                // cache missing or empty — let Nette rebuild from scratch
        }

        $changedPaths = $this->getChangedPaths($meta['dirs'], $meta['mtimes']);         // first and fast mtime check

        if ($changedPaths === []) {
            return null;
        }

        foreach ($changedPaths as $file) {
            if (!is_file($file) && !is_dir($file)) {              // really deleted file or directory
                $this->invalidateContainer();
                return $this->computeMtimeHash($meta['dirs']);
            }
        }

        $byPath = array_flip($indexed);                         // [path => className]
        $attributesChanged = $this->attributesChanged($changedPaths, $byPath, $meta['attrData'], $meta['attrHash']);

        if (!$attributesChanged) {                              // second changed-files-snapshot check - slow but precise
            return null;
        }

        $this->invalidateContainer();
        return $this->computeMtimeHash($meta['dirs']);
    }

    /**
     * @param array<string, array<string, mixed>> $attrData [className => hashClassAttributes()]
     */
    public function saveSnapshot(array $scanDirs, string $mtimeHash, array $attrData, string $attrHash): void           // after DIC compilation
    {
        $metaFile = $this->cacheDir . self::MetaFile;
        FileSystem::createDir(dirname($metaFile));

        file_put_contents($metaFile, serialize([
            'dirs'      => $scanDirs,
            'mtimes'    => $this->collectMtimes($scanDirs),
            'mtimeHash' => $mtimeHash,
            'attrData'  => $attrData,
            'attrHash'  => $attrHash,
        ]));
    }

    public function computeMtimeHash(array $dirs): string
    {
        $times = $this->collectMtimes($dirs);
        ksort($times);
        return md5(serialize($times));
    }

    /**
     * Snapshot of all classes in RobotLoader cache, called after DIC compilation.
     *
     * @param array<string, string> $indexedClasses [className => path] from RobotLoader
     * @return array{attrData: array<string, mixed>, attrHash: string}
     */
    public function computeAttrSnapshot(array $indexedClasses): array
    {
        $attrData = [];
        foreach ($indexedClasses as $class => $path) {
            try {
                $rc = new ReflectionClass($class);
            } catch (ReflectionException) {
                continue;
            }
            $attrData[$class] = $this->extractClassAttributes($rc);
        }

        ksort($attrData);
        return [
            'attrData' => $attrData,
            'attrHash' => md5(serialize($attrData)),
        ];
    }

    /**
     * Compares changed files attributes with saved snapshot.
     * Merges recomputed data into stored snapshot and compares resulting hash.
     *
     * @param list<string> $changedPaths
     * @param array<string, string> $byPath [path => className]
     * @param array<string, mixed> $savedAttrData saved full snapshot
     */
    private function attributesChanged(
        array $changedPaths,
        array $byPath,
        array $savedAttrData,
        string $savedAttrHash,
    ): bool {
        $updatedData = [];

        foreach ($changedPaths as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $class = $byPath[$file] ?? null;
            if ($class === null) {                  // PHP file in scan dirs but not in RobotLoader cache => new class not yet indexed => invalidate
                return true;
            }

            try {
                $rc = new ReflectionClass($class);
            } catch (ReflectionException) {
                continue;
            }

            $updatedData[$class] = $this->extractClassAttributes($rc);
        }

        if ($updatedData === []) {
            return false;
        }

        $mergedData = array_merge($savedAttrData, $updatedData);        // update data of changed classes
        ksort($mergedData);
        $currentHash = md5(serialize($mergedData));

        return $currentHash !== $savedAttrHash;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractClassAttributes(ReflectionClass $rc): array
    {
        $data = [];

        foreach ($rc->getAttributes() as $attribute) {
            if (in_array($attribute->getName(), self::WatchedClassAttributes, true)) {
                $data['class'][] = $attribute->getName();
            }
        }

        if (self::$controllerBase !== null && $rc->isSubclassOf(self::$controllerBase)) {
            $methods = $this->extractControllerMethods($rc);
            if ($methods !== []) {
                $data['methods'] = $methods;
            }
        }

        return $data;
    }

    /**
     * @return array<string, array{attrs: list<string>, params: list<string>}>
     */
    private function extractControllerMethods(ReflectionClass $rc): array
    {
        $methods = [];

        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            if ($method->getDeclaringClass()->name !== $rc->name) {
                continue;
            }

            $httpAttrs = $method->getAttributes(self::$httpAccessBase, ReflectionAttribute::IS_INSTANCEOF);
            if ($httpAttrs === []) {
                continue;
            }

            $attrs = array_map(fn($a) => $a->getName(), $httpAttrs);

            $params = [];
            foreach ($method->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $params[] = $type->getName();
                }
            }

            $methods[$method->getName()] = [
                'attrs'  => $attrs,
                'params' => $params,
            ];
        }

        return $methods;
    }

    private function getChangedPaths(array $dirs, array $savedMtimes): array
    {
        $current = $this->collectMtimes($dirs);
        $changed = [];

        foreach ($current as $path => $mtime) {
            if (($savedMtimes[$path] ?? null) !== $mtime) {
                $changed[] = $path;
            }
        }

        foreach (array_keys($savedMtimes) as $path) {               // deleted files or directories
            if (!isset($current[$path])) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    private function collectMtimes(array $dirs): array
    {
        $times = [];
        foreach ($dirs as $dir) {
            $times[$dir] = is_dir($dir) ? filemtime($dir) : null;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if ($file->isDir() || $file->getExtension() === 'php') {
                    $times[$file->getPathname()] = $file->getMTime();
                }
            }
        }

        return $times;
    }

    private function loadMeta(): ?array
    {
        $metaFile = $this->cacheDir . self::MetaFile;
        if (!is_file($metaFile)) {
            return null;
        }

        $data = unserialize(file_get_contents($metaFile), ['allowed_classes' => false]);        // paranoid
        return is_array($data) ? $data : null;
    }

    private function invalidateContainer(): void
    {
        $cacheDir = $this->tempDir . '/cache';
        if (is_dir($cacheDir)) {
            FileSystem::delete($cacheDir);
        }
    }
}
