<?php

declare(strict_types=1);

namespace Mildabre\ServiceDiscovery\DI;

use FilesystemIterator;
use Nette\Utils\FileSystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class MetadataChecker
{
    private const MetaFile = '/discovery.meta';

    private readonly string $cacheDir;

    public function __construct(
        private readonly string $tempDir,
        string $cacheFolder
    ) {
        $this->cacheDir = $tempDir . $cacheFolder;
    }

    public function check(): ?string
    {
        $meta = $this->loadMeta();
        if ($meta === null) {
            return null;
        }

        $currentHash = $this->computeHash($meta['dirs']);
        if ($currentHash === $meta['hash']) {
            return null;
        }

        $this->invalidateContainer();
        return $currentHash;
    }

    public function persist(array $scanDirs, string $currentHash): void
    {
        $metaFile = $this->cacheDir . self::MetaFile;
        FileSystem::createDir(dirname($metaFile));

        file_put_contents($metaFile, serialize([
            'dirs' => $scanDirs,
            'hash' => $currentHash,
        ]));
    }

    public function computeHash(array $dirs): string
    {
        $times = [];
        foreach ($dirs as $dir) {
            $times[$dir] = is_dir($dir) ? filemtime($dir) : null;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $times[$file->getPathname()] = $file->getMTime();
                }
            }
        }

        ksort($times);
        return md5(serialize($times));
    }

    private function loadMeta(): ?array
    {
        $metaFile = $this->cacheDir . self::MetaFile;
        if (!is_file($metaFile)) {
            return null;
        }

        $data = unserialize(file_get_contents($metaFile));
        return is_array($data) ? $data : null;
    }

    private function invalidateContainer(): void
    {
        $containerCache = $this->tempDir . '/cache/nette.configurator';
        if (is_dir($containerCache)) {
            FileSystem::delete($containerCache);
        }
    }
}