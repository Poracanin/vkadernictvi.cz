<?php
/**
 * /cancel.php
 *
 * Zákaznické zrušení rezervace přes jednorázový token z e-mailu.
 *
 * Flow:
 *   GET  ?token=XXX              → najde rezervaci, ukáže potvrzovací stránku
 *   POST (token v hidden input)  → změní status na cancelled, pošle mail, ukáže potvrzení
 *
 * Stavy zobrazované uživateli:
 *   - "ok"           – rezervace zrušena (čerstvě)
 *   - "already"      – už byla dříve zrušena
 *   - "past"         – termín již proběhl, nelze rušit
 *   - "not_found"    – neplatný token
 *   - "confirm"      – default GET, zobraz formulář
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';

// -------------------------------------------------------------
// Parsing tokenu (GET ?token=XXX nebo POST hidden)
// -------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token  = (string)($_REQUEST['token'] ?? '');
$state  = 'not_found';
$booking = null;

try {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $state = 'not_found';
    } else {
        $pdo = db();
        $sel = $pdo->prepare(
            'SELECT b.id, b.start_at, b.end_at, b.customer_name, b.customer_email,
                    b.customer_phone, b.status, b.cancel_token,
                    s.name AS service_name, s.duration_min, s.price, s.icon
               FROM bookings b
               JOIN services s ON s.id = b.service_id
              WHERE b.cancel_token = :tok
              LIMIT 1'
        );
        $sel->execute([':tok' => $token]);
        $booking = $sel->fetch();

        if (!$booking) {
            $state = 'not_found';
        } elseif ($booking['status'] === 'cancelled') {
            $state = 'already';
        } elseif (strtotime((string)$booking['start_at']) < time()) {
            $state = 'past';
        } elseif ($method === 'POST') {
            // -- POST: provedení zrušení -------------------------------
            $upd = $pdo->prepare(
                "UPDATE bookings SET status = 'cancelled' WHERE id = :id AND status NOT IN ('cancelled','done')"
            );
            $upd->execute([':id' => $booking['id']]);

            $booking['status'] = 'cancelled';
            @mail_booking_cancelled($booking);

            log_event('actions', 'INFO',
                "action=cancel_by_customer booking=#{$booking['id']} customer={$booking['customer_email']}",
                'customer'
            );
            log_event('bookings', 'INFO',
                "[CANCEL] [public] Zákazník zrušil rezervaci #{$booking['id']} ({$booking['customer_email']})",
                'customer'
            );

            $state = 'ok';
        } else {
            $state = 'confirm';
        }
    }
} catch (Throwable $e) {
    log_event('errors', 'ERROR', 'cancel.php: ' . $e->getMessage());
    $state = 'not_found';
}

// -------------------------------------------------------------
// Pomocné formátování pro view
// -------------------------------------------------------------
function fmtCancelDate(string $dt): string
{
    $d = new DateTime($dt);
    return $d->format('j. n. Y') . ' v ' . $d->format('H:i');
}
function fmtCancelPrice($p): string
{
    return ($p === null || $p === '')
        ? 'na dotaz'
        : number_format((float)$p, 0, ',', ' ') . ' Kč';
}

$siteName = $CONFIG['site']['name']  ?? 'Vkadeřnictví';
$siteUrl  = rtrim((string)($CONFIG['site']['url'] ?? '/'), '/');

http_response_code($state === 'not_found' ? 404 : 200);
?><!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Zrušení rezervace | <?= e($siteName) ?></title>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600&family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #0a0a0a;
            color: #d1d5db;
            display: flex; align-items: center; justify-content: center;
            padding: 24px;
            background-image:
                radial-gradient(circle at 20% 20%, rgba(197,160,89,0.06), transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(197,160,89,0.05), transparent 40%);
        }
        .card {
            width: 100%; max-width: 520px;
            background: #141414;
            border: 1px solid #262626;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6);
        }
        .card-header {
            background: linear-gradient(135deg, #ebd197 0%, #c5a059 50%, #9c7b3b 100%);
            padding: 28px 24px;
            text-align: center;
        }
        .card-header .brand {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 24px;
            font-weight: 700;
            color: #0a0a0a;
            letter-spacing: 0.5px;
        }
        .card-header .sub {
            font-size: 11px;
            color: #3a2a10;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-top: 4px;
        }
        .badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            padding: 7px 14px;
            border-radius: 999px;
        }
        .badge-warn   { background: rgba(245,158,11,0.12); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); }
        .badge-cancel { background: rgba(220,38,38,0.10);  color: #f87171; border: 1px solid rgba(220,38,38,0.3); }
        .badge-info   { background: rgba(59,130,246,0.12); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
        .badge-ok     { background: rgba(34,197,94,0.12);  color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }

        .status-row { background: #1a1a1a; padding: 14px 24px; text-align: center; border-bottom: 1px solid #262626; }
        .content { padding: 32px 28px; }
        h1 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 24px; font-weight: 600; color: #fff;
            margin: 0 0 10px 0; line-height: 1.3;
        }
        p.lead { font-size: 14px; line-height: 1.6; color: #a3a3a3; margin: 0 0 20px 0; }

        .booking-card {
            background: #0e0e0e;
            border: 1px solid #262626;
            border-radius: 10px;
            margin: 18px 0 24px 0;
            overflow: hidden;
        }
        .booking-card .booking-title {
            padding: 14px 18px;
            border-bottom: 1px solid #262626;
        }
        .booking-card .booking-title .svc {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 16px;
            color: #c5a059;
            font-weight: 600;
        }
        .booking-card .booking-title .meta {
            font-size: 11px; color: #666;
            text-transform: uppercase; letter-spacing: 1px;
            margin-top: 3px;
        }
        .booking-card .row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 11px 18px; border-bottom: 1px solid #1f1f1f;
            font-size: 13px;
        }
        .booking-card .row:last-child { border-bottom: 0; }
        .booking-card .row .lbl { color: #888; text-transform: uppercase; font-size: 11px; letter-spacing: 1.5px; }
        .booking-card .row .val { color: #fff; font-weight: 500; }
        .booking-card .row .val.gold { color: #c5a059; }
        .booking-card.dim { opacity: 0.85; }
        .booking-card.dim .svc, .booking-card.dim .val { text-decoration: line-through; color: #888; }

        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
        .btn {
            flex: 1; min-width: 130px;
            padding: 13px 18px; border: 0; border-radius: 8px;
            font-family: inherit; font-size: 13px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 1px;
            cursor: pointer; text-align: center;
            transition: transform 0.12s, filter 0.2s, background 0.2s;
            display: inline-block; text-decoration: none;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-danger {
            background: rgba(220,38,38,0.12);
            color: #fca5a5;
            border: 1px solid rgba(220,38,38,0.4);
        }
        .btn-danger:hover { background: rgba(220,38,38,0.2); }
        .btn-ghost {
            background: transparent;
            color: #d1d5db;
            border: 1px solid #2f2f2f;
        }
        .btn-ghost:hover { background: #1a1a1a; }
        .btn-gold {
            background: linear-gradient(135deg, #ebd197 0%, #c5a059 50%, #9c7b3b 100%);
            color: #0a0a0a;
        }
        .btn-gold:hover { filter: brightness(1.08); }

        .footer-note {
            text-align: center;
            padding: 18px 24px;
            border-top: 1px solid #1f1f1f;
            background: #0a0a0a;
        }
        .footer-note a { color: #c5a059; text-decoration: none; }
        .footer-note a:hover { text-decoration: underline; }

        .hero-icon {
            width: 64px; height: 64px;
            border-radius: 50%;
            margin: 0 auto 16px auto;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
        }
        .hero-icon.danger  { background: rgba(220,38,38,0.10); color: #f87171; border: 1px solid rgba(220,38,38,0.3); }
        .hero-icon.success { background: rgba(34,197,94,0.10); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
        .hero-icon.muted   { background: #1a1a1a; color: #666;   border: 1px solid #262626; }
    </style>
</head>
<body>
    <main class="card">
        <header class="card-header">
            <div class="brand"><?= e($siteName) ?></div>
            <div class="sub">Veronika Volfová</div>
        </header>

        <?php if ($state === 'confirm' && $booking): ?>
            <div class="status-row">
                <span class="badge badge-warn">⚠ &nbsp;Zrušení rezervace</span>
            </div>
            <div class="content">
                <h1>Opravdu zrušit rezervaci?</h1>
                <p class="lead">
                    Tímto formulářem zrušíte rezervaci. Ozve se vám potvrzovací e-mail. Pokud chcete pouze přesunout termín, napište mi prosím – domluvíme se.
                </p>

                <div class="booking-card">
                    <div class="booking-title">
                        <div class="svc"><i class="fas <?= e($booking['icon'] ?? 'fa-cut') ?>"></i>&nbsp; <?= e($booking['service_name']) ?></div>
                        <div class="meta">Doba trvání: <?= (int)$booking['duration_min'] ?> min · Cena: <?= e(fmtCancelPrice($booking['price'])) ?></div>
                    </div>
                    <div class="row"><span class="lbl">Jméno</span><span class="val"><?= e($booking['customer_name']) ?></span></div>
                    <div class="row"><span class="lbl">Termín</span><span class="val gold"><?= e(fmtCancelDate($booking['start_at'])) ?></span></div>
                </div>

                <form method="POST" action="/cancel.php" class="actions">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <a href="<?= e($siteUrl ?: '/') ?>" class="btn btn-ghost">
                        <i class="fas fa-arrow-left"></i>&nbsp; Zpět
                    </a>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times"></i>&nbsp; Zrušit rezervaci
                    </button>
                </form>
            </div>

        <?php elseif ($state === 'ok'): ?>
            <div class="status-row">
                <span class="badge badge-cancel">✕ &nbsp;Rezervace zrušena</span>
            </div>
            <div class="content" style="text-align:center;">
                <div class="hero-icon success"><i class="fas fa-check"></i></div>
                <h1 style="text-align:center;">Hotovo, je to zrušené.</h1>
                <p class="lead" style="text-align:center;">
                    Rezervace <strong style="color:#fff;">#<?= (int)$booking['id'] ?></strong> byla úspěšně zrušena. Potvrzovací e-mail je už na cestě k vám.
                </p>
                <div class="booking-card dim">
                    <div class="booking-title">
                        <div class="svc"><i class="fas <?= e($booking['icon'] ?? 'fa-cut') ?>"></i>&nbsp; <?= e($booking['service_name']) ?></div>
                        <div class="meta"><?= e(fmtCancelDate($booking['start_at'])) ?></div>
                    </div>
                </div>
                <div class="actions" style="justify-content:center;">
                    <a href="<?= e($siteUrl ?: '/') ?>" class="btn btn-gold" style="flex:0 0 auto;">
                        <i class="fas fa-plus"></i>&nbsp; Objednat se znovu
                    </a>
                </div>
            </div>

        <?php elseif ($state === 'already'): ?>
            <div class="status-row">
                <span class="badge badge-info">i &nbsp;Již zrušeno</span>
            </div>
            <div class="content" style="text-align:center;">
                <div class="hero-icon muted"><i class="fas fa-info"></i></div>
                <h1 style="text-align:center;">Tato rezervace už byla zrušena.</h1>
                <p class="lead" style="text-align:center;">
                    Žádná akce už není potřeba. Pokud jste ji chtěli zachovat, kontaktujte mě prosím přímo.
                </p>
                <div class="actions" style="justify-content:center;">
                    <a href="<?= e($siteUrl ?: '/') ?>" class="btn btn-ghost" style="flex:0 0 auto;">
                        <i class="fas fa-arrow-left"></i>&nbsp; Zpět na web
                    </a>
                </div>
            </div>

        <?php elseif ($state === 'past'): ?>
            <div class="status-row">
                <span class="badge badge-warn">⚠ &nbsp;Termín již proběhl</span>
            </div>
            <div class="content" style="text-align:center;">
                <div class="hero-icon muted"><i class="fas fa-clock"></i></div>
                <h1 style="text-align:center;">Tento termín už proběhl.</h1>
                <p class="lead" style="text-align:center;">
                    Rezervace už nelze rušit po termínu. Pokud byste se chtěli objednat znovu, klidně přes web.
                </p>
                <div class="actions" style="justify-content:center;">
                    <a href="<?= e($siteUrl ?: '/') ?>" class="btn btn-gold" style="flex:0 0 auto;">
                        <i class="fas fa-plus"></i>&nbsp; Objednat se znovu
                    </a>
                </div>
            </div>

        <?php else: /* not_found */ ?>
            <div class="status-row">
                <span class="badge badge-cancel">✕ &nbsp;Neplatný odkaz</span>
            </div>
            <div class="content" style="text-align:center;">
                <div class="hero-icon danger"><i class="fas fa-link-slash"></i></div>
                <h1 style="text-align:center;">Tento odkaz není platný.</h1>
                <p class="lead" style="text-align:center;">
                    Odkaz pro zrušení nelze najít. Možná byl už použitý nebo špatně zkopírovaný. Pokud potřebujete zrušit rezervaci, napište mi prosím.
                </p>
                <div class="actions" style="justify-content:center;">
                    <a href="<?= e($siteUrl ?: '/') ?>" class="btn btn-ghost" style="flex:0 0 auto;">
                        <i class="fas fa-home"></i>&nbsp; Zpět na web
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <footer class="footer-note">
            <a href="<?= e($siteUrl ?: '/') ?>"><?= e($siteUrl ?: 'Domů') ?></a>
        </footer>
    </main>
</body>
</html>
