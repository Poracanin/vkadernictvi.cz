<?php
/**
 * auth.php
 *
 * Přihlašování admina, odhlášení, guard pro chráněné stránky a API,
 * ochrana proti bruteforce přes tabulku login_attempts.
 *
 * Předpokládá, že už proběhl bootstrap (PDO, session, logger).
 */

declare(strict_types=1);

/**
 * Vrátí true, pokud je v aktuální session přihlášený admin.
 */
function is_admin_logged_in(): bool
{
    return !empty($_SESSION['admin_user']) && !empty($_SESSION['admin_logged_at']);
}

/**
 * Pokus o přihlášení. Při úspěchu nastaví session a vrátí true.
 * Při selhání ukládá pokus do login_attempts.
 *
 * @throws ApiException 423 při zamčení účtu (lockout)
 * @throws ApiException 401 při špatných přihlašovacích údajích
 */
function login_admin(string $username, string $password): bool
{
    global $CONFIG;

    $ip = client_ip();

    // 1) Zkontroluj lockout
    $lock = check_lockout($ip);
    if ($lock !== null) {
        log_event('access', 'WARN', "Login blocked (locked) user=$username", 'public');
        throw new ApiException(
            "Příliš mnoho neúspěšných pokusů. Zkus to znovu v $lock.",
            423
        );
    }

    // 2) Ověř přihlašovací údaje
    $expectedUser = (string)($CONFIG['admin']['username'] ?? '');
    $expectedHash = (string)($CONFIG['admin']['password_hash'] ?? '');

    $userMatches = hash_equals($expectedUser, $username);
    $passOk      = $userMatches && password_verify($password, $expectedHash);

    if (!$passOk) {
        register_failed_attempt($ip);
        log_event('access', 'WARN', "Login FAIL user=$username", 'public');
        throw new ApiException('Neplatné přihlašovací údaje.', 401);
    }

    // 3) Úspěch – vyčisti pokusy, regeneruj session ID, ulož data
    clear_attempts($ip);
    session_regenerate_id(true);
    $_SESSION['admin_user']      = $expectedUser;
    $_SESSION['admin_logged_at'] = time();
    $_SESSION['_regen_at']       = time();

    log_event('access', 'INFO', "Login OK user=$expectedUser", $expectedUser);
    return true;
}

/**
 * Odhlášení admina – vyčistí session.
 */
function logout_admin(): void
{
    $user = $_SESSION['admin_user'] ?? '-';
    log_event('access', 'INFO', "Logout user=$user", $user);

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            (bool)$params['secure'], (bool)$params['httponly']
        );
    }
    session_destroy();
}

/**
 * Guard pro HTML admin stránky – při neautorizovaném přístupu redirect na login.
 */
function require_admin_html(): void
{
    if (!is_admin_logged_in()) {
        header('Location: /admin/login.php');
        exit;
    }
}

/**
 * Guard pro API – při neautorizovaném přístupu vrátí JSON 401 a ukončí.
 */
function require_admin_api(): void
{
    if (!is_admin_logged_in()) {
        json_response(['error' => 'Vyžaduje přihlášení.'], 401);
    }
}

// =====================================================================
// Bruteforce ochrana – tabulka login_attempts
// =====================================================================

/**
 * Vrátí lidsky čitelný čas zbývajícího lockoutu, nebo null pokud je IP volná.
 */
function check_lockout(string $ip): ?string
{
    $row = db()->prepare(
        'SELECT attempts, locked_until FROM login_attempts WHERE ip_address = :ip'
    );
    $row->execute([':ip' => $ip]);
    $r = $row->fetch();
    if (!$r) {
        return null;
    }
    if (!empty($r['locked_until'])) {
        $until = strtotime($r['locked_until']);
        if ($until && $until > time()) {
            $secs = $until - time();
            $mins = (int)ceil($secs / 60);
            return "$mins min";
        }
    }
    return null;
}

/**
 * Zaeviduje neúspěšný pokus. Po překročení limitu nastaví locked_until.
 */
function register_failed_attempt(string $ip): void
{
    global $CONFIG;
    $maxAttempts   = (int)($CONFIG['auth']['max_attempts'] ?? 5);
    $lockoutMin    = (int)($CONFIG['auth']['lockout_minutes'] ?? 15);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare(
            'SELECT attempts FROM login_attempts WHERE ip_address = :ip FOR UPDATE'
        );
        $sel->execute([':ip' => $ip]);
        $row = $sel->fetch();

        if (!$row) {
            $ins = $pdo->prepare(
                'INSERT INTO login_attempts (ip_address, attempts, last_attempt)
                 VALUES (:ip, 1, NOW())'
            );
            $ins->execute([':ip' => $ip]);
        } else {
            $newCount = ((int)$row['attempts']) + 1;
            $lockUntil = null;
            if ($newCount >= $maxAttempts) {
                $lockUntil = date('Y-m-d H:i:s', time() + $lockoutMin * 60);
            }
            $upd = $pdo->prepare(
                'UPDATE login_attempts
                    SET attempts = :n, last_attempt = NOW(), locked_until = :lu
                  WHERE ip_address = :ip'
            );
            $upd->execute([
                ':n'  => $newCount,
                ':lu' => $lockUntil,
                ':ip' => $ip,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Vyčistí počítadlo neúspěšných pokusů pro IP po úspěšném loginu.
 */
function clear_attempts(string $ip): void
{
    $del = db()->prepare('DELETE FROM login_attempts WHERE ip_address = :ip');
    $del->execute([':ip' => $ip]);
}
