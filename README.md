# Laravel Telegram Storage (Telara)

A custom Laravel package that provides a storage disk for uploading files to Telegram and retrieving them seamlessly. It integrates effortlessly with Laravel's Filesystem to let you store files through Telegram's API just like you would use S3, local, or any other disk.

**Features:**
- Automatic file chunking for large files (bypasses Telegram's 20MB limit)
- Optional AES-256 encryption for secure storage
- Multiple tracking drivers (database, JSON, array)
- Seamless Laravel Storage integration

<p align="center">
  <img src="https://raw.githubusercontent.com/gflaminio3/telara/master/docs/banner.png" height="300" alt="Laravel Telegram Storage">
  <br>
  <a href="https://packagist.org/packages/gflaminio3/telara"><img alt="Total Downloads" src="https://img.shields.io/packagist/dt/gflaminio3/telara"></a>
  <a href="https://packagist.org/packages/gflaminio3/telara"><img alt="Latest Version" src="https://img.shields.io/packagist/v/gflaminio3/telara"></a>
  <a href="https://packagist.org/packages/gflaminio3/telara"><img alt="License" src="https://img.shields.io/packagist/l/gflaminio3/telara"></a>
</p>

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Chunking & Encryption](#chunking--encryption)
- [Advanced Usage](#advanced-usage)
- [Testing](#testing)
- [License](#license)

---

## Requirements

- **PHP 8.3+**
- **Laravel 11+**
- A **Telegram bot token** from [BotFather](https://t.me/botfather)

## Installation

```bash
composer require gflaminio3/telara
```

Publish configuration (optional):

```bash
php artisan vendor:publish --tag=telara-config
```

Run migrations:

```bash
php artisan migrate
```

## Configuration

### Interactive Setup

```bash
php artisan telara:configure
```

This command will verify your bot token, fetch available chats, and update your `.env` automatically.

### Manual Setup

**1. Add disk in `config/filesystems.php`:**

```php
'disks' => [
    'telegram' => [
        'driver' => 'telara',
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],
],
```

**2. Configure `.env`:**

```env
TELEGRAM_BOT_TOKEN=123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11
TELEGRAM_CHAT_ID=987654321

TELARA_TRACK_FILES=true
TELARA_TRACKING_DRIVER=database

# Chunking (enabled by default)
TELARA_CHUNKING_ENABLED=true
TELARA_CHUNK_SIZE=19922944  # 19MB per chunk

# Encryption (disabled by default)
TELARA_ENCRYPTION_ENABLED=false

# Logging
TELARA_LOGGING_ENABLED=false
```

## Usage

```php
use Illuminate\Support\Facades\Storage;

// Upload a file
Storage::disk('telegram')->put('document.pdf', file_get_contents('path/to/file.pdf'));

// Upload with caption
Storage::disk('telegram')->put('image.jpg', $contents, ['caption' => 'My image']);

// Download a file
$contents = Storage::disk('telegram')->get('document.pdf');

// Check existence
if (Storage::disk('telegram')->exists('document.pdf')) {
    // File exists
}

// Delete from tracker
Storage::disk('telegram')->delete('document.pdf');
```

## Chunking & Encryption

### Automatic Chunking

Files larger than 19MB are automatically split into chunks and uploaded separately. They are reassembled transparently when downloaded.

```php
// This file will be chunked automatically
$largeFile = str_repeat('A', 50 * 1024 * 1024); // 50MB
Storage::disk('telegram')->put('large.bin', $largeFile);

// Download - chunks are merged automatically
$retrieved = Storage::disk('telegram')->get('large.bin');
```

### Encryption

Enable encryption to secure your files with AES-256:

```env
TELARA_ENCRYPTION_ENABLED=true
```

Files are encrypted before upload and decrypted on download automatically:

```php
// Upload - encrypted automatically
Storage::disk('telegram')->put('secret.txt', 'Sensitive data');

// Download - decrypted automatically
$plaintext = Storage::disk('telegram')->get('secret.txt');
```

**Note:** Encryption works with both chunked and non-chunked files. Each chunk is encrypted individually.

### How It Works

- **Chunking**: Large files are split into 19MB chunks. Metadata tracks all chunk file_ids for reassembly.
- **Encryption**: Uses AES-256-CBC with a random IV per file/chunk. Key is derived from `APP_KEY`.
- **Tracking**: Database stores chunked/encrypted flags for proper retrieval.

## Advanced Usage

### Database Tracking

Check tracked files:

```php
$files = DB::table('telara_files')->get();

// Check if chunked/encrypted
$file = DB::table('telara_files')->where('path', 'large.bin')->first();
echo $file->is_chunked;    // true/false
echo $file->is_encrypted;  // true/false
```

### Disable Chunking

To disable chunking (files > 20MB will fail):

```env
TELARA_CHUNKING_ENABLED=false
```

### Custom Chunk Size

```env
TELARA_CHUNK_SIZE=10485760  # 10MB chunks
```

### Channels

To use a Telegram channel, add your bot as admin and use the channel ID:

```env
TELEGRAM_CHAT_ID=-1001234567890
```

## Testing

```bash
composer test
```

Individual commands:

```bash
composer lint          # Code style
composer test:types    # Static analysis
composer test:unit     # Unit tests
```

## Limitations

- Telegram's download limit: 20MB per chunk (chunking bypasses upload limit)
- Files cannot be deleted from Telegram via API
- No directory structure support

## Troubleshooting

**Files upload but return false:**
Enable logging to verify operations.

**Encryption not working:**
Ensure `TELARA_ENCRYPTION_ENABLED=true` and run `php artisan config:clear`.

**Chunks not working:**
Check logs with `TELARA_LOGGING_ENABLED=true` to see chunking operations.

## License

MIT License. See [LICENSE](LICENSE) for details.

---

**Enjoy building with Telara!**
