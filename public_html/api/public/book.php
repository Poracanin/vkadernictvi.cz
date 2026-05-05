<?php
/**
 * POST /api/public/book.php
 *
 * Vytvoří novou rezervaci (status pending) a pošle potvrzovací mail zákazníkovi.
 *
 * Tělo (JSON):
 *   {
 *     "service_id":  Number,
 *     "date":        "YYYY-MM-DD",
 *     "time":        "HH:MM",
 *     "name":        String,
 *     "email":       String,
 *     "phone":       String,
 *     "note":        String?,
 *     "website":     String?    // honeypot – pokud vyplněn, tichý 200
 *   }
 *
 * Odpověď: { ok: true, data: { booking_id, cancel_url } }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/booking_helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';

require_method('POST');

try {
    $body = read_json_body();

    // -------------------------------------------------------------
    // Honeypot – pokud bot vyplnil "website", tvař se že je vše OK
    // -------------------------------------------------------------
    if (!empty($body['website'])) {
        log_event('bookings', 'WARN', 'Honeypot triggered, request ignored', 'public');
        json_response(['ok' => true, 'data' => ['booking_id' => 0, 'cancel_url' => null]]);
    }

    // -------------------------------------------------------------
    // Validace vstupů
    // -------------------------------------------------------------
    $serviceId = v_int($body['service_id'] ?? null, 'service_id', 1);
    $date      = v_date($body['date'] ?? '', 'date');
    $time      = v_time($body['time'] ?? '', 'time'); // vrátí HH:MM:SS
    $name      = v_string($body['name']  ?? '', 'jméno', 2, 120);
    $email     = v_email($body['email']  ?? '', 'E-mail');
    $phone     = v_phone($body['phone']  ?? '', 'Telefon');
    $note      = isset($body['note']) && is_string($body['note'])
        ? mb_substr(trim($body['note']), 0, 1000, 'UTF-8')
        : null;

    $ip  = client_ip();
    $pdo = db();

    // -------------------------------------------------------------
    // Rate limit – max N rezervací z 1 IP za posledních 60 min
    // -------------------------------------------------------------
    $maxPerHour = (int)($CONFIG['booking']['rate_limit_per_hour'] ?? 5);
    $rl = $pdo->prepare(
        'SELECT COUNT(*) AS cnt FROM bookings
          WHERE ip_address = :ip
            AND created_at >= (NOW() - INTERVAL 1 HOUR)'
    );
    $rl->execute([':ip' => $ip]);
    $cnt = (int)$rl->fetchColumn();
    if ($cnt >= $maxPerHour) {
        log_event('bookings', 'WARN', "Rate limit hit ip=$ip count=$cnt", 'public');
        json_response(['error' => 'Překročili jste limit rezervací z této adresy. Zkuste to prosím za hodinu.'], 429);
    }

    // -------------------------------------------------------------
    // Transakce + zámek slotu (race-condition safe)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    try {
        $check = lock_and_validate_slot($pdo, $date, $time, $serviceId);
        if (!$check['ok']) {
            $pdo->rollBack();
            json_response(['error' => $check['reason']], 409);
        }

        $token = bin2hex(random_bytes(32));
        $startAt = "$date $time";
        $endAt   = $check['end_at'];

        $ins = $pdo->prepare(
            'INSERT INTO bookings
                (service_id, start_at, end_at, customer_name, customer_email,
                 customer_phone, note, status, cancel_token, ip_address)
             VALUES
                (:sid, :start, :end, :name, :email, :phone, :note, "pending", :tok, :ip)'
        );
        $ins->execute([
            ':sid'   => $serviceId,
            ':start' => $startAt,
            ':end'   => $endAt,
            ':name'  => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':note'  => $note,
            ':tok'   => $token,
            ':ip'    => $ip,
        ]);
        $bookingId = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // -------------------------------------------------------------
    // Pošli potvrzovací mail (mimo transakci)
    // -------------------------------------------------------------
    $sel = $pdo->prepare(
        'SELECT b.id, b.start_at, b.end_at, b.customer_name, b.customer_email,
                b.customer_phone, b.cancel_token,
                s.name AS service_name, s.duration_min, s.price
           FROM bookings b
           JOIN services s ON s.id = b.service_id
          WHERE b.id = :id'
    );
    $sel->execute([':id' => $bookingId]);
    $booking = $sel->fetch();

    @mail_booking_received($booking);

    log_event('bookings', 'INFO',
        sprintf('[BOOKING] [public] Nová rezervace #%d od %s (%s %s)', $bookingId, $email, $date, substr($time, 0, 5)),
        'public'
    );

    $cancelUrl = rtrim((string)($CONFIG['site']['url'] ?? ''), '/')
        . '/cancel.php?token=' . rawurlencode($token);

    json_response([
        'ok'   => true,
        'data' => [
            'booking_id' => $bookingId,
            'cancel_url' => $cancelUrl,
        ],
    ]);

} catch (ValidationException $e) {
    json_response(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    log_event('errors', 'ERROR', 'book.php: ' . $e->getMessage());
    json_response(['error' => 'Nepodařilo se uložit rezervaci. Zkuste to prosím znovu.'], 500);
}
