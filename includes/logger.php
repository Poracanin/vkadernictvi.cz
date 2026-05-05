<?php
/**
 * logger.php
 *
 * Jednoduchý souborový logger. Zapisuje do logs/{file}.log ve formátu:
 *     [2026-05-04 14:23:11] [INFO] [192.168.1.5] [faris] Zpráva tady
 *
 * Použití:
 *     log_event('actions', 'INFO', 'action=confirm booking=#142', 'faris');
 *     log_event('errors', 'ERROR', 'PDOException: ...');
 */

declare(strict_types=1);

/**
 * Zapíše řádek do požadovaného log souboru.
 *
 * @param string  $file    Název bez přípony (access, actions, bookings, mail, errors)
 * @param string  $level   INFO | WARN | ERROR | DEBUG
 * @param string  $message Volný text
 * @param ?string $user    Volitelně uživatel (např. 'faris' nebo 'public')
 */
function log_event(string $file, string $level, string $message, ?string $user = null): void
{
    global $CONFIG;

    $logDir = $CONFIG['paths']['log_dir'] ?? (__DIR__ . '/../logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $userPart = $user !== null ? '[' . $user . ']' : '[-]';
    $line = sprintf(
        "[%s] [%s] [%s] %s %s%s",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $ip,
        $userPart,
        str_replace(["\r", "\n"], ' ', $message),
        PHP_EOL
    );

    $path = $logDir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $file) . '.log';
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Zaloguje aktuálního přihlášeného admina (nebo 'public' pro neautorizované akce).
 */
function current_log_user(): string
{
    return $_SESSION['admin_user'] ?? 'public';
}
