<?php
/**
 * /api/admin/bookings.php
 *
 * Správa rezervací – vyžaduje přihlášení admina + CSRF token na změnách.
 *
 *   GET    ?status=pending|confirmed|...&from=YYYY-MM-DD&to=YYYY-MM-DD
 *          &search=email|jmeno&page=1&limit=20
 *          → seznam rezervací (paging, total)
 *
 *   PATCH  body: { id, action: confirm|reschedule|cancel|done|no_show, ...optional }
 *          - confirm    → status confirmed   + mail confirmed
 *          - reschedule → ověř volný slot (FOR UPDATE), update start/end + mail rescheduled
 *          - cancel     → status cancelled   + mail cancelled
 *          - done       → status done        (žádný mail)
 *          - no_show    → status no_show     (žádný mail)
 *
 *   DELETE body: { id }
 *          - tvrdé smazání rezervace (mail se neposílá)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/booking_helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';

require_admin_api();
$method = require_method(['GET', 'PATCH', 'DELETE']);
if ($method !== 'GET') {
    require_csrf();
}

try {
    if ($method === 'GET') {
        handle_list();
    } elseif ($method === 'PATCH') {
        handle_patch(read_json_body());
    } elseif ($method === 'DELETE') {
        handle_delete(read_json_body());
    }
} catch (ValidationException $e) {
    json_response(['error' => $e->getMessage()], 400);
} catch (ApiException $e) {
    json_response(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    log_event('errors', 'ERROR', 'admin/bookings.php: ' . $e->getMessage(), current_log_user());
    json_response(['error' => 'Vnitřní chyba serveru.'], 500);
}

// =====================================================================
// Handlery
// =====================================================================

/**
 * GET – seznam rezervací s filtry + paging.
 */
function handle_list(): void
{
    $status   = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
    $from     = isset($_GET['from'])   ? trim((string)$_GET['from'])   : '';
    $to       = isset($_GET['to'])     ? trim((string)$_GET['to'])     : '';
    $search   = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $page     = max(1, (int)($_GET['page']  ?? 1));
    $limit    = max(1, min(200, (int)($_GET['limit'] ?? 20)));
    $offset   = ($page - 1) * $limit;

    $where  = [];
    $params = [];

    // Status: může být víc oddělených čárkou (např. pending,confirmed)
    if ($status !== '') {
        $statuses = array_filter(array_map('trim', explode(',', $status)), static fn ($s) => $s !== '');
        $allowed  = ['pending', 'confirmed', 'cancelled', 'done', 'no_show'];
        $statuses = array_values(array_intersect($statuses, $allowed));
        if ($statuses) {
            $placeholders = [];
            foreach ($statuses as $i => $st) {
                $key = ":st$i";
                $placeholders[] = $key;
                $params[$key]   = $st;
            }
            $where[] = 'b.status IN (' . implode(',', $placeholders) . ')';
        }
    }

    if ($from !== '') {
        $from = v_date($from, 'from');
        $where[] = 'b.start_at >= :from';
        $params[':from'] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $to = v_date($to, 'to');
        $where[] = 'b.start_at <= :to';
        $params[':to']   = $to . ' 23:59:59';
    }
    if ($search !== '') {
        $where[] = '(b.customer_email LIKE :q OR b.customer_name LIKE :q OR b.customer_phone LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $pdo = db();

    // Total pro paging
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM bookings b $whereSql");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $sql = "
        SELECT b.id, b.service_id, b.start_at, b.end_at, b.customer_name,
               b.customer_email, b.customer_phone, b.note, b.status,
               b.cancel_token, b.created_at,
               s.name AS service_name, s.duration_min, s.price, s.icon
          FROM bookings b
          JOIN services s ON s.id = b.service_id
          $whereSql
         ORDER BY b.start_at DESC
         LIMIT $limit OFFSET $offset
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $data = array_map(static function (array $r): array {
        return [
            'id'             => (int)$r['id'],
            'service_id'     => (int)$r['service_id'],
            'service_name'   => (string)$r['service_name'],
            'duration_min'   => (int)$r['duration_min'],
            'price'          => $r['price'] !== null ? (float)$r['price'] : null,
            'icon'           => $r['icon'],
            'start_at'       => (string)$r['start_at'],
            'end_at'         => (string)$r['end_at'],
            'customer_name'  => (string)$r['customer_name'],
            'customer_email' => (string)$r['customer_email'],
            'customer_phone' => (string)$r['customer_phone'],
            'note'           => $r['note'],
            'status'         => (string)$r['status'],
            'cancel_token'   => (string)$r['cancel_token'],
            'created_at'     => (string)$r['created_at'],
        ];
    }, $rows);

    json_response([
        'ok'   => true,
        'data' => $data,
        'meta' => [
            'page'   => $page,
            'limit'  => $limit,
            'total'  => $total,
            'pages'  => (int)ceil($total / $limit),
        ],
    ]);
}

/**
 * PATCH – akce nad rezervací.
 */
function handle_patch(array $body): void
{
    $id     = v_int($body['id']     ?? null, 'id', 1);
    $action = v_string($body['action'] ?? '', 'action', 1, 30);
    $allowed = ['confirm', 'reschedule', 'cancel', 'done', 'no_show'];
    if (!in_array($action, $allowed, true)) {
        throw new ValidationException('Neznámá akce.');
    }

    $pdo = db();

    // Načti aktuální stav rezervace
    $sel = $pdo->prepare(
        'SELECT b.*, s.name AS service_name, s.duration_min, s.price, s.icon
           FROM bookings b
           JOIN services s ON s.id = b.service_id
          WHERE b.id = :id
          LIMIT 1'
    );
    $sel->execute([':id' => $id]);
    $booking = $sel->fetch();
    if (!$booking) {
        throw new ApiException('Rezervace nebyla nalezena.', 404);
    }

    $user = current_log_user();

    switch ($action) {
        case 'confirm':
            update_status($pdo, $id, 'confirmed');
            $booking['status'] = 'confirmed';
            @mail_booking_confirmed($booking);
            log_event('actions', 'INFO', "action=confirm booking=#$id customer={$booking['customer_email']}", $user);
            break;

        case 'cancel':
            update_status($pdo, $id, 'cancelled');
            $booking['status'] = 'cancelled';
            @mail_booking_cancelled($booking);
            log_event('actions', 'INFO', "action=cancel booking=#$id customer={$booking['customer_email']}", $user);
            break;

        case 'done':
            update_status($pdo, $id, 'done');
            log_event('actions', 'INFO', "action=done booking=#$id customer={$booking['customer_email']}", $user);
            break;

        case 'no_show':
            update_status($pdo, $id, 'no_show');
            log_event('actions', 'INFO', "action=no_show booking=#$id customer={$booking['customer_email']}", $user);
            break;

        case 'reschedule':
            $newStart = v_string($body['new_start_at'] ?? '', 'new_start_at', 14, 25);
            // očekáváme YYYY-MM-DD HH:MM nebo HH:MM:SS
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $newStart, $m)) {
                throw new ValidationException('Nový termín musí být ve formátu YYYY-MM-DD HH:MM.');
            }
            $newDate = $m[1];
            $newTime = $m[2] . ':00';

            $oldStartAt = (string)$booking['start_at'];

            // Pod transakcí ověř volný slot a updatuj
            $pdo->beginTransaction();
            try {
                // Dočasně vyloučit aktuální rezervaci ze srovnání – přesun na svůj
                // vlastní (i jen mírně posunutý) termín by jinak sám sebe blokoval.
                $tmp = $pdo->prepare(
                    "UPDATE bookings SET status = 'cancelled' WHERE id = :id"
                );
                $tmp->execute([':id' => $id]);

                $check = lock_and_validate_slot($pdo, $newDate, $newTime, (int)$booking['service_id']);
                if (!$check['ok']) {
                    $pdo->rollBack();
                    throw new ApiException($check['reason'], 409);
                }

                $upd = $pdo->prepare(
                    "UPDATE bookings
                        SET start_at = :start, end_at = :end, status = 'confirmed'
                      WHERE id = :id"
                );
                $upd->execute([
                    ':start' => "$newDate $newTime",
                    ':end'   => $check['end_at'],
                    ':id'    => $id,
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            // Znovu načti pro mail
            $sel->execute([':id' => $id]);
            $booking = $sel->fetch();
            @mail_booking_rescheduled($booking, $oldStartAt);
            log_event('actions', 'INFO',
                "action=reschedule booking=#$id customer={$booking['customer_email']} old=$oldStartAt new={$booking['start_at']}",
                $user
            );
            break;
    }

    // Vrať aktuální stav
    $sel->execute([':id' => $id]);
    $fresh = $sel->fetch();

    json_response([
        'ok'   => true,
        'data' => [
            'id'       => (int)$fresh['id'],
            'status'   => (string)$fresh['status'],
            'start_at' => (string)$fresh['start_at'],
            'end_at'   => (string)$fresh['end_at'],
        ],
    ]);
}

/**
 * DELETE – tvrdé smazání rezervace (např. duplikát, spam).
 */
function handle_delete(array $body): void
{
    $id = v_int($body['id'] ?? null, 'id', 1);

    $pdo = db();
    $sel = $pdo->prepare('SELECT id, customer_email FROM bookings WHERE id = :id LIMIT 1');
    $sel->execute([':id' => $id]);
    $row = $sel->fetch();
    if (!$row) {
        throw new ApiException('Rezervace nebyla nalezena.', 404);
    }

    $del = $pdo->prepare('DELETE FROM bookings WHERE id = :id');
    $del->execute([':id' => $id]);

    log_event('actions', 'INFO',
        "action=delete booking=#$id customer={$row['customer_email']}",
        current_log_user()
    );

    json_response(['ok' => true, 'data' => ['id' => $id, 'deleted' => true]]);
}

// =====================================================================
// Pomocné funkce
// =====================================================================

function update_status(PDO $pdo, int $id, string $status): void
{
    $u = $pdo->prepare('UPDATE bookings SET status = :s WHERE id = :id');
    $u->execute([':s' => $status, ':id' => $id]);
}
