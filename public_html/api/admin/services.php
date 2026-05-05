<?php
/**
 * /api/admin/services.php
 *
 * CRUD nad službami (admin).
 *
 *   GET    → seznam všech služeb (i deaktivovaných), seřazený podle sort_order
 *   POST   → vytvoření nové (body: name, duration_min, price?, icon?, category?, subcategory?, description?, sort_order?, is_active?)
 *   PATCH  → úprava (body: id + libovolná pole)
 *   DELETE → smaže službu pokud nemá rezervace, jinak vrátí 409 a nabídne deaktivaci
 *            body: { id, soft?:true } – soft=true místo smazání jen is_active=0
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/auth.php';

require_admin_api();
$method = require_method(['GET', 'POST', 'PATCH', 'DELETE']);
if ($method !== 'GET') {
    require_csrf();
}

try {
    if ($method === 'GET') {
        handle_list();
    } elseif ($method === 'POST') {
        handle_create(read_json_body());
    } elseif ($method === 'PATCH') {
        handle_update(read_json_body());
    } elseif ($method === 'DELETE') {
        handle_delete(read_json_body());
    }
} catch (ValidationException $e) {
    json_response(['error' => $e->getMessage()], 400);
} catch (ApiException $e) {
    json_response(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    log_event('errors', 'ERROR', 'admin/services.php: ' . $e->getMessage(), current_log_user());
    json_response(['error' => 'Vnitřní chyba serveru.'], 500);
}

// =====================================================================

function handle_list(): void
{
    $rows = db()->query(
        'SELECT id, name, duration_min, price, icon, category, subcategory,
                description, sort_order, is_active, created_at, updated_at
           FROM services
          ORDER BY sort_order ASC, id ASC'
    )->fetchAll();

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
            'sort_order'   => (int)$r['sort_order'],
            'is_active'    => (int)$r['is_active'] === 1,
            'created_at'   => $r['created_at'],
            'updated_at'   => $r['updated_at'],
        ];
    }, $rows);

    json_response(['ok' => true, 'data' => $data]);
}

function handle_create(array $body): void
{
    $fields = parse_service_fields($body, true);

    $st = db()->prepare(
        'INSERT INTO services
            (name, duration_min, price, icon, category, subcategory,
             description, sort_order, is_active)
         VALUES
            (:name, :dur, :price, :icon, :cat, :sub, :desc, :sort, :active)'
    );
    $st->execute([
        ':name'   => $fields['name'],
        ':dur'    => $fields['duration_min'],
        ':price'  => $fields['price'],
        ':icon'   => $fields['icon'],
        ':cat'    => $fields['category'],
        ':sub'    => $fields['subcategory'],
        ':desc'   => $fields['description'],
        ':sort'   => $fields['sort_order'],
        ':active' => $fields['is_active'],
    ]);
    $id = (int)db()->lastInsertId();

    log_event('actions', 'INFO', "action=service_create id=#$id name=\"{$fields['name']}\"", current_log_user());
    json_response(['ok' => true, 'data' => ['id' => $id]]);
}

function handle_update(array $body): void
{
    $id = v_int($body['id'] ?? null, 'id', 1);

    $sel = db()->prepare('SELECT id FROM services WHERE id = :id');
    $sel->execute([':id' => $id]);
    if (!$sel->fetch()) {
        throw new ApiException('Služba nebyla nalezena.', 404);
    }

    $fields = parse_service_fields($body, false);
    if (empty($fields)) {
        throw new ValidationException('Žádná pole ke změně.');
    }

    $sets = [];
    $params = [':id' => $id];
    foreach ($fields as $k => $v) {
        $col = match ($k) {
            'duration_min' => 'duration_min',
            default        => $k,
        };
        $sets[] = "$col = :$k";
        $params[":$k"] = $v;
    }

    $sql = 'UPDATE services SET ' . implode(', ', $sets) . ' WHERE id = :id';
    db()->prepare($sql)->execute($params);

    log_event('actions', 'INFO', "action=service_update id=#$id fields=" . implode(',', array_keys($fields)), current_log_user());
    json_response(['ok' => true, 'data' => ['id' => $id]]);
}

function handle_delete(array $body): void
{
    $id   = v_int($body['id'] ?? null, 'id', 1);
    $soft = !empty($body['soft']);

    $pdo = db();

    // Existují aktivní rezervace?
    $cnt = $pdo->prepare(
        "SELECT COUNT(*) FROM bookings
          WHERE service_id = :id AND status NOT IN ('cancelled','no_show')"
    );
    $cnt->execute([':id' => $id]);
    $active = (int)$cnt->fetchColumn();

    if ($active > 0 && !$soft) {
        throw new ApiException(
            "Službu nelze smazat – existuje $active aktivních rezervací. Můžete ji deaktivovat (soft=true).",
            409
        );
    }

    if ($soft) {
        $u = $pdo->prepare('UPDATE services SET is_active = 0 WHERE id = :id');
        $u->execute([':id' => $id]);
        log_event('actions', 'INFO', "action=service_deactivate id=#$id", current_log_user());
        json_response(['ok' => true, 'data' => ['id' => $id, 'deactivated' => true]]);
    }

    $d = $pdo->prepare('DELETE FROM services WHERE id = :id');
    try {
        $d->execute([':id' => $id]);
    } catch (PDOException $e) {
        throw new ApiException('Službu nelze smazat – existují historické rezervace. Použijte deaktivaci.', 409);
    }

    log_event('actions', 'INFO', "action=service_delete id=#$id", current_log_user());
    json_response(['ok' => true, 'data' => ['id' => $id, 'deleted' => true]]);
}

/**
 * Parsuje a validuje pole služby. Při $required=true vyžaduje povinná pole.
 *
 * @return array Pole jen s opravdu zadanými klíči (pro PATCH).
 */
function parse_service_fields(array $body, bool $required): array
{
    $out = [];

    if ($required || array_key_exists('name', $body)) {
        $out['name'] = v_string($body['name'] ?? '', 'název', 2, 150);
    }
    if ($required || array_key_exists('duration_min', $body)) {
        $out['duration_min'] = v_int($body['duration_min'] ?? null, 'délka v minutách', 5, 600);
    }

    if (array_key_exists('price', $body)) {
        $price = $body['price'];
        if ($price === null || $price === '' || $price === 'null') {
            $out['price'] = null; // "na dotaz"
        } else {
            $out['price'] = v_decimal($price, 'cena', 0, 999999);
        }
    }

    if (array_key_exists('icon', $body)) {
        $out['icon'] = $body['icon'] === null || $body['icon'] === ''
            ? null
            : v_string($body['icon'], 'ikona', 1, 60);
    }
    if (array_key_exists('category', $body)) {
        $out['category'] = $body['category'] === null || $body['category'] === ''
            ? null
            : v_string($body['category'], 'kategorie', 1, 40);
    }
    if (array_key_exists('subcategory', $body)) {
        $out['subcategory'] = $body['subcategory'] === null || $body['subcategory'] === ''
            ? null
            : v_string($body['subcategory'], 'podkategorie', 1, 40);
    }
    if (array_key_exists('description', $body)) {
        $out['description'] = $body['description'] === null
            ? null
            : (string)mb_substr((string)$body['description'], 0, 2000, 'UTF-8');
    }
    if (array_key_exists('sort_order', $body)) {
        $out['sort_order'] = v_int($body['sort_order'], 'pořadí', 0, 65535);
    }
    if (array_key_exists('is_active', $body)) {
        $out['is_active'] = !empty($body['is_active']) ? 1 : 0;
    }

    if ($required && !isset($out['sort_order'])) {
        $out['sort_order'] = 999;
    }
    if ($required && !isset($out['is_active'])) {
        $out['is_active'] = 1;
    }

    return $out;
}
