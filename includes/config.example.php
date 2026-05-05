<?php
/**
 * config.example.php
 *
 * Šablona konfigurace. Zkopíruj na config.php a vyplň skutečné hodnoty:
 *     cp includes/config.example.php includes/config.php
 *
 * Soubor config.php je v .gitignore – nikdy ho necommituj.
 */

return [

    // ---------- DATABÁZE ----------
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => 'vkadernictvi',
        'user'     => 'vkadernictvi_user',
        'password' => 'TvojeSilneHesloDb',
        'charset'  => 'utf8mb4',
    ],

    // ---------- ADMIN ÚČET ----------
    // Hash vygeneruj přes:
    //   php -r "echo password_hash('TVOJE_HESLO', PASSWORD_BCRYPT) . PHP_EOL;"
    // Vlož celý výstup (~60 znaků začíná $2y$).
    'admin' => [
        'username'      => 'faris',
        'password_hash' => '$2y$10$REPLACE_WITH_YOUR_BCRYPT_HASH_HEREXXXXXXXXXXXXXXXXXXXX',
    ],

    // ---------- WEB / FIRMA ----------
    'site' => [
        'name'             => 'Vkadeřnictví',
        'url'              => 'https://www.vkadernictvi.cz',
        'admin_email'      => 'rezervace@vkadernictvi.cz',
        'reply_to'         => 'rezervace@vkadernictvi.cz',
        'envelope_sender'  => 'rezervace@vkadernictvi.cz', // pro mail() -f parametr
        'timezone'         => 'Europe/Prague',
    ],

    // ---------- BUSINESS PRAVIDLA ----------
    'booking' => [
        'slot_grid_min'         => 15,   // mřížka slotů v minutách
        'min_ahead_min'         => 60,   // pro dnešek minimálně X min do startu
        'max_days_ahead'        => 90,   // jak daleko dopředu lze rezervovat
        'rate_limit_per_hour'   => 5,    // max rezervací z 1 IP / hodinu
    ],

    // ---------- AUTH ----------
    'auth' => [
        'max_attempts'      => 5,
        'lockout_minutes'   => 15,
        'session_regen_min' => 30,
        'cookie_secure'     => false, // nasaď na true v produkci s HTTPS
    ],

    // ---------- CESTY (absolutní) ----------
    'paths' => [
        'log_dir'      => __DIR__ . '/../logs',
        'template_dir' => __DIR__ . '/../templates',
    ],

    // ---------- DEBUG ----------
    // V produkci nastav 'debug' => false. Při debug=true se chyby zobrazují.
    'debug' => false,
];
