<?php
/**
 * validator.php
 *
 * Sada validačních funkcí. Při neplatném vstupu vyhazují ValidationException
 * (definovanou v bootstrap.php), kterou endpointy chytají jako 400 Bad Request.
 *
 * Pravidlo: každá v_* funkce vrací očištěnou (typovanou) hodnotu, případně
 * vyhodí ValidationException s hláškou pro koncového uživatele.
 */

declare(strict_types=1);

/**
 * Validuje řetězec – min/max délka, trim, NOT NULL.
 */
function v_string($value, string $label, int $min = 1, int $max = 255): string
{
    if (!is_string($value) && !is_numeric($value)) {
        throw new ValidationException("Pole '$label' je povinné.");
    }
    $v = trim((string)$value);
    $len = mb_strlen($v, 'UTF-8');
    if ($len < $min) {
        throw new ValidationException("Pole '$label' musí mít alespoň $min znaků.");
    }
    if ($len > $max) {
        throw new ValidationException("Pole '$label' smí mít maximálně $max znaků.");
    }
    return $v;
}

/**
 * Validuje e-mailovou adresu.
 */
function v_email($value, string $label = 'E-mail'): string
{
    $v = is_string($value) ? trim($value) : '';
    if ($v === '' || mb_strlen($v) > 190) {
        throw new ValidationException("$label není ve správném formátu.");
    }
    if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
        throw new ValidationException("$label není ve správném formátu.");
    }
    return $v;
}

/**
 * Validuje telefon. Akceptuje číslice, mezery, +, -, závorky. Min. 9 cifer.
 */
function v_phone($value, string $label = 'Telefon'): string
{
    $v = is_string($value) ? trim($value) : '';
    if ($v === '') {
        throw new ValidationException("$label je povinný.");
    }
    if (!preg_match('/^[\d\s\+\-\(\)]{9,30}$/', $v)) {
        throw new ValidationException("$label není ve správném formátu.");
    }
    $digits = preg_replace('/\D/', '', $v);
    if (strlen($digits) < 9) {
        throw new ValidationException("$label musí obsahovat alespoň 9 číslic.");
    }
    return $v;
}

/**
 * Validuje datum ve formátu YYYY-MM-DD.
 */
function v_date($value, string $label = 'Datum'): string
{
    $v = is_string($value) ? trim($value) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        throw new ValidationException("$label musí být ve formátu YYYY-MM-DD.");
    }
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v) {
        throw new ValidationException("$label není platné.");
    }
    return $v;
}

/**
 * Validuje čas HH:MM nebo HH:MM:SS, vrátí ve formátu HH:MM:SS.
 */
function v_time($value, string $label = 'Čas'): string
{
    $v = is_string($value) ? trim($value) : '';
    if (preg_match('/^(\d{2}):(\d{2})$/', $v, $m)) {
        $v .= ':00';
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $v)) {
        throw new ValidationException("$label musí být ve formátu HH:MM.");
    }
    return $v;
}

/**
 * Validuje celé číslo v rozsahu.
 */
function v_int($value, string $label, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
{
    if ($value === null || $value === '') {
        throw new ValidationException("Pole '$label' je povinné.");
    }
    if (is_string($value)) {
        $value = trim($value);
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new ValidationException("Pole '$label' musí být celé číslo.");
        }
    }
    $v = (int)$value;
    if ($v < $min || $v > $max) {
        throw new ValidationException("Pole '$label' musí být mezi $min a $max.");
    }
    return $v;
}

/**
 * Validuje desetinné číslo (cenu). Vrací string zaokrouhlený na 2 desetinná místa.
 */
function v_decimal($value, string $label, float $min = 0, float $max = 1000000): string
{
    if (is_string($value)) {
        $value = str_replace([',', ' '], ['.', ''], trim($value));
    }
    if (!is_numeric($value)) {
        throw new ValidationException("Pole '$label' musí být číslo.");
    }
    $v = (float)$value;
    if ($v < $min || $v > $max) {
        throw new ValidationException("Pole '$label' je mimo povolený rozsah.");
    }
    return number_format($v, 2, '.', '');
}
