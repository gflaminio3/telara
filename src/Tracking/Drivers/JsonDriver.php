<?php

namespace Telara\Tracking\Drivers;

use Telara\Tracking\FileTracker;

class JsonDriver extends FileTracker
{
    protected string $filePath;

    public function __construct()
    {
        $this->filePath = config('telara.json_tracking_path', storage_path('app/telara_files.json'));
        $this->ensureFileExists();
    }

    public function track(string $path, string $fileId, array $metadata = []): void
    {
        $files = $this->loadFiles();

        $files[$path] = array_merge($metadata, [
            'file_id' => $fileId,
            'path' => $path,
            'created_at' => $files[$path]['created_at'] ?? now()->timestamp,
            'updated_at' => now()->timestamp,
        ]);

        $this->saveFiles($files);
    }

    public function exists(string $path): bool
    {
        $files = $this->loadFiles();
        return isset($files[$path]);
    }

    public function getMetadata(string $path): ?array
    {
        $files = $this->loadFiles();
        return $files[$path] ?? null;
    }

    public function forget(string $path): void
    {
        $files = $this->loadFiles();
        unset($files[$path]);
        $this->saveFiles($files);
    }

    public function list(string $prefix = ''): array
    {
        $files = $this->loadFiles();

        if (empty($prefix)) {
            return array_values($files);
        }

        return array_values(array_filter($files, function ($file) use ($prefix) {
            return str_starts_with($file['path'], $prefix);
        }));
    }

    public function clear(): void
    {
        $this->saveFiles([]);
    }

    protected function loadFiles(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $contents = file_get_contents($this->filePath);

        if (empty($contents)) {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode JSON tracking file: ' . json_last_error_msg());
        }

        return $decoded ?? [];
    }

    protected function saveFiles(array $files): void
    {
        $encoded = json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode files to JSON: ' . json_last_error_msg());
        }

        $directory = dirname($this->filePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_put_contents($this->filePath, $encoded) === false) {
            throw new \RuntimeException("Failed to write to tracking file: {$this->filePath}");
        }
    }

    protected function ensureFileExists(): void
    {
        if (!file_exists($this->filePath)) {
            $directory = dirname($this->filePath);

            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($this->filePath, '{}');
        }
    }
}
