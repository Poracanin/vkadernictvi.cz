<?php
/**
 * csrf.php
 *
 * Vystavení a ověření CSRF tokenu pro admin rozhraní.
 *
 * Token je uložen v session pod klíčem _csrf_token a posílá se
 * z prohlížeče v hlavičce X-CSRF-Token (pro AJAX) nebo skrytém poli.
 */

declare(strict_types=1);

/**
 * Vrátí (a v případě potřeby vygeneruje) CSRF token aktuální session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Vyžádá si platný CSRF token z požadavku. Při nesouladu odpoví 403.
 *
 * Pořadí zdrojů:
 *   1) HTTP hlavička X-CSRF-Token
 *   2) POST/JSON pole 'csrf_token'
 */
function require_csrf(): void
{
    $expected = $_SESSION['_csrf_token'] ?? null;
    if (!$expected) {
        json_response(['error' => 'CSRF token chybí v session.'], 403);
    }

    $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!$given) {
        // Zkus získat z POST nebo JSON body
        if (!empty($_POST['csrf_token'])) {
            $given = $_POST['csrf_token'];
        } else {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j) && !empty($j['csrf_token'])) {
                    $given = $j['csrf_token'];
                }
            }
        }
    }

    if (!is_string($given) || !hash_equals($expected, $given)) {
        log_event('errors', 'WARN', 'CSRF token mismatch', current_log_user());
        json_response(['error' => 'Neplatný CSRF token.'], 403);
    }
}
