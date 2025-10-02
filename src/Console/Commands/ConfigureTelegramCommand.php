<?php

namespace Telara\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ConfigureTelegramCommand extends Command
{
    protected $signature = 'telara:configure';

    protected $description = 'Configure Telegram chat ID for Telara storage';

    public function handle()
    {
        $this->info(' Telara Configuration Wizard');
        $this->newLine();

        // Ottieni il bot token
        $botToken = $this->getBotToken();

        if (!$botToken) {
            $this->error(' Bot token is required to continue.');
            return Command::FAILURE;
        }

        if (!$this->verifyBotToken($botToken)) {
            $this->error(' Invalid bot token. Please check your token and try again.');
            return Command::FAILURE;
        }

        $this->info(' Bot token verified successfully!');
        $this->newLine();

        $chats = $this->getAvailableChats($botToken);

        if (empty($chats)) {
            $this->warn('  No chats found. Please:');
            $this->line('   1. Send a message to your bot');
            $this->line('   2. Or add the bot to a group/channel');
            $this->line('   3. Run this command again');
            return Command::FAILURE;
        }

        $this->info(' Available chats:');
        $this->newLine();

        $choices = [];
        foreach ($chats as $index => $chat) {
            $label = $this->formatChatLabel($chat);
            $choices[$index] = $label;
            $this->line("   [{$index}] {$label}");
        }

        $this->newLine();
        $selectedIndex = $this->ask('Select chat number', '0');

        if (!isset($chats[$selectedIndex])) {
            $this->error(' Invalid selection.');
            return Command::FAILURE;
        }

        $selectedChat = $chats[$selectedIndex];
        $chatId = $selectedChat['id'];

        // Aggiorna il file .env
        $this->updateEnvFile('TELEGRAM_BOT_TOKEN', $botToken);
        $this->updateEnvFile('TELEGRAM_CHAT_ID', $chatId);

        $this->newLine();
        $this->info(' Configuration saved successfully!');
        $this->line("   Bot Token: {$this->maskToken($botToken)}");
        $this->line("   Chat ID: {$chatId}");
        $this->newLine();
        $this->info(' Telara is now ready to use!');

        return Command::SUCCESS;
    }

    protected function getBotToken(): ?string
    {
        $envToken = env('TELEGRAM_BOT_TOKEN');

        if ($envToken) {
            $useExisting = $this->confirm(
                "Use existing bot token from .env ({$this->maskToken($envToken)})?",
                true
            );

            if ($useExisting) {
                return $envToken;
            }
        }

        return $this->ask('Enter your Telegram bot token');
    }

    protected function verifyBotToken(string $botToken): bool
    {
        try {
            $response = Http::withoutVerifying()->get("https://api.telegram.org/bot{$botToken}/getMe");
            return $response->successful() && $response->json('ok') === true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function getAvailableChats(string $botToken): array
    {
        try {
            $response = Http::withoutVerifying()->get("https://api.telegram.org/bot{$botToken}/getUpdates");

            if (!$response->successful()) {
                return [];
            }

            $updates = $response->json('result', []);
            $chats = [];
            $seenIds = [];

            foreach ($updates as $update) {
                $chat = $update['message']['chat'] ?? $update['my_chat_member']['chat'] ?? null;

                if ($chat && !in_array($chat['id'], $seenIds)) {
                    $chats[] = [
                        'id' => $chat['id'],
                        'type' => $chat['type'],
                        'title' => $chat['title'] ?? null,
                        'username' => $chat['username'] ?? null,
                        'first_name' => $chat['first_name'] ?? null,
                        'last_name' => $chat['last_name'] ?? null,
                    ];
                    $seenIds[] = $chat['id'];
                }
            }

            return $chats;
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function formatChatLabel(array $chat): string
    {
        $type = ucfirst($chat['type']);

        if ($chat['type'] === 'private') {
            $name = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
            $username = $chat['username'] ? " (@{$chat['username']})" : '';
            return "{$type}: {$name}{$username} [ID: {$chat['id']}]";
        }

        $title = $chat['title'] ?? 'Unknown';
        $username = $chat['username'] ? " (@{$chat['username']})" : '';
        return "{$type}: {$title}{$username} [ID: {$chat['id']}]";
    }

    protected function updateEnvFile(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (!file_exists($envPath)) {
            $this->warn("  .env file not found. Creating it...");
            file_put_contents($envPath, '');
        }

        $envContent = file_get_contents($envPath);
        $escapedValue = $this->escapeEnvValue($value);

        // Controlla se la chiave esiste già
        if (preg_match("/^{$key}=.*/m", $envContent)) {
            // Aggiorna il valore esistente
            $envContent = preg_replace(
                "/^{$key}=.*/m",
                "{$key}={$escapedValue}",
                $envContent
            );
        } else {
            // Aggiungi la nuova chiave
            $envContent .= "\n{$key}={$escapedValue}\n";
        }

        file_put_contents($envPath, $envContent);
    }

    protected function escapeEnvValue(string $value): string
    {
        if (preg_match('/[\s#"]/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }

        return $value;
    }

    protected function maskToken(string $token): string
    {
        $parts = explode(':', $token);
        if (count($parts) === 2) {
            return $parts[0] . ':' . str_repeat('*', min(20, strlen($parts[1])));
        }
        return str_repeat('*', 20);
    }
}
