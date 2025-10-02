<?php

namespace Telara;

use Illuminate\Contracts\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Telara\Models\Telara;

/**
 * This class serves as a custom filesystem driver for interacting with Telegram via its bot API.
 * It now supports various file tracking drivers (array, database, JSON, none) and optional logging
 * as defined in the "telara.php" config file.
 */
class TelegramStorageProvider implements FilesystemAdapter
{
    /**
     * Base URL for Telegram's bot API.
     */
    private string $apiUrl;

    /**
     * An in-memory array for tracking files, if "tracking_driver" is set to "array".
     */
    private array $trackedFiles = [];

    /**
     * Constructor: Initializes the Telegram API URL using the provided bot token
     * and sets the chat ID where files should be sent.
     */
    public function __construct(
        private string $botToken,
        private string $chatId
    ) {
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    /**
     * Writes a file to Telegram by sending a "document" message to the specified chat.
     * If file tracking is enabled, calls trackFile() to store file metadata according to
     * the chosen driver (array, database, JSON, none).
     *
     * If logging is enabled, logs info about the file being written.
     */
    public function write(string $path, string $contents, array $config = []): bool
    {
        $response = Http::attach(
            'document',
            $contents,
            basename($path)
        )->post("{$this->apiUrl}/sendDocument", [
                    'chat_id' => $this->chatId,
                    'caption' => $config['caption'] ?? null,
                ]);

        $success = $response->successful();
        $this->logAction('write', [
            'path' => $path,
            'success' => $success,
        ]);

        if ($success) {
            // Telegram returns some JSON with a file_id, we can parse it if we want to store the ID
            $result = $response->json();
            $fileId = $result['result']['document']['file_id'] ?? null;

            if ($fileId !== null) {
                $this->trackFile($path, $fileId, $config);
            }

            return true;
        }

        return false;
    }

    /**
     * Reads (downloads) a file from Telegram. It first calls the "getFile" method
     * to retrieve the file path on Telegram's servers, then downloads it via the returned URL.
     * Logs the action if enabled.
     *
     * @param  string  $path  The Telegram file_id to download
     */
    public function read(string $path): ?string
    {
        $response = Http::get("{$this->apiUrl}/getFile", [
            'file_id' => $path,
        ]);

        $result = $response->json();
        $this->logAction('read', [
            'path' => $path,
            'status' => $response->status(),
        ]);

        if (isset($result['result']['file_path'])) {
            $fileUrl = "https://api.telegram.org/file/bot{$this->botToken}/{$result['result']['file_path']}";

            return file_get_contents($fileUrl);
        }

        return null;
    }

    /**
     * Lists all files that have been tracked, based on the configuration. If the user
     * has chosen "array" tracking, you'll see the array contents. If it's "database" or "json",
     * this method retrieves the list from those sources. If "none" is set, this returns an empty array.
     */
    public function listContents(string $directory = '', bool $recursive = false): array
    {
        $trackEnabled = Config::get('telara.track_files', false);
        $trackingDriver = Config::get('telara.tracking_driver', 'none');

        if (!$trackEnabled || $trackingDriver === 'none') {
            $this->logAction('listContents', ['message' => 'Tracking disabled.']);

            return [];
        }

        if ($trackingDriver === 'array') {
            $this->logAction('listContents', ['message' => 'Returning in-memory array list.']);

            return array_map(fn($fp) => ['type' => 'file', 'path' => $fp], $this->trackedFiles);
        }

        if ($trackingDriver === 'database') {
            $this->logAction('listContents', ['message' => 'Listing from database.']);

            return $this->listFromDatabase();
        }

        if ($trackingDriver === 'json') {
            $this->logAction('listContents', ['message' => 'Listing from JSON file.']);

            return $this->listFromJson();
        }

        return [];
    }

    /**
     * Deletes a file by removing its path from the tracking system (if enabled).
     * Note that Telegram does not actually support remote file deletion after uploading.
     */
    public function delete(string $path): bool
    {
        $trackEnabled = Config::get('telara.track_files', false);
        $trackingDriver = Config::get('telara.tracking_driver', 'none');

        if (!$trackEnabled || $trackingDriver === 'none') {
            $this->logAction('delete', [
                'path' => $path,
                'message' => 'Tracking disabled; cannot remove file from local record.',
            ]);

            return false;
        }

        $removed = false;
        if ($trackingDriver === 'array') {
            if (in_array($path, $this->trackedFiles, true)) {
                $this->trackedFiles = array_diff($this->trackedFiles, [$path]);
                $removed = true;
            }
        } elseif ($trackingDriver === 'database') {
            $removed = $this->removeFromDatabase($path);
        } elseif ($trackingDriver === 'json') {
            $removed = $this->removeFromJson($path);
        }

        $this->logAction('delete', [
            'path' => $path,
            'removed' => $removed,
        ]);

        return $removed;
    }

    /**
     * Checks if a file exists in the tracking system (if enabled).
     */
    public function has(string $path): bool
    {
        $trackEnabled = Config::get('telara.track_files', false);
        $trackingDriver = Config::get('telara.tracking_driver', 'none');

        if (!$trackEnabled || $trackingDriver === 'none') {
            return false;
        }

        if ($trackingDriver === 'array') {
            return in_array($path, $this->trackedFiles, true);
        }

        if ($trackingDriver === 'database') {
            return $this->hasInDatabase($path);
        }

        if ($trackingDriver === 'json') {
            return $this->hasInJson($path);
        }

        return false;
    }

    /**
     * Deletes a directory. This driver does not manage directories, so this is a no-op.
     */
    public function deleteDirectory(string $dirname): bool
    {
        return false;
    }

    /**
     * Creates a directory. This driver does not manage directories, so this is a no-op.
     */
    public function createDir(string $dirname, array $config): bool
    {
        return false;
    }

    /**
     * Sets the visibility for a file. Telegram does not truly support file visibility settings,
     * so this always returns true as a no-op.
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        return true;
    }

    /**
     * Gets the visibility for a file. Telegram does not truly support file visibility settings,
     * so this always returns 'public' as a default placeholder.
     */
    public function getVisibility(string $path): string
    {
        return 'public';
    }

    /**
     * Writes a file stream to Telegram. It reads the stream into a string and delegates
     * to the write() method for final upload.
     *
     * @param  resource  $resource
     */
    public function writeStream(string $path, $resource, array $config): bool
    {
        $contents = stream_get_contents($resource);
        if ($contents === false) {
            $this->logAction('writeStream', [
                'path' => $path,
                'message' => 'Failed to read from stream',
            ]);

            return false;
        }

        return $this->write($path, $contents, $config);
    }

    /**
     * Reads a file from Telegram as a stream. If the file is successfully fetched,
     * it creates a temporary in-memory stream for reading the contents.
     *
     *
     * @return resource|bool
     */
    public function readStream(string $path)
    {
        $contents = $this->read($path);
        if ($contents !== null) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $contents);
            rewind($stream);

            return $stream;
        }

        return false;
    }

    /**
     * -------------------------------------------------------------------------
     * PRIVATE HELPER METHODS
     * -------------------------------------------------------------------------
     */

    /**
     * Track a newly uploaded file according to the "track_files" configuration and
     * the specified "tracking_driver". This method is called in write().
     */
    private function trackFile(string $path, string $fileId, array $config = []): void
    {
        $trackEnabled = Config::get('telara.track_files', false);
        $trackingDriver = Config::get('telara.tracking_driver', 'none');

        if (!$trackEnabled || $trackingDriver === 'none') {
            return;
        }

        if ($trackingDriver === 'array') {
            // For array, we only store the path in memory.
            if (!in_array($path, $this->trackedFiles, true)) {
                $this->trackedFiles[] = $path;
            }
        } elseif ($trackingDriver === 'database') {
            $this->trackFileInDatabase($path, $fileId, $config);
        } elseif ($trackingDriver === 'json') {
            $this->trackFileInJson($path, $fileId, $config);
        }
    }

    /**
     * Logs actions if logging is enabled in the config.
     */
    private function logAction(string $action, array $details = []): void
    {
        $loggingEnabled = Config::get('telara.logging.enabled', false);
        if ($loggingEnabled) {
            $channel = Config::get('telara.logging.channel', 'stack');
            Log::channel($channel)->info("Telara: {$action}", $details);
        }
    }

    /**
     * Retrieves all tracked files from the database.
     */
    private function listFromDatabase(): array
    {
        // Fetch all records from the telara_tracked_files table (via Telara model).
        // Map them into an array of ['type' => 'file', 'path' => ...].
        $records = Telara::all();
        $results = [];

        foreach ($records as $record) {
            $results[] = [
                'type' => 'file',
                'path' => $record->path,
            ];
        }

        return $results;
    }

    /**
     * Reads file tracking data from a JSON file.
     */
    private function listFromJson(): array
    {
        // The JSON file path is taken from config, even if not published.
        $jsonFilePath = Config::get('telara.json_tracking_path', storage_path('app/telara_files.json'));
        if (!file_exists($jsonFilePath)) {
            return [];
        }

        $data = json_decode(file_get_contents($jsonFilePath), true);
        if (!is_array($data)) {
            return [];
        }

        $results = [];
        foreach ($data as $record) {
            $results[] = [
                'type' => 'file',
                'path' => $record['path'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * Removes the tracking record for a file from the database.
     */
    private function removeFromDatabase(string $path): bool
    {
        // Attempt to find the record by path and delete it.
        $fileRecord = Telara::where('path', $path)->first();
        if ($fileRecord) {
            $fileRecord->delete();

            return true;
        }

        return false;
    }

    /**
     * Removes the tracking record for a file in the JSON file.
     */
    private function removeFromJson(string $path): bool
    {
        $jsonFilePath = Config::get('telara.json_tracking_path', storage_path('app/telara_files.json'));
        if (!file_exists($jsonFilePath)) {
            return false;
        }

        $data = json_decode(file_get_contents($jsonFilePath), true);
        if (!is_array($data)) {
            return false;
        }

        $originalCount = count($data);
        $data = array_filter($data, function ($item) use ($path) {
            return isset($item['path']) && $item['path'] !== $path;
        });

        if (count($data) === $originalCount) {
            // No entry removed
            return false;
        }

        // Save the updated array back to disk
        file_put_contents($jsonFilePath, json_encode(array_values($data), JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * Determines if a file is present in the database tracking table.
     */
    private function hasInDatabase(string $path): bool
    {
        return Telara::where('path', $path)->exists();
    }

    /**
     * Determines if a file is present in the JSON tracking file.
     */
    private function hasInJson(string $path): bool
    {
        $jsonFilePath = Config::get('telara.json_tracking_path', storage_path('app/telara_files.json'));
        if (!file_exists($jsonFilePath)) {
            return false;
        }

        $data = json_decode(file_get_contents($jsonFilePath), true);
        if (!is_array($data)) {
            return false;
        }

        foreach ($data as $record) {
            if (($record['path'] ?? '') === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stores file tracking information in the database.
     */
    private function trackFileInDatabase(string $path, string $fileId, array $config): void
    {
        // Create a new record in the telara_tracked_files table (via Telara model).
        Telara::create([
            'file_id' => $fileId,
            'path' => $path,
            'caption' => $config['caption'] ?? null,
        ]);
    }

    /**
     * Stores file tracking information in a JSON file.
     */
    private function trackFileInJson(string $path, string $fileId, array $config): void
    {
        $jsonFilePath = Config::get('telara.json_tracking_path', storage_path('app/telara_files.json'));

        // Load existing data if file exists, otherwise start an empty array
        if (file_exists($jsonFilePath)) {
            $data = json_decode(file_get_contents($jsonFilePath), true) ?? [];
        } else {
            $data = [];
        }

        // Append a new record
        $data[] = [
            'file_id' => $fileId,
            'path' => $path,
            'caption' => $config['caption'] ?? null,
            'timestamp' => now(),
        ];

        // Save updated data back to JSON file
        file_put_contents($jsonFilePath, json_encode($data, JSON_PRETTY_PRINT));
    }
}
