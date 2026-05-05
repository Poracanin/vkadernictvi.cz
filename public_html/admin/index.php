<?php
/**
 * /admin/index.php
 *
 * Guard pro administraci. Pokud je uživatel přihlášený, načte admin.html
 * a vstříkne do <head> meta tag s CSRF tokenem (kvůli AJAX volání).
 *
 * Tím pádem k admin.html nikdy nelze přistoupit přímo bez přihlášení
 * (.htaccess v této složce navíc zakazuje přímý přístup k admin.html).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_admin_html();

// Načti admin.html ze stejné složky
$htmlFile = __DIR__ . '/admin.html';
if (!is_file($htmlFile)) {
    http_response_code(500);
    echo 'Chybí soubor admin.html v /admin/ – přesuň ho prosím do public_html/admin/.';
    exit;
}

$html = file_get_contents($htmlFile);
$csrf = e(csrf_token());
$user = e($_SESSION['admin_user'] ?? '');

// Vlož CSRF meta a info o uživateli těsně za <head>
$inject = "\n    <meta name=\"csrf-token\" content=\"{$csrf}\">\n"
        . "    <meta name=\"admin-user\" content=\"{$user}\">\n";

if (preg_match('/<head[^>]*>/i', $html)) {
    $html = preg_replace('/(<head[^>]*>)/i', '$1' . $inject, $html, 1);
} else {
    // Fallback – předřaď jako první řádek
    $html = $inject . $html;
}

// Pokud už v souboru meta csrf-token byla statická, nahraď ji aktuální hodnotou
$html = preg_replace(
    '/<meta\s+name=["\']csrf-token["\']\s+content=["\'][^"\']*["\']\s*\/?>\s*/i',
    "<meta name=\"csrf-token\" content=\"{$csrf}\">",
    $html,
    1
);

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $html;
