<?php

namespace Telara\Tracking\Drivers;

use Illuminate\Support\Facades\DB;
use Telara\Tracking\FileTracker;

class DatabaseDriver extends FileTracker
{
    protected string $table = 'telara_files';

    public function track(string $path, string $fileId, array $metadata = []): void
    {
        $data = [
            'file_id' => $fileId,
            'path' => $path,
            'file_name' => $metadata['file_name'] ?? null,
            'mime_type' => $metadata['mime_type'] ?? null,
            'size' => $metadata['size'] ?? null,
            'caption' => $metadata['caption'] ?? null,
            'metadata' => json_encode($metadata),
            'updated_at' => now(),
        ];

        DB::table($this->table)->updateOrInsert(
            ['path' => $path],
            array_merge($data, ['created_at' => DB::raw('COALESCE(created_at, NOW())')])
        );
    }

    public function exists(string $path): bool
    {
        return DB::table($this->table)
            ->where('path', $path)
            ->exists();
    }

    public function getMetadata(string $path): ?array
    {
        $record = DB::table($this->table)
            ->where('path', $path)
            ->first();

        if (!$record) {
            return null;
        }

        $metadata = json_decode($record->metadata ?? '{}', true) ?? [];

        return array_merge($metadata, [
            'file_id' => $record->file_id,
            'path' => $record->path,
            'size' => $record->size,
            'mime_type' => $record->mime_type,
            'file_name' => $record->file_name,
            'caption' => $record->caption,
            'created_at' => $record->created_at ? strtotime($record->created_at) : null,
            'updated_at' => $record->updated_at ? strtotime($record->updated_at) : null,
        ]);
    }

    public function forget(string $path): void
    {
        DB::table($this->table)
            ->where('path', $path)
            ->delete();
    }

    public function list(string $prefix = ''): array
    {
        $query = DB::table($this->table);

        if (!empty($prefix)) {
            $query->where('path', 'like', $prefix . '%');
        }

        return $query->get()->map(function ($record) {
            $metadata = json_decode($record->metadata ?? '{}', true) ?? [];

            return array_merge($metadata, [
                'file_id' => $record->file_id,
                'path' => $record->path,
                'size' => $record->size,
                'mime_type' => $record->mime_type,
                'file_name' => $record->file_name,
                'caption' => $record->caption,
                'created_at' => $record->created_at ? strtotime($record->created_at) : null,
                'updated_at' => $record->updated_at ? strtotime($record->updated_at) : null,
            ]);
        })->toArray();
    }

    public function clear(): void
    {
        DB::table($this->table)->truncate();
    }
}
