<?php
/**
 * /admin/login.php
 *
 * Přihlašovací stránka admina. Vlastní inline CSS (dark + zlatá), aby
 * nebyla závislá na žádném externím souboru. Bez Tailwindu, bez frameworků.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

// Pokud už jsem přihlášený, jdi rovnou do administrace
if (is_admin_logged_in()) {
    header('Location: /admin/');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token z formuláře
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || empty($_SESSION['_csrf_token']) || !hash_equals($_SESSION['_csrf_token'], $sent)) {
        $error = 'Neplatný CSRF token. Obnov stránku a zkus to znovu.';
        log_event('errors', 'WARN', 'CSRF mismatch on login', 'public');
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            login_admin($username, $password);
            header('Location: /admin/');
            exit;
        } catch (ApiException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = 'Při přihlášení nastala chyba.';
            log_event('errors', 'ERROR', 'Login exception: ' . $e->getMessage());
        }
    }
}

$csrf = csrf_token();
?><!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Přihlášení | Vkadeřnictví Admin</title>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600&family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: 'Montserrat', sans-serif;
            background: #0a0a0a;
            color: #d1d5db;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background-image:
                radial-gradient(circle at 20% 20%, rgba(197,160,89,0.08), transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(197,160,89,0.06), transparent 40%);
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: #141414;
            border: 1px solid #262626;
            border-radius: 12px;
            padding: 40px 32px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6);
        }
        .login-logo {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-logo .icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px; height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ebd197 0%, #c5a059 50%, #9c7b3b 100%);
            color: #0a0a0a;
            font-size: 24px;
            margin-bottom: 12px;
        }
        .login-logo h1 {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            font-size: 24px;
            margin: 0;
            background: linear-gradient(to bottom, #ebd197 0%, #c5a059 60%, #9c7b3b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .login-logo p {
            font-size: 13px;
            color: #888;
            margin: 6px 0 0 0;
            letter-spacing: 0.5px;
        }
        label {
            display: block;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #c5a059;
            margin-bottom: 6px;
        }
        .field { margin-bottom: 18px; }
        .input-wrap {
            position: relative;
        }
        .input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #555;
            font-size: 14px;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px 14px 12px 40px;
            background: #0a0a0a;
            border: 1px solid #2a2a2a;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.25s ease;
        }
        input:focus {
            outline: none;
            border-color: #c5a059;
            box-shadow: 0 0 0 3px rgba(197,160,89,0.15);
        }
        button.submit {
            width: 100%;
            padding: 13px 16px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #ebd197 0%, #c5a059 50%, #9c7b3b 100%);
            color: #0a0a0a;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.25s ease;
        }
        button.submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(197,160,89,0.25);
        }
        .alert {
            background: rgba(220, 38, 38, 0.1);
            border: 1px solid rgba(220, 38, 38, 0.4);
            color: #fca5a5;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
        }
        .footer-link {
            text-align: center;
            margin-top: 22px;
            font-size: 12px;
            color: #666;
        }
        .footer-link a { color: #c5a059; text-decoration: none; }
        .footer-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main class="login-card">
        <div class="login-logo">
            <div class="icon"><i class="fas fa-cut"></i></div>
            <h1>Vkadeřnictví</h1>
            <p>Administrace</p>
        </div>

        <?php if ($error !== null): ?>
            <div class="alert"><i class="fas fa-triangle-exclamation"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/login.php" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="field">
                <label for="username">Uživatel</label>
                <div class="input-wrap">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" required
                           autocomplete="username"
                           value="<?= e($_POST['username'] ?? '') ?>">
                </div>
            </div>

            <div class="field">
                <label for="password">Heslo</label>
                <div class="input-wrap">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" required
                           autocomplete="current-password">
                </div>
            </div>

            <button type="submit" class="submit">
                <i class="fas fa-sign-in-alt"></i>&nbsp; Přihlásit se
            </button>
        </form>

        <p class="footer-link">
            <a href="/">← Zpět na web</a>
        </p>
    </main>
</body>
</html>
