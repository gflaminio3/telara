<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram Bot Token
    |--------------------------------------------------------------------------
    |
    | The token of your Telegram bot. You can get this token by creating
    | a bot with the BotFather on Telegram.
    |
    */
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Chat ID
    |--------------------------------------------------------------------------
    |
    | The default chat ID where files will be sent. This can be overridden
    | dynamically when using the package.
    |
    */
    'chat_id' => env('TELEGRAM_CHAT_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | File Tracking
    |--------------------------------------------------------------------------
    |
    | Enable or disable file tracking. When enabled, the package will
    | store metadata about uploaded files (e.g., file IDs, paths, etc.),
    | allowing features like listing or “has” checks.
    |
    */
    'track_files' => false,

    /*
    |--------------------------------------------------------------------------
    | Tracking Driver
    |--------------------------------------------------------------------------
    |
    | Decide how to track file metadata. Possible values could be:
    | - 'array': Use a simple in-memory array during runtime.
    | - 'database': Store data in a dedicated database table.
    | - 'json': Store data in a JSON file in storage.
    | - 'none': Disable tracking entirely.
    |
    */
    'tracking_driver' => env('TELEGRAM_TRACKING_DRIVER', 'array'),

    /*
    |--------------------------------------------------------------------------
    | Database Table Name
    |--------------------------------------------------------------------------
    |
    | If 'tracking_driver' is set to 'database', this configuration key
    | defines the table name where file tracking data will be stored.
    | You can create a migration for this table to hold the metadata,
    | including columns like file path, Telegram file_id, etc.
    |
    */
    'tracking_table' => env('TELEGRAM_TRACKING_TABLE', 'telara_tracked_files'),

    /*
    |--------------------------------------------------------------------------
    | JSON Tracking Path
    |--------------------------------------------------------------------------
    |
    | If 'tracking_driver' is set to 'json', this option allows you to specify
    | a path to a JSON file where all file metadata should be recorded.
    |
    */
    'json_tracking_path' => storage_path('app/storage/telara_files.json'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Turn on/off detailed logging for Telegram API interactions. This could be
    | useful for debugging request/response cycles when working with the Telegram
    | bot. You may also want to set up a custom log channel for this.
    |
    */
    'logging' => [
        'enabled' => env('TELEGRAM_LOGGING', false),
        'channel' => env('TELEGRAM_LOG_CHANNEL', 'stack'),
    ],
];
