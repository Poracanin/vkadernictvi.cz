<?php
/**
 * GET /api/admin/stats.php
 *
 * Dashboard – počty pro úvodní obrazovku admina.
 *
 * Odpověď:
 *   {
 *     ok: true,
 *     data: {
 *       today:           { total, confirmed, pending, done },
 *       this_week:       { total, confirmed, pending },
 *       upcoming:        { confirmed, pending },
 *       pending_total:   N,
 *       last_7d_total:   N,
 *       next_booking:    { id, start_at, customer_name, service_name } | null
 *     }
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/auth.php';

require_admin_api();
require_method('GET');

try {
    $pdo = db();

    // Dnešek (00:00 - 23:59)
    $today = $pdo->prepare(
        "SELECT
             SUM(1) AS total,
             SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
             SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) AS pending,
             SUM(CASE WHEN status = 'done'      THEN 1 ELSE 0 END) AS done
           FROM bookings
          WHERE start_at >= CURDATE() AND start_at < (CURDATE() + INTERVAL 1 DAY)
            AND status NOT IN ('cancelled')"
    );
    $today->execute();
    $todayRow = $today->fetch() ?: [];

    // Tento týden (Po 00:00 → následující Po 00:00)
    $week = $pdo->prepare(
        "SELECT
             SUM(1) AS total,
             SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
             SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) AS pending
           FROM bookings
          WHERE start_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)
            AND start_at <  DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) + INTERVAL 7 DAY
            AND status NOT IN ('cancelled')"
    );
    $week->execute();
    $weekRow = $week->fetch() ?: [];

    // Nadcházející (od teď do +30 dní)
    $up = $pdo->prepare(
        "SELECT
             SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
             SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) AS pending
           FROM bookings
          WHERE start_at >= NOW() AND start_at < (NOW() + INTERVAL 30 DAY)"
    );
    $up->execute();
    $upRow = $up->fetch() ?: [];

    // Celkem čekajících (kdykoliv v budoucnosti)
    $pending = $pdo->prepare(
        "SELECT COUNT(*) FROM bookings
          WHERE status = 'pending' AND start_at >= NOW()"
    );
    $pending->execute();
    $pendingTotal = (int)$pending->fetchColumn();

    // Posledních 7 dní – kolik nových rezervací přišlo
    $last7 = $pdo->prepare(
        "SELECT COUNT(*) FROM bookings
          WHERE created_at >= (NOW() - INTERVAL 7 DAY)"
    );
    $last7->execute();
    $last7Total = (int)$last7->fetchColumn();

    // Nejbližší potvrzená nebo čekající rezervace
    $next = $pdo->prepare(
        "SELECT b.id, b.start_at, b.customer_name, b.status,
                s.name AS service_name
           FROM bookings b
           JOIN services s ON s.id = b.service_id
          WHERE b.start_at >= NOW()
            AND b.status IN ('pending','confirmed')
          ORDER BY b.start_at ASC
          LIMIT 1"
    );
    $next->execute();
    $nextRow = $next->fetch();
    $nextBooking = $nextRow ? [
        'id'            => (int)$nextRow['id'],
        'start_at'      => (string)$nextRow['start_at'],
        'customer_name' => (string)$nextRow['customer_name'],
        'service_name'  => (string)$nextRow['service_name'],
        'status'        => (string)$nextRow['status'],
    ] : null;

    $intify = static fn ($v): int => $v === null ? 0 : (int)$v;

    json_response([
        'ok'   => true,
        'data' => [
            'today' => [
                'total'     => $intify($todayRow['total']     ?? 0),
                'confirmed' => $intify($todayRow['confirmed'] ?? 0),
                'pending'   => $intify($todayRow['pending']   ?? 0),
                'done'      => $intify($todayRow['done']      ?? 0),
            ],
            'this_week' => [
                'total'     => $intify($weekRow['total']     ?? 0),
                'confirmed' => $intify($weekRow['confirmed'] ?? 0),
                'pending'   => $intify($weekRow['pending']   ?? 0),
            ],
            'upcoming' => [
                'confirmed' => $intify($upRow['confirmed'] ?? 0),
                'pending'   => $intify($upRow['pending']   ?? 0),
            ],
            'pending_total' => $pendingTotal,
            'last_7d_total' => $last7Total,
            'next_booking'  => $nextBooking,
        ],
    ]);

} catch (Throwable $e) {
    log_event('errors', 'ERROR', 'admin/stats.php: ' . $e->getMessage(), current_log_user());
    json_response(['error' => 'Nepodařilo se načíst statistiky.'], 500);
}
