<?php

namespace Telara;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Telara\Console\Commands\ConfigureTelegramCommand;
use Telara\Filesystem\TelegramFilesystemAdapter;

class TelaraServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge la configurazione del pacchetto con quella dell'app
        $configPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'telara.php';
        
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'telara');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $configPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'telara.php';
        $migrationsPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        // Pubblica il file di configurazione
        if ($this->app->runningInConsole()) {
            if (file_exists($configPath)) {
                $this->publishes([
                    $configPath => config_path('telara.php'),
                ], 'telara-config');
            }

            // Pubblica le migrations
            if (is_dir($migrationsPath)) {
                $this->publishes([
                    $migrationsPath => database_path('migrations'),
                ], 'telara-migrations');
            }
        }

        // Carica automaticamente le migrations del pacchetto
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        // Registra i comandi Artisan
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConfigureTelegramCommand::class,
            ]);
        }

        // Estendi il filesystem di Laravel con il driver Telegram
        Storage::extend('telara', function ($app, $config) {
            $adapter = new TelegramFilesystemAdapter(
                $config['bot_token'] ?? config('telara.bot_token'),
                $config['chat_id'] ?? config('telara.chat_id'),
                $config
            );
            
            $flysystem = new \League\Flysystem\Filesystem($adapter, $config);
            
            return new \Illuminate\Filesystem\FilesystemAdapter($flysystem, $adapter, $config);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            'telara',
            ConfigureTelegramCommand::class,
        ];
    }
}
