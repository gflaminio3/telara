<?php

namespace Telara\Support;

class FileChunker
{
    protected int $chunkSize;
    protected bool $encryptionEnabled;
    protected string $encryptionKey;

    public function __construct(
        int $chunkSize = 19 * 1024 * 1024,
        bool $encryptionEnabled = false,
        ?string $encryptionKey = null
    ) {
        $this->chunkSize = $chunkSize;
        $this->encryptionEnabled = $encryptionEnabled;
        $this->encryptionKey = $encryptionKey ?? config('app.key');

        if ($this->encryptionEnabled && empty($this->encryptionKey)) {
            throw new \RuntimeException('Encryption key is required when encryption is enabled');
        }
    }

    public function needsChunking(string $contents): bool
    {
        return strlen($contents) > $this->chunkSize;
    }

    public function split(string $contents): array
    {
        $chunks = [];
        $totalSize = strlen($contents);
        $offset = 0;

        while ($offset < $totalSize) {
            $chunk = substr($contents, $offset, $this->chunkSize);

            if ($this->encryptionEnabled) {
                $chunk = $this->encrypt($chunk);
            }

            $chunks[] = $chunk;
            $offset += $this->chunkSize;
        }

        return $chunks;
    }

    public function merge(array $chunks): string
    {
        $decryptedChunks = [];

        foreach ($chunks as $chunk) {
            if ($this->encryptionEnabled) {
                $chunk = $this->decrypt($chunk);
            }
            $decryptedChunks[] = $chunk;
        }

        return implode('', $decryptedChunks);
    }

    public function encrypt(string $data): string
    {
        if (!$this->encryptionEnabled) {
            return $data;
        }

        $key = $this->getKey();
        $iv = random_bytes(16);

        $encrypted = openssl_encrypt(
            $data,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Failed to encrypt data');
        }

        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $data): string
    {
        if (!$this->encryptionEnabled) {
            return $data;
        }

        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            throw new \RuntimeException('Failed to decode encrypted data');
        }

        $key = $this->getKey();
        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);

        $decrypted = openssl_decrypt(
            $encrypted,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Failed to decrypt data');
        }

        return $decrypted;
    }

    protected function getKey(): string
    {
        $key = $this->encryptionKey;

        if (strpos($key, 'base64:') === 0) {
            $key = base64_decode(substr($key, 7));
        }

        if (strlen($key) !== 32) {
            throw new \RuntimeException('Encryption key must be 32 bytes for AES-256');
        }

        return $key;
    }

    public function generateChunkMetadata(array $chunkFileIds, string $originalPath, array $extraMetadata = []): array
    {
        return array_merge([
            'is_chunked' => true,
            'chunk_count' => count($chunkFileIds),
            'chunk_file_ids' => $chunkFileIds,
            'original_path' => $originalPath,
            'encrypted' => $this->encryptionEnabled,
        ], $extraMetadata);
    }

    public function isChunkedMetadata(array $metadata): bool
    {
        return ($metadata['is_chunked'] ?? false) === true;
    }

    public function isEncryptionEnabled(): bool
    {
        return $this->encryptionEnabled;
    }
}
