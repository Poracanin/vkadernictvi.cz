<?php
/**
 * mailer.php
 *
 * Tenká vrstva nad PHP mail(). Podporuje:
 *   - šablony /templates/emails/*.html s {{placeholdery}}
 *   - HTML mail (UTF-8) s base64-zakódovaným předmětem (kvůli diakritice)
 *   - 5. parametr -f pro envelope sender (lepší doručitelnost)
 *
 * Veškerá odeslání i selhání jdou do logs/mail.log.
 */

declare(strict_types=1);

/**
 * Načte šablonu a nahradí {{placeholdery}}.
 *
 * @param string $template  Název souboru bez přípony (booking_received apod.)
 * @param array  $vars      Mapa name => value (HTML se autoescapuje)
 */
function render_email_template(string $template, array $vars): string
{
    global $CONFIG;
    $path = ($CONFIG['paths']['template_dir'] ?? __DIR__ . '/../templates') . '/emails/' . $template . '.html';
    if (!is_file($path)) {
        throw new RuntimeException("Mail template '$template' not found: $path");
    }
    $html = file_get_contents($path);

    // Doplň výchozí proměnné webu, pokud nejsou předány explicitně
    $vars += [
        'site_name' => $CONFIG['site']['name'] ?? '',
        'site_url'  => $CONFIG['site']['url'] ?? '',
    ];

    // Náhrada {{key}} → escapovaná hodnota
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($vars) {
        $key = $m[1];
        if (!array_key_exists($key, $vars)) {
            return '';
        }
        return e((string)$vars[$key]);
    }, $html);
}

/**
 * Pošle HTML mail. Vrací bool úspěch a paralelně loguje.
 */
function send_mail(string $to, string $subject, string $htmlBody): bool
{
    global $CONFIG;

    $from        = $CONFIG['site']['admin_email'] ?? 'no-reply@localhost';
    $replyTo     = $CONFIG['site']['reply_to']    ?? $from;
    $envelope    = $CONFIG['site']['envelope_sender'] ?? $from;
    $siteName    = $CONFIG['site']['name'] ?? 'Web';

    // Předmět zakódovaný do base64 UTF-8 (kvůli českým znakům)
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    // Bezpečné From s jménem firmy
    $fromName = '=?UTF-8?B?' . base64_encode($siteName) . '?=';
    $fromHeader = "$fromName <$from>";

    $headers   = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'X-Mailer: vkadernictvi-mailer/1.0';
    $headers[] = 'Date: ' . date('r');

    // Odřádkuj těla (mail() na některých systémech vyžaduje LF, jinde CRLF)
    $body = preg_replace("/\r\n|\r/", "\n", $htmlBody);

    // -f param pro envelope sender
    $additional = '-f' . escapeshellarg_safe($envelope);

    // Validace adresy (mail() s newline by byl injection)
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        log_event('mail', 'ERROR', "Invalid recipient '$to' subject='$subject'");
        return false;
    }

    $ok = @mail(
        $to,
        $encodedSubject,
        $body,
        implode("\r\n", $headers),
        $additional
    );

    log_event('mail', $ok ? 'INFO' : 'ERROR', sprintf(
        '%s to=%s subject="%s"',
        $ok ? 'SENT' : 'FAIL',
        $to,
        $subject
    ));

    return (bool)$ok;
}

/**
 * Bezpečnější escapeshellarg na Windows (kde escapeshellarg má jiné chování).
 * Pro envelope sender stačí defaultní escapeshellarg.
 */
function escapeshellarg_safe(string $arg): string
{
    return escapeshellarg($arg);
}

// =====================================================================
// Vrstva pro konkrétní typy mailů – sjednocuje data, předměty, šablony
// =====================================================================

/**
 * Sestaví společný kontext pro mailové šablony z bookingu (řádek z DB s joinem na services).
 *
 * Očekává klíče: customer_name, service_name, start_at, duration_min, price, cancel_token
 */
function build_booking_mail_vars(array $b): array
{
    global $CONFIG;

    $start = new DateTime($b['start_at']);

    $price = $b['price'] !== null && $b['price'] !== ''
        ? number_format((float)$b['price'], 0, ',', ' ') . ' Kč'
        : 'na dotaz';

    $cancelUrl = rtrim((string)($CONFIG['site']['url'] ?? ''), '/')
        . '/cancel.php?token=' . rawurlencode((string)$b['cancel_token']);

    return [
        'customer_name' => (string)$b['customer_name'],
        'service_name'  => (string)$b['service_name'],
        'date'          => $start->format('j. n. Y'),
        'time'          => $start->format('H:i'),
        'duration'      => (string)(int)$b['duration_min'],
        'price'         => $price,
        'cancel_url'    => $cancelUrl,
    ];
}

/**
 * Mail po vytvoření rezervace (zákazníkovi).
 */
function mail_booking_received(array $booking): bool
{
    $vars = build_booking_mail_vars($booking);
    $subject = 'Rezervace přijata – čeká na potvrzení';
    $html = render_email_template('booking_received', $vars);
    return send_mail($booking['customer_email'], $subject, $html);
}

/**
 * Mail po potvrzení rezervace adminem.
 */
function mail_booking_confirmed(array $booking): bool
{
    $vars = build_booking_mail_vars($booking);
    $subject = 'Rezervace potvrzena';
    $html = render_email_template('booking_confirmed', $vars);
    return send_mail($booking['customer_email'], $subject, $html);
}

/**
 * Mail po přesunu rezervace.
 *
 * @param string $oldStartAt DATETIME původního termínu (před update)
 */
function mail_booking_rescheduled(array $booking, string $oldStartAt): bool
{
    $vars = build_booking_mail_vars($booking);
    $oldDt = new DateTime($oldStartAt);
    $vars['old_datetime'] = $oldDt->format('j. n. Y · H:i');

    $subject = 'Změna termínu rezervace';
    $html = render_email_template('booking_rescheduled', $vars);
    return send_mail($booking['customer_email'], $subject, $html);
}

/**
 * Mail po zrušení rezervace.
 */
function mail_booking_cancelled(array $booking): bool
{
    $vars = build_booking_mail_vars($booking);
    $subject = 'Rezervace zrušena';
    $html = render_email_template('booking_cancelled', $vars);
    return send_mail($booking['customer_email'], $subject, $html);
}
