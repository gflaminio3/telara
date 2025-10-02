<?php

namespace Telara\Tracking;

use Telara\Tracking\Drivers\ArrayDriver;
use Telara\Tracking\Drivers\DatabaseDriver;
use Telara\Tracking\Drivers\JsonDriver;
use Telara\Tracking\Drivers\NoneDriver;

abstract class FileTracker
{
    /**
     * Crea un'istanza del tracker appropriato
     */
    public static function make(string $driver): FileTracker
    {
        return match ($driver) {
            'array' => new ArrayDriver(),
            'database' => new DatabaseDriver(),
            'json' => new JsonDriver(),
            'none' => new NoneDriver(),
            default => throw new \InvalidArgumentException("Unsupported tracking driver: {$driver}"),
        };
    }

    /**
     * Traccia un file caricato
     */
    abstract public function track(string $path, string $fileId, array $metadata = []): void;

    /**
     * Verifica se un file esiste
     */
    abstract public function exists(string $path): bool;

    /**
     * Ottiene i metadata di un file
     */
    abstract public function getMetadata(string $path): ?array;

    /**
     * Rimuove un file dal tracking
     */
    abstract public function forget(string $path): void;

    /**
     * Lista tutti i file tracciati
     */
    abstract public function list(string $prefix = ''): array;

    /**
     * Ottiene il file_id da un path
     */
    public function getFileId(string $path): ?string
    {
        $metadata = $this->getMetadata($path);
        return $metadata['file_id'] ?? null;
    }

    /**
     * Pulisce tutti i file tracciati
     */
    abstract public function clear(): void;
}
