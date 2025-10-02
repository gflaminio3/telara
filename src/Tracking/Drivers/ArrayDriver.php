<?php

namespace Telara\Tracking\Drivers;

use Telara\Support\FileTracker;

class ArrayDriver extends FileTracker
{
    protected array $files = [];

    public function track(string $path, string $fileId, array $metadata = []): void
    {
        $this->files[$path] = array_merge($metadata, [
            'file_id' => $fileId,
            'path' => $path,
            'created_at' => now()->timestamp,
            'updated_at' => now()->timestamp,
        ]);
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function getMetadata(string $path): ?array
    {
        return $this->files[$path] ?? null;
    }

    public function forget(string $path): void
    {
        unset($this->files[$path]);
    }

    public function list(string $prefix = ''): array
    {
        if (empty($prefix)) {
            return array_values($this->files);
        }

        return array_values(array_filter($this->files, fn($file) => str_starts_with($file['path'], $prefix)));
    }

    public function clear(): void
    {
        $this->files = [];
    }
}
