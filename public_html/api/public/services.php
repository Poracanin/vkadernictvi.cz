<?php
/**
 * GET /api/public/services.php
 *
 * Veřejný seznam aktivních služeb pro frontend rezervace.
 * Bez loginu, bez CSRF (čistě read-only GET).
 *
 * Odpověď: { ok: true, data: [ {id, name, duration_min, price, icon, category, subcategory, description}, ... ] }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';

require_method('GET');

try {
    $stmt = db()->query(
        'SELECT id, name, duration_min, price, icon, category, subcategory, description, sort_order
           FROM services
          WHERE is_active = 1
          ORDER BY sort_order ASC, id ASC'
    );
    $rows = $stmt->fetchAll();

    // Lehké přetypování pro front-end
    $data = array_map(static function (array $r): array {
        return [
            'id'           => (int)$r['id'],
            'name'         => (string)$r['name'],
            'duration_min' => (int)$r['duration_min'],
            'price'        => $r['price'] !== null ? (float)$r['price'] : null,
            'icon'         => $r['icon'],
            'category'     => $r['category'],
            'subcategory'  => $r['subcategory'],
            'description'  => $r['description'] ?? '',
        ];
    }, $rows);

    json_response(['ok' => true, 'data' => $data]);

} catch (Throwable $e) {
    log_event('errors', 'ERROR', 'services.php: ' . $e->getMessage());
    json_response(['error' => 'Nepodařilo se načíst služby.'], 500);
}
