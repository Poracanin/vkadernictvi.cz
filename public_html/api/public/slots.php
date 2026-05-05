<?php
/**
 * GET /api/public/slots.php?date=YYYY-MM-DD&service_id=X
 *
 * Vrátí pole volných časových slotů pro daný den a službu.
 * Slot není nabídnut, pokud start + duration > zavírací doba.
 *
 * Odpověď: { ok: true, data: { date, service_id, slots: ["09:00","09:15",...], closed: bool, reason: ?string } }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/booking_helpers.php';

require_method('GET');

try {
    $date      = v_date($_GET['date'] ?? '', 'date');
    $serviceId = v_int($_GET['service_id'] ?? '', 'service_id', 1);

    $pdo = db();

    $window = get_day_window($pdo, $date);
    if ($window['closed']) {
        json_response([
            'ok'   => true,
            'data' => [
                'date'       => $date,
                'service_id' => $serviceId,
                'slots'      => [],
                'closed'     => true,
                'reason'     => $window['reason'],
            ],
        ]);
    }

    $slots = compute_available_slots($pdo, $date, $serviceId);

    json_response([
        'ok'   => true,
        'data' => [
            'date'       => $date,
            'service_id' => $serviceId,
            'slots'      => $slots,
            'closed'     => false,
            'reason'     => null,
        ],
    ]);

} catch (ValidationException $e) {
    json_response(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    log_event('errors', 'ERROR', 'slots.php: ' . $e->getMessage());
    json_response(['error' => 'Nepodařilo se načíst dostupné termíny.'], 500);
}
