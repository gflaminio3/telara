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
use Telara\Tracking\FileTracker;

class TelegramFilesystemAdapter implements FilesystemAdapter
{
    protected string $botToken;
    protected string $chatId;
    protected array $config;
    protected ?FileTracker $tracker = null;

    public function __construct(string $botToken, string $chatId, array $config = [])
    {
        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->config = array_merge(config('telara', []), $config);

        // Inizializza il tracker se abilitato
        if ($this->config['track_files'] ?? false) {
            $this->tracker = FileTracker::make($this->config['tracking_driver'] ?? 'array');
        }
    }

    /**
     * Verifica se un file esiste
     */
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

    /**
     * Verifica se una directory esiste (Telegram non ha directory)
     */
    public function directoryExists(string $path): bool
    {
        return false;
    }

    /**
     * Scrive un file su Telegram
     */
    public function write(string $path, string $contents, Config $config): void
    {
        try {
            \Log::info('Telara: Attempting to write file', [
                'path' => $path,
                'size' => strlen($contents),
                'bot_token_set' => !empty($this->botToken),
                'chat_id' => $this->chatId,
            ]);

            $response = Http::withoutVerifying()->attach(
                'document',
                $contents,
                basename($path)
            )->post($this->getApiUrl('sendDocument'), [
                        'chat_id' => $this->chatId,
                        'caption' => $config->get('caption', basename($path)),
                    ]);

            \Log::info('Telara: Telegram API response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                throw new \Exception('Failed to upload file to Telegram: ' . $response->body());
            }

            $result = $response->json('result');
            $fileId = $result['document']['file_id'] ?? null;

            if (!$fileId) {
                throw new \Exception('No file_id returned from Telegram');
            }

            // Traccia il file se il tracker è abilitato
            if ($this->tracker) {
                $this->tracker->track($path, $fileId, [
                    'size' => strlen($contents),
                    'mime_type' => $result['document']['mime_type'] ?? 'application/octet-stream',
                    'file_name' => $result['document']['file_name'] ?? basename($path),
                    'caption' => $config->get('caption'),
                ]);
            }

            $this->log('info', 'write', ['path' => $path, 'file_id' => $fileId, 'success' => true]);
        } catch (\Exception $e) {
            \Log::error('Telara: Write failed', [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->log('error', 'write', ['path' => $path, 'error' => $e->getMessage()]);
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Scrive uno stream su Telegram
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $stringContents = stream_get_contents($contents);
        $this->write($path, $stringContents, $config);
    }

    /**
     * Legge un file da Telegram
     */
    public function read(string $path): string
    {
        try {
            // Il path qui è il file_id di Telegram
            $fileId = $this->resolveFileId($path);

            // Ottieni il file path dal file_id
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

            // Scarica il file
            $fileUrl = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
            $fileResponse = Http::withoutVerifying()->get($fileUrl);

            if (!$fileResponse->successful()) {
                throw new \Exception('Failed to download file from Telegram');
            }

            $this->log('info', 'read', ['path' => $path, 'file_id' => $fileId, 'success' => true]);

            return $fileResponse->body();
        } catch (\Exception $e) {
            $this->log('error', 'read', ['path' => $path, 'error' => $e->getMessage()]);
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Legge un file come stream
     */
    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        return $stream;
    }

    /**
     * Elimina un file (rimuove solo dal tracker, Telegram non supporta eliminazione)
     */
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

    /**
     * Elimina una directory (non supportato)
     */
    public function deleteDirectory(string $path): void
    {
        // Telegram non ha directory
    }

    /**
     * Crea una directory (non supportato)
     */
    public function createDirectory(string $path, Config $config): void
    {
        // Telegram non ha directory
    }

    /**
     * Imposta la visibilità (non supportato)
     */
    public function setVisibility(string $path, string $visibility): void
    {
        // Telegram non supporta visibilità
    }

    /**
     * Ottiene la visibilità (sempre public)
     */
    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'public');
    }

    /**
     * Ottiene il MIME type
     */
    public function mimeType(string $path): FileAttributes
    {
        if ($this->tracker) {
            $metadata = $this->tracker->getMetadata($path);
            $mimeType = $metadata['mime_type'] ?? 'application/octet-stream';
            return new FileAttributes($path, null, null, null, $mimeType);
        }

        return new FileAttributes($path, null, null, null, 'application/octet-stream');
    }

    /**
     * Ottiene l'ultimo timestamp di modifica
     */
    public function lastModified(string $path): FileAttributes
    {
        if ($this->tracker) {
            $metadata = $this->tracker->getMetadata($path);
            $timestamp = $metadata['updated_at'] ?? time();
            return new FileAttributes($path, null, null, $timestamp);
        }

        return new FileAttributes($path, null, null, time());
    }

    /**
     * Ottiene la dimensione del file
     */
    public function fileSize(string $path): FileAttributes
    {
        if ($this->tracker) {
            $metadata = $this->tracker->getMetadata($path);
            $size = $metadata['size'] ?? null;
            return new FileAttributes($path, $size);
        }

        return new FileAttributes($path);
    }

    /**
     * Lista il contenuto (solo file tracciati)
     */
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

    /**
     * Sposta un file (non supportato direttamente)
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    /**
     * Copia un file
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $contents = $this->read($source);
        $this->write($destination, $contents, $config);
    }

    /**
     * Risolve il file_id da un path
     */
    protected function resolveFileId(string $path): string
    {
        // Se il path sembra già un file_id di Telegram, usalo direttamente
        if (strpos($path, '/') === false && strlen($path) > 20) {
            return $path;
        }

        // Altrimenti cerca nel tracker
        if ($this->tracker) {
            $metadata = $this->tracker->getMetadata($path);
            return $metadata['file_id'] ?? $path;
        }

        return $path;
    }

    /**
     * Ottiene l'URL dell'API di Telegram
     */
    protected function getApiUrl(string $method): string
    {
        return "https://api.telegram.org/bot{$this->botToken}/{$method}";
    }

    /**
     * Log delle operazioni
     */
    protected function log(string $level, string $action, array $context = []): void
    {
        if ($this->config['logging']['enabled'] ?? false) {
            $channel = $this->config['logging']['channel'] ?? 'single';
            Log::channel($channel)->$level("Telara: {$action}", $context);
        }
    }
}
