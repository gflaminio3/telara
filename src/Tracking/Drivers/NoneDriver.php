<?php

namespace Telara\Tracking\Drivers;

use Telara\Support\FileTracker;

class NoneDriver extends FileTracker
{
    public function track(string $path, string $fileId, array $metadata = []): void
    {
        // Nothing to do here - tracking disabled
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function getMetadata(string $path): ?array
    {
        return null;
    }

    public function forget(string $path): void
    {
        // Non fa nulla - tracking disabilitato
    }

    public function list(string $prefix = ''): array
    {
        return [];
    }

    public function clear(): void
    {
        // Nothing to do here - tracking disabled
    }
}
