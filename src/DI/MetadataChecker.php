<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\DI;

use FilesystemIterator;
use Nette\Loaders\RobotLoader;
use Nette\Utils\FileSystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;

final class MetadataChecker
{
    private const MetaFile = '/discovery.meta';

    private readonly string $cacheDir;

    public function __construct(
        private readonly string $tempDir,
        string $cacheFolder,
    ) {
        $this->cacheDir = $tempDir . $cacheFolder;
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
            return null;                                // cache missing or empty — invalidate DIC
        }

        $changedFiles = $this->getChangedFiles($meta['dirs'], $meta['mtimes']);         // first and fast mtime check

        if ($changedFiles === []) {
            return null;
        }

        foreach ($changedFiles as $file) {
            if (!is_file($file) && !is_dir($file)) {              // really deleted class file or directory
                $this->invalidateContainer();
                return $this->computeMtimeHash($meta['dirs']);
            }
        }

        $byPath = array_flip($indexed);                         // [path => className]
        $changed = $this->attributesChanged($changedFiles, $byPath, $meta['attrData'], $meta['attrHash']);

        if (!$changed) {                            //  second changed-files-snapshot check - slow but precise
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
            'mtimes'    => $this->collectMTimes($scanDirs),
            'mtimeHash' => $mtimeHash,
            'attrData'  => $attrData,
            'attrHash'  => $attrHash,
        ]));
    }

    public function computeMtimeHash(array $dirs): string
    {
        $times = $this->collectMTimes($dirs);
        ksort($times);
        return md5(serialize($times));
    }

    /**
     * snapshot of all classes in RobotLoader cache, used when DIC is invalidated
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
            $attrData[$class] = $this->hashClassAttributes($rc);
        }

        ksort($attrData);
        return [
            'attrData' => $attrData,
            'attrHash' => md5(serialize($attrData)),
        ];
    }

    /**
     * compares changed files attributes with snapshot
     * Merges new data into stored snapshot and compares resulting hash
     *
     * @param list<string> $changedFiles
     * @param array<string, string> $byPath [path => className]
     * @param array<string, mixed> $savedAttrData saved full snapshot
     */
    private function attributesChanged(
        array $changedFiles,
        array $byPath,
        array $savedAttrData,
        string $savedAttrHash,
    ): bool {
        $updatedData = [];

        foreach ($changedFiles as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $class = $byPath[$file] ?? null;
            if ($class === null) {                  // PHP file in scan dirs but not in RobotLoader cache => new class not indexed by RobotLoader => invalidate
                return true;
            }

            try {
                $rc = new ReflectionClass($class);
            } catch (ReflectionException) {
                continue;
            }

            $updatedData[$class] = $this->hashClassAttributes($rc);
        }

        if ($updatedData === []) {
            return false;
        }

        $mergedData = array_merge($savedAttrData, $updatedData);
        ksort($mergedData);
        $currentHash = md5(serialize($mergedData));

        return $currentHash !== $savedAttrHash;
    }

    /**
     * @return array<string, mixed>
     */
    private function hashClassAttributes(ReflectionClass $rc): array
    {
        $data = [];

        foreach ($rc->getAttributes() as $attr) {
            $data['class'][] = $attr->getName();
        }

        foreach ($rc->getMethods() as $method) {
            $methodAttrs = [];
            foreach ($method->getAttributes() as $attr) {
                $methodAttrs[] = $attr->getName();
            }
            if ($methodAttrs !== []) {
                $data['methods'][$method->getName()] = $methodAttrs;
            }
        }

        foreach ($rc->getProperties() as $property) {
            $propAttrs = [];
            foreach ($property->getAttributes() as $attr) {
                $propAttrs[] = $attr->getName();
            }
            if ($propAttrs !== []) {
                $data['properties'][$property->getName()] = $propAttrs;
            }
        }

        return $data;
    }

    private function getChangedFiles(array $dirs, array $savedMtimes): array
    {
        $current = $this->collectMTimes($dirs);
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

    private function collectMTimes(array $dirs): array
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
