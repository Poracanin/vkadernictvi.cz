<?php
/**
 * /api/admin/hours.php
 *
 * Otvírací doba (working_hours) + výjimky (day_overrides).
 *
 *   GET   → { ok, data: { working_hours: [...7], overrides: [...] } }
 *
 *   POST  body: {
 *             working_hours: [
 *               { day_of_week:1, open_time:"09:00", close_time:"18:00", is_closed:false }, ...
 *             ],
 *             overrides: [
 *               { date:"2026-12-24", is_closed:true, reason:"Štědrý den" },
 *               { date:"2026-05-15", open_time:"12:00", close_time:"16:00", reason:"Zkrácená pracovní doba" }
 *             ]
 *          }
 *          → uloží otvírací dobu (UPSERT všech 7 dní) a kompletně přepíše overrides
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/auth.php';

require_admin_api();
$method = require_method(['GET', 'POST']);
if ($method !== 'GET') {
    require_csrf();
}

try {
    if ($method === 'GET') {
        handle_get();
    } else {
        handle_save(read_json_body());
    }
} catch (ValidationException $e) {
    json_response(['error' => $e->getMessage()], 400);
} catch (ApiException $e) {
    json_response(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    log_event('errors', 'ERROR', 'admin/hours.php: ' . $e->getMessage(), current_log_user());
    json_response(['error' => 'Vnitřní chyba serveru.'], 500);
}

// =====================================================================

function handle_get(): void
{
    $pdo = db();

    $whRows = $pdo->query(
        'SELECT day_of_week, open_time, close_time, is_closed
           FROM working_hours ORDER BY day_of_week ASC'
    )->fetchAll();

    // Garantuj všech 7 dní (i kdyby v DB chyběly)
    $byDay = [];
    foreach ($whRows as $r) {
        $byDay[(int)$r['day_of_week']] = [
            'day_of_week' => (int)$r['day_of_week'],
            'open_time'   => $r['open_time']  ? substr((string)$r['open_time'],  0, 5) : null,
            'close_time'  => $r['close_time'] ? substr((string)$r['close_time'], 0, 5) : null,
            'is_closed'   => (int)$r['is_closed'] === 1,
        ];
    }
    $workingHours = [];
    for ($d = 1; $d <= 7; $d++) {
        $workingHours[] = $byDay[$d] ?? [
            'day_of_week' => $d,
            'open_time'   => null,
            'close_time'  => null,
            'is_closed'   => true,
        ];
    }

    // Overrides – jen budoucí + posledních 30 dní
    $ovRows = $pdo->prepare(
        "SELECT `date`, open_time, close_time, is_closed, reason
           FROM day_overrides
          WHERE `date` >= (CURRENT_DATE - INTERVAL 30 DAY)
          ORDER BY `date` ASC"
    );
    $ovRows->execute();
    $ovs = array_map(static fn (array $r): array => [
        'date'       => (string)$r['date'],
        'open_time'  => $r['open_time']  ? substr((string)$r['open_time'],  0, 5) : null,
        'close_time' => $r['close_time'] ? substr((string)$r['close_time'], 0, 5) : null,
        'is_closed'  => (int)$r['is_closed'] === 1,
        'reason'     => $r['reason'],
    ], $ovRows->fetchAll());

    json_response([
        'ok'   => true,
        'data' => [
            'working_hours' => $workingHours,
            'overrides'     => $ovs,
        ],
    ]);
}

function handle_save(array $body): void
{
    if (!isset($body['working_hours']) || !is_array($body['working_hours'])) {
        throw new ValidationException('Chybí pole working_hours.');
    }

    // Otvírací doba – validace
    $hours = [];
    foreach ($body['working_hours'] as $h) {
        if (!is_array($h)) continue;
        $dow      = v_int($h['day_of_week'] ?? null, 'day_of_week', 1, 7);
        $isClosed = !empty($h['is_closed']);
        $open     = $isClosed ? null : (empty($h['open_time'])  ? null : v_time($h['open_time'],  'open_time'));
        $close    = $isClosed ? null : (empty($h['close_time']) ? null : v_time($h['close_time'], 'close_time'));

        if (!$isClosed) {
            if ($open === null || $close === null) {
                throw new ValidationException("Pro den $dow zadejte open_time i close_time, nebo nastavte is_closed.");
            }
            if (strtotime("1970-01-01 $open") >= strtotime("1970-01-01 $close")) {
                throw new ValidationException("Pro den $dow musí být open_time dříve než close_time.");
            }
        }

        $hours[$dow] = [
            'day' => $dow, 'open' => $open, 'close' => $close, 'closed' => $isClosed ? 1 : 0,
        ];
    }
    if (count($hours) === 0) {
        throw new ValidationException('Pole working_hours je prázdné.');
    }

    // Overrides – validace
    $overrides = [];
    if (isset($body['overrides']) && is_array($body['overrides'])) {
        foreach ($body['overrides'] as $o) {
            if (!is_array($o)) continue;
            $date     = v_date($o['date'] ?? '', 'override.date');
            $isClosed = !empty($o['is_closed']);
            $open     = $isClosed ? null : (empty($o['open_time'])  ? null : v_time($o['open_time'],  'open_time'));
            $close    = $isClosed ? null : (empty($o['close_time']) ? null : v_time($o['close_time'], 'close_time'));
            $reason   = isset($o['reason']) && is_string($o['reason'])
                ? mb_substr(trim($o['reason']), 0, 150, 'UTF-8')
                : null;

            if (!$isClosed && ($open === null || $close === null)) {
                throw new ValidationException("Pro výjimku $date zadejte open_time + close_time, nebo is_closed=true.");
            }
            if (!$isClosed && strtotime("1970-01-01 $open") >= strtotime("1970-01-01 $close")) {
                throw new ValidationException("Ve výjimce $date musí být open_time dříve než close_time.");
            }

            $overrides[$date] = [
                'date' => $date, 'open' => $open, 'close' => $close,
                'closed' => $isClosed ? 1 : 0, 'reason' => $reason,
            ];
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // UPSERT working_hours
        $upsert = $pdo->prepare(
            'INSERT INTO working_hours (day_of_week, open_time, close_time, is_closed)
                 VALUES (:dow, :o, :c, :closed)
             ON DUPLICATE KEY UPDATE
                 open_time = VALUES(open_time),
                 close_time = VALUES(close_time),
                 is_closed = VALUES(is_closed)'
        );
        foreach ($hours as $h) {
            $upsert->execute([
                ':dow'    => $h['day'],
                ':o'      => $h['open'],
                ':c'      => $h['close'],
                ':closed' => $h['closed'],
            ]);
        }

        // Overrides – kompletně přepiš (smaž a vlož znovu)
        $pdo->exec('DELETE FROM day_overrides');
        if ($overrides) {
            $insOv = $pdo->prepare(
                'INSERT INTO day_overrides (`date`, open_time, close_time, is_closed, reason)
                 VALUES (:d, :o, :c, :closed, :reason)'
            );
            foreach ($overrides as $o) {
                $insOv->execute([
                    ':d'      => $o['date'],
                    ':o'      => $o['open'],
                    ':c'      => $o['close'],
                    ':closed' => $o['closed'],
                    ':reason' => $o['reason'],
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    log_event('actions', 'INFO',
        sprintf('action=hours_save days=%d overrides=%d', count($hours), count($overrides)),
        current_log_user()
    );

    json_response([
        'ok' => true,
        'data' => [
            'working_hours_count' => count($hours),
            'overrides_count'     => count($overrides),
        ],
    ]);
}
