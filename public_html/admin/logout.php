<?php
/**
 * /admin/logout.php
 *
 * Odhlásí admina (smaže session, smaže cookie) a přesměruje na login.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

logout_admin();

header('Location: /admin/login.php');
exit;
