<?php

namespace Telara\Filesystem;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToCheckFileExistence;
use Telara\Support\FileTracker;
use Telara\Support\FileChunker;

class TelegramFilesystemAdapter implements FilesystemAdapter
{
    protected string $botToken;
    protected string $chatId;
    protected array $config;
    protected ?FileTracker $tracker = null;
    protected FileChunker $chunker;

    public function __construct(string $botToken, string $chatId, array $config = [])
    {
        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->config = array_merge(config('telara', []), $config);

        if ($this->config['track_files'] ?? false) {
            $this->tracker = FileTracker::make($this->config['tracking_driver'] ?? 'array');
        }

        $chunkSize = $this->config['chunking']['size'] ?? 19 * 1024 * 1024;
        $encryptionEnabled = $this->config['encryption']['enabled'] ?? false;
        $encryptionKey = $this->config['encryption']['key'] ?? config('app.key');

        Log::info('Telara: Initializing FileChunker', [
            'chunk_size' => $chunkSize,
            'encryption_enabled' => $encryptionEnabled,
            'encryption_key_set' => !empty($encryptionKey),
        ]);

        $this->chunker = new FileChunker(
            $chunkSize,
            $encryptionEnabled,
            $encryptionKey
        );
    }

    public function fileExists(string $path): bool
    {
        try {
            if ($this->tracker) {
                return $this->tracker->exists($path);
            }
            return false;
        } catch (\Exception $e) {
            $this->log('error', 'fileExists', ['path' => $path, 'error' => $e->getMessage()]);
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $chunkingEnabled = $this->config['chunking']['enabled'] ?? true;
            $needsChunking = $chunkingEnabled && $this->chunker->needsChunking($contents);

            if ($needsChunking) {
                $this->writeChunked($path, $contents, $config);
            } else {
                $this->writeSingle($path, $contents, $config);
            }
        } catch (\Exception $e) {
            $this->log('error', 'write', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    protected function writeSingle(string $path, string $contents, Config $config): void
    {
        $originalSize = strlen($contents);

        $this->log('info', 'write_attempt', [
            'path' => $path,
            'size' => $originalSize,
            'chunked' => false,
            'encrypted' => $this->chunker->isEncryptionEnabled(),
        ]);

        if ($this->chunker->isEncryptionEnabled()) {
            $contents = $this->chunker->encrypt($contents);
        }

        $response = Http::withoutVerifying()->attach(
            'document',
            $contents,
            basename($path)
        )->post($this->getApiUrl('sendDocument'), [
                    'chat_id' => $this->chatId,
                    'caption' => $config->get('caption', basename($path)),
                ]);

        $this->log('info', 'telegram_response', [
            'status' => $response->status(),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to upload file to Telegram: ' . $response->body());
        }

        $result = $response->json('result');
        $fileId = $result['document']['file_id'] ?? null;

        if (!$fileId) {
            throw new \Exception('No file_id returned from Telegram');
        }

        if ($this->tracker) {
            $this->tracker->track($path, $fileId, [
                'size' => $originalSize,
                'mime_type' => $result['document']['mime_type'] ?? 'application/octet-stream',
                'file_name' => $result['document']['file_name'] ?? basename($path),
                'caption' => $config->get('caption'),
                'is_chunked' => false,
                'is_encrypted' => $this->chunker->isEncryptionEnabled(),
            ]);
        }

        $this->log('info', 'write', ['path' => $path, 'file_id' => $fileId, 'success' => true]);
    }

    protected function writeChunked(string $path, string $contents, Config $config): void
    {
        $this->log('info', 'write_chunked_attempt', [
            'path' => $path,
            'size' => strlen($contents),
            'chunked' => true,
        ]);

        $chunks = $this->chunker->split($contents);
        $chunkFileIds = [];

        foreach ($chunks as $index => $chunk) {
            $chunkPath = "{$path}.chunk{$index}";

            $response = Http::withoutVerifying()->attach(
                'document',
                $chunk,
                basename($chunkPath)
            )->post($this->getApiUrl('sendDocument'), [
                        'chat_id' => $this->chatId,
                        'caption' => "Chunk {$index} of " . basename($path),
                    ]);

            if (!$response->successful()) {
                throw new \Exception("Failed to upload chunk {$index}: " . $response->body());
            }

            $result = $response->json('result');
            $fileId = $result['document']['file_id'] ?? null;

            if (!$fileId) {
                throw new \Exception("No file_id returned for chunk {$index}");
            }

            $chunkFileIds[] = $fileId;

            $this->log('info', 'chunk_uploaded', [
                'path' => $path,
                'chunk' => $index,
                'file_id' => $fileId,
            ]);
        }

        if ($this->tracker) {
            $metadata = $this->chunker->generateChunkMetadata($chunkFileIds, $path, [
                'original_size' => strlen($contents),
                'mime_type' => 'application/octet-stream',
                'file_name' => basename($path),
                'caption' => $config->get('caption'),
            ]);

            $this->tracker->track($path, $chunkFileIds[0], array_merge($metadata, [
                'is_encrypted' => $this->chunker->isEncryptionEnabled(),
            ]));
        }

        $this->log('info', 'write_chunked', [
            'path' => $path,
            'chunks' => count($chunkFileIds),
            'success' => true,
        ]);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $stringContents = stream_get_contents($contents);
        $this->write($path, $stringContents, $config);
    }

    public function read(string $path): string
    {
        try {
            $metadata = $this->tracker ? $this->tracker->getMetadata($path) : null;

            if ($metadata && $this->chunker->isChunkedMetadata($metadata)) {
                return $this->readChunked($path, $metadata);
            }

            return $this->readSingle($path);
        } catch (\Exception $e) {
            $this->log('error', 'read', ['path' => $path, 'error' => $e->getMessage()]);
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    protected function readSingle(string $path): string
    {
        $fileId = $this->resolveFileId($path);

        $response = Http::withoutVerifying()->get($this->getApiUrl('getFile'), [
            'file_id' => $fileId,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to get file info from Telegram: ' . $response->body());
        }

        $filePath = $response->json('result.file_path');

        if (!$filePath) {
            throw new \Exception('No file_path returned from Telegram');
        }

        $fileUrl = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
        $fileResponse = Http::withoutVerifying()->get($fileUrl);

        if (!$fileResponse->successful()) {
            throw new \Exception('Failed to download file from Telegram');
        }

        $contents = $fileResponse->body();

        if ($this->chunker->isEncryptionEnabled()) {
            $contents = $this->chunker->decrypt($contents);
        }

        $this->log('info', 'read', [
            'path' => $path,
            'file_id' => $fileId,
            'encrypted' => $this->chunker->isEncryptionEnabled(),
            'success' => true,
        ]);

        return $contents;
    }

    protected function readChunked(string $path, array $metadata): string
    {
        $this->log('info', 'read_chunked_attempt', [
            'path' => $path,
            'chunks' => $metadata['chunk_count'] ?? 0,
        ]);

        $chunkFileIds = $metadata['chunk_file_ids'] ?? [];
        $chunks = [];

        foreach ($chunkFileIds as $index => $fileId) {
            $response = Http::withoutVerifying()->get($this->getApiUrl('getFile'), [
                'file_id' => $fileId,
            ]);

            if (!$response->successful()) {
                throw new \Exception("Failed to get info for chunk {$index}: " . $response->body());
            }

            $filePath = $response->json('result.file_path');

            if (!$filePath) {
                throw new \Exception("No file_path returned for chunk {$index}");
            }

            $fileUrl = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
            $fileResponse = Http::withoutVerifying()->get($fileUrl);

            if (!$fileResponse->successful()) {
                throw new \Exception("Failed to download chunk {$index}");
            }

            $chunks[] = $fileResponse->body();

            $this->log('info', 'chunk_downloaded', [
                'path' => $path,
                'chunk' => $index,
            ]);
        }

        $merged = $this->chunker->merge($chunks);

        $this->log('info', 'read_chunked', [
            'path' => $path,
            'chunks' => count($chunks),
            'success' => true,
        ]);

        return $merged;
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            if ($this->tracker) {
                $this->tracker->forget($path);
                $this->log('info', 'delete', ['path' => $path, 'success' => true]);
            }
        } catch (\Exception $e) {
            $this->log('error', 'delete', ['path' => $path, 'error' => $e->getMessage()]);
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        // Not supported
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Not supported
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Not supported
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        if ($this->tracker) {
            $metadata = $this->tracker->getMetadata($path);
            $mimeType = $metadata['mime_type'] ?? 'application/octet-stream';
            return new FileAttributes($path, null, null, null, $mimeType);
        }

        return new FileAttributes($path, null, null, null, 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        if ($this->tracker) {
            $metadata = $this->tracker->getMetadata($path);
            $timestamp = $metadata['updated_at'] ?? time();
            return new FileAttributes($path, null, null, $timestamp);
        }

        return new FileAttributes($path, null, null, time());
    }

    public function fileSize(string $path): FileAttributes
    {
        if ($this->tracker) {
            $metadata = $this->tracker->getMetadata($path);
            $size = $metadata['size'] ?? null;
            return new FileAttributes($path, $size);
        }

        return new FileAttributes($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        if ($this->tracker) {
            $files = $this->tracker->list($path);

            foreach ($files as $file) {
                yield new FileAttributes(
                    $file['path'],
                    $file['size'] ?? null,
                    null,
                    $file['updated_at'] ?? null,
                    $file['mime_type'] ?? null
                );
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $contents = $this->read($source);
        $this->write($destination, $contents, $config);
    }

    protected function resolveFileId(string $path): string
    {
        if (strpos($path, '/') === false && strlen($path) > 20) {
            return $path;
        }

        if ($this->tracker) {
            $metadata = $this->tracker->getMetadata($path);
            return $metadata['file_id'] ?? $path;
        }

        return $path;
    }

    protected function getApiUrl(string $method): string
    {
        return "https://api.telegram.org/bot{$this->botToken}/{$method}";
    }

    protected function log(string $level, string $action, array $context = []): void
    {
        if ($this->config['logging']['enabled'] ?? false) {
            $channel = $this->config['logging']['channel'] ?? 'single';
            Log::channel($channel)->$level("Telara: {$action}", $context);
        }
    }
}
