<?php
/**
 * booking_helpers.php
 *
 * Sdílená logika pro výpočet dostupnosti slotů – používá ji jak veřejný
 * GET slots.php, tak vytvoření rezervace v book.php (po druhé pod zámkem
 * SELECT ... FOR UPDATE pro race-condition safety).
 */

declare(strict_types=1);

/**
 * Vrátí "okno" otevíračky pro konkrétní datum.
 * Priorita: day_overrides → working_hours (ISO den 1-7).
 *
 * @return array{closed:bool, open:?string, close:?string, reason:?string}
 */
function get_day_window(PDO $pdo, string $date): array
{
    // 1) Override pro konkrétní datum?
    $st = $pdo->prepare(
        'SELECT open_time, close_time, is_closed, reason
           FROM day_overrides WHERE `date` = :d LIMIT 1'
    );
    $st->execute([':d' => $date]);
    $ov = $st->fetch();
    if ($ov) {
        if ((int)$ov['is_closed'] === 1 || empty($ov['open_time']) || empty($ov['close_time'])) {
            return ['closed' => true, 'open' => null, 'close' => null, 'reason' => $ov['reason']];
        }
        return [
            'closed' => false,
            'open'   => (string)$ov['open_time'],
            'close'  => (string)$ov['close_time'],
            'reason' => $ov['reason'],
        ];
    }

    // 2) Otvíračka podle dne v týdnu (ISO 1=Po … 7=Ne)
    $iso = (int)(new DateTime($date))->format('N');
    $st = $pdo->prepare(
        'SELECT open_time, close_time, is_closed
           FROM working_hours WHERE day_of_week = :d LIMIT 1'
    );
    $st->execute([':d' => $iso]);
    $wh = $st->fetch();
    if (!$wh || (int)$wh['is_closed'] === 1 || empty($wh['open_time']) || empty($wh['close_time'])) {
        return ['closed' => true, 'open' => null, 'close' => null, 'reason' => null];
    }
    return [
        'closed' => false,
        'open'   => (string)$wh['open_time'],
        'close'  => (string)$wh['close_time'],
        'reason' => null,
    ];
}

/**
 * Načte aktivní rezervace pro daný den (bez cancelled / no_show).
 * Vrací pole timestampů [start_at_unix, end_at_unix].
 *
 * @param bool $forUpdate Pokud true, použije se SELECT ... FOR UPDATE (jen v transakci!)
 */
function get_busy_intervals(PDO $pdo, string $date, bool $forUpdate = false): array
{
    $sql = "SELECT start_at, end_at
              FROM bookings
             WHERE start_at >= :start AND start_at < :end
               AND status NOT IN ('cancelled','no_show')";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $st = $pdo->prepare($sql);
    $st->execute([
        ':start' => $date . ' 00:00:00',
        ':end'   => $date . ' 23:59:59',
    ]);
    $rows = $st->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'start' => strtotime((string)$r['start_at']),
            'end'   => strtotime((string)$r['end_at']),
        ];
    }
    return $out;
}

/**
 * Spočítá dostupné sloty pro kombinaci data + služby.
 *
 * @return string[] Pole časů ve formátu "HH:MM"
 */
function compute_available_slots(PDO $pdo, string $date, int $serviceId): array
{
    global $CONFIG;

    $stepMin   = (int)($CONFIG['booking']['slot_grid_min'] ?? 15);
    $minAhead  = (int)($CONFIG['booking']['min_ahead_min'] ?? 60);
    $maxAhead  = (int)($CONFIG['booking']['max_days_ahead'] ?? 90);

    // Nepouštěj rezervace dál než N dní
    $today = new DateTime('today');
    $target = new DateTime($date);
    $diffDays = (int)$today->diff($target)->format('%r%a');
    if ($diffDays < 0 || $diffDays > $maxAhead) {
        return [];
    }

    // Načti délku služby
    $st = $pdo->prepare('SELECT duration_min FROM services WHERE id = :id AND is_active = 1');
    $st->execute([':id' => $serviceId]);
    $svc = $st->fetch();
    if (!$svc) {
        return [];
    }
    $duration = (int)$svc['duration_min'];

    // Otevíračka
    $window = get_day_window($pdo, $date);
    if ($window['closed']) {
        return [];
    }

    $openTs  = strtotime($date . ' ' . $window['open']);
    $closeTs = strtotime($date . ' ' . $window['close']);

    if ($openTs === false || $closeTs === false) {
        return [];
    }

    // Načti obsazené intervaly
    $busy = get_busy_intervals($pdo, $date, false);

    $isToday = ($date === $today->format('Y-m-d'));
    $earliest = $isToday ? (time() + $minAhead * 60) : 0;

    $slots = [];
    $stepSec = $stepMin * 60;
    $durSec  = $duration * 60;

    for ($t = $openTs; $t + $durSec <= $closeTs; $t += $stepSec) {
        // Min-ahead pro dnešek
        if ($t < $earliest) {
            continue;
        }
        $end = $t + $durSec;

        // Test překrytí s existujícími rezervacemi
        $overlap = false;
        foreach ($busy as $b) {
            if ($b['start'] < $end && $b['end'] > $t) {
                $overlap = true;
                break;
            }
        }
        if (!$overlap) {
            $slots[] = date('H:i', $t);
        }
    }

    return $slots;
}

/**
 * Pod zámkem (FOR UPDATE) ověří, že konkrétní slot je stále volný.
 * Používá se v book.php a v admin "reschedule" akci.
 *
 * Volat výhradně v rámci $pdo->beginTransaction().
 *
 * @return array{ok:bool, reason?:string, end_at?:string}
 */
function lock_and_validate_slot(PDO $pdo, string $date, string $time, int $serviceId): array
{
    global $CONFIG;

    $st = $pdo->prepare('SELECT duration_min FROM services WHERE id = :id AND is_active = 1');
    $st->execute([':id' => $serviceId]);
    $svc = $st->fetch();
    if (!$svc) {
        return ['ok' => false, 'reason' => 'Služba neexistuje nebo je deaktivovaná.'];
    }
    $duration = (int)$svc['duration_min'];

    $window = get_day_window($pdo, $date);
    if ($window['closed']) {
        return ['ok' => false, 'reason' => 'V daný den máme zavřeno.'];
    }

    $startTs = strtotime("$date $time");
    $endTs   = $startTs + $duration * 60;
    $closeTs = strtotime($date . ' ' . $window['close']);
    $openTs  = strtotime($date . ' ' . $window['open']);

    if ($startTs < $openTs || $endTs > $closeTs) {
        return ['ok' => false, 'reason' => 'Vybraný čas přesahuje otvírací dobu.'];
    }

    // Min-ahead test
    $minAhead = (int)($CONFIG['booking']['min_ahead_min'] ?? 60);
    if ($startTs < time() + $minAhead * 60) {
        return ['ok' => false, 'reason' => 'Vybraný termín už je v minulosti nebo příliš brzy.'];
    }

    // FOR UPDATE check překrytí
    $busy = get_busy_intervals($pdo, $date, true);
    foreach ($busy as $b) {
        if ($b['start'] < $endTs && $b['end'] > $startTs) {
            return ['ok' => false, 'reason' => 'Tento termín už je obsazený. Vyberte prosím jiný.'];
        }
    }

    return [
        'ok'     => true,
        'end_at' => date('Y-m-d H:i:s', $endTs),
    ];
}
