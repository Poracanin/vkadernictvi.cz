<?php
/**
 * bootstrap.php
 *
 * Společný startovací soubor – načte config, inicializuje session, PDO,
 * exception handler a několik helperů. Includuje se na začátku každého
 * PHP endpointu.
 */

declare(strict_types=1);

// ---------- Načtení configu ----------
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Config file is missing. Copy config.example.php to config.php.']);
    exit;
}

/** @var array $CONFIG */
$CONFIG = require $configFile;

// ---------- Časová zóna ----------
date_default_timezone_set($CONFIG['site']['timezone'] ?? 'Europe/Prague');

// ---------- Error reporting ----------
$IS_DEBUG = !empty($CONFIG['debug']);
error_reporting(E_ALL);
ini_set('display_errors', $IS_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
@mkdir($CONFIG['paths']['log_dir'], 0750, true);
ini_set('error_log', $CONFIG['paths']['log_dir'] . '/php_errors.log');

// ---------- Helpery: logger, validator, csrf ----------
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/csrf.php';

// ---------- Session ----------
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($CONFIG['auth']['cookie_secure']),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookieParams);
    session_name('VKAD_SESS');
    session_start();

    // Auto-regenerace session ID každých N minut (anti session-fixation)
    $regenMin = (int)($CONFIG['auth']['session_regen_min'] ?? 30);
    if (!isset($_SESSION['_regen_at'])) {
        $_SESSION['_regen_at'] = time();
    } elseif (time() - (int)$_SESSION['_regen_at'] > $regenMin * 60) {
        session_regenerate_id(true);
        $_SESSION['_regen_at'] = time();
    }
}

// ---------- PDO ----------
/**
 * Vrátí (singleton) PDO připojení.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    global $CONFIG;
    $c = $CONFIG['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'], (int)$c['port'], $c['dbname'], $c['charset']
    );
    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+01:00', NAMES utf8mb4 COLLATE utf8mb4_czech_ci",
    ]);
    return $pdo;
}

// ---------- Vlastní výjimky ----------
class ValidationException extends RuntimeException {}
class ApiException extends RuntimeException
{
    public int $status;
    public function __construct(string $msg, int $status = 400) {
        parent::__construct($msg);
        $this->status = $status;
    }
}

// ---------- Globální exception handler ----------
set_exception_handler(static function (Throwable $e): void {
    global $IS_DEBUG;
    log_event('errors', 'ERROR', sprintf(
        '%s in %s:%d — %s',
        get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
    ));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => 'Vnitřní chyba serveru.',
        'detail' => $IS_DEBUG ? $e->getMessage() : null,
    ]);
    exit;
});

// ---------- Helpery pro JSON API ----------
/**
 * Pošle JSON odpověď a ukončí běh.
 */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Načte a zparsuje JSON body z requestu.
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ValidationException('Neplatný JSON v těle požadavku.');
    }
    return $data;
}

/**
 * Vyžádá si konkrétní HTTP metodu nebo vrátí 405.
 *
 * @param string|string[] $allowed
 */
function require_method($allowed): string
{
    $allowed = (array)$allowed;
    $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_response(['error' => 'Metoda není povolena.'], 405);
    }
    return $method;
}

/**
 * Vrátí IP adresu klienta (jednoduše – bez proxy whitelistů).
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Pokud běžíš za reverzní proxy a věříš jí, lze odkomentovat:
    // if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    //     $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    //     $ip = trim($parts[0]);
    // }
    return $ip;
}

/**
 * Bezpečně escapuje string pro vložení do HTML.
 */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
