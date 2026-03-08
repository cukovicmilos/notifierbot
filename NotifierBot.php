<?php
date_default_timezone_set('Europe/Belgrade');

/**
 * NotifierBot - Univerzalni Telegram Notifikacioni Servis
 *
 * Korišćenje iz PHP-a:
 *   require_once '/var/www/html/notifierbot/NotifierBot.php';
 *   NotifierBot::send('backup', 'Backup završen', ['size' => '2.5MB']);
 *
 * Korišćenje iz CLI:
 *   /var/www/html/notifierbot/notify backup "Backup završen" --size="2.5MB"
 */

class NotifierBot
{
    private static ?string $botToken = null;
    private static ?string $chatId = null;
    private static bool $initialized = false;

    /**
     * Emoji i naslovi prema tipu notifikacije
     */
    private static array $types = [
        'backup' => ['emoji' => '🗄️', 'title' => 'BACKUP ZAVRŠEN'],
        'registration' => ['emoji' => '👤', 'title' => 'NOVA REGISTRACIJA'],
        'error' => ['emoji' => '❌', 'title' => 'GREŠKA'],
        'warning' => ['emoji' => '⚠️', 'title' => 'UPOZORENJE'],
        'info' => ['emoji' => 'ℹ️', 'title' => 'INFO'],
        'success' => ['emoji' => '✅', 'title' => 'USPEH'],
        'test' => ['emoji' => '🔔', 'title' => 'TEST'],
        'digest' => ['emoji' => '📋', 'title' => 'DNEVNI GITLAB IZVEŠTAJ'],
        'monitor' => ['emoji' => '🔍', 'title' => 'SISTEMSKI IZVEŠTAJ'],
    ];

    /**
     * Učitaj credentials iz .env fajla
     */
    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $envFile = __DIR__ . '/.env';

        if (!file_exists($envFile)) {
            throw new RuntimeException("Fajl .env ne postoji u " . __DIR__);
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Preskoči komentare
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if ($key === 'TELEGRAM_BOT_TOKEN') {
                    self::$botToken = $value;
                } elseif ($key === 'TELEGRAM_CHAT_ID') {
                    self::$chatId = $value;
                }
            }
        }

        if (empty(self::$botToken) || empty(self::$chatId)) {
            throw new RuntimeException("TELEGRAM_BOT_TOKEN i TELEGRAM_CHAT_ID moraju biti definisani u .env");
        }

        self::$initialized = true;
    }

    /**
     * Pošalji notifikaciju na Telegram
     *
     * @param string $type Tip notifikacije (backup, registration, error, info, test, ...)
     * @param string $message Glavna poruka
     * @param array $extras Dodatni parametri za prikaz (npr. ['size' => '2.5MB', 'file' => 'backup.zip'])
     * @return bool True ako je poruka uspešno poslata
     */
    public static function send(string $type, string $message, array $extras = []): bool
    {
        self::init();

        $formattedMessage = self::formatMessage($type, $message, $extras);

        return self::sendToTelegram($formattedMessage);
    }

    /**
     * Formatiraj poruku prema tipu
     */
    private static function formatMessage(string $type, string $message, array $extras): string
    {
        $typeConfig = self::$types[$type] ?? ['emoji' => '📢', 'title' => strtoupper($type)];

        $lines = [];

        // Naslov
        $lines[] = $typeConfig['emoji'] . ' *' . $typeConfig['title'] . '*';
        $lines[] = '';

        // Glavna poruka (escape user content)
        $lines[] = self::escapeMarkdown($message);

        // Extra parametri
        if (!empty($extras)) {
            $lines[] = '';

            foreach ($extras as $key => $value) {
                $emoji = self::getExtraEmoji($key);
                $label = self::getExtraLabel($key);
                $lines[] = $emoji . ' ' . $label . ': ' . self::escapeMarkdown($value);
            }
        }

        // Timestamp
        $lines[] = '';
        $lines[] = '🕐 Vreme: ' . date('Y-m-d H:i:s');

        return implode("\n", $lines);
    }

    /**
     * Emoji za extra parametre
     */
    private static function getExtraEmoji(string $key): string
    {
        $emojis = [
            'file' => '📦',
            'size' => '📊',
            'destination' => '☁️',
            'app' => '📱',
            'application' => '📱',
            'user' => '👤',
            'username' => '👤',
            'email' => '📧',
            'ip' => '🌐',
            'duration' => '⏱️',
            'count' => '🔢',
            'path' => '📁',
            'server' => '🖥️',
        ];

        return $emojis[strtolower($key)] ?? '•';
    }

    /**
     * Label za extra parametre
     */
    private static function getExtraLabel(string $key): string
    {
        $labels = [
            'file' => 'Fajl',
            'size' => 'Veličina',
            'destination' => 'Destinacija',
            'app' => 'Aplikacija',
            'application' => 'Aplikacija',
            'user' => 'Korisnik',
            'username' => 'Korisnik',
            'email' => 'Email',
            'ip' => 'IP adresa',
            'duration' => 'Trajanje',
            'count' => 'Broj',
            'path' => 'Putanja',
            'server' => 'Server',
        ];

        return $labels[strtolower($key)] ?? ucfirst($key);
    }

    /**
     * Escape Markdown v1 special characters
     */
    private static function escapeMarkdown(string $text): string
    {
        // Markdown v1 special chars: * _ ` [
        $special = ['*', '_', '`', '['];

        foreach ($special as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }

        return $text;
    }

    /**
     * Pošalji poruku preko Telegram Bot API
     */
    private static function sendToTelegram(string $message): bool
    {
        $url = 'https://api.telegram.org/bot' . self::$botToken . '/sendMessage';

        $data = [
            'chat_id' => self::$chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("NotifierBot cURL error: " . $error);
            return false;
        }

        if ($httpCode !== 200) {
            error_log("NotifierBot API error (HTTP $httpCode): " . $response);
            return false;
        }

        $result = json_decode($response, true);

        return isset($result['ok']) && $result['ok'] === true;
    }
}
