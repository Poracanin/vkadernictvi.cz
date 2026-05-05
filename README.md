# Vkadeřnictví – rezervační systém

Kompletní rezervační systém pro kadeřnictví s jedinou kadeřnicí (Veronika Volfová).
Postavený na čistém **PHP 8.1+** a **MySQL/MariaDB**, frontend ve **vanilla JS**, žádný framework, žádný Composer.

---

## Vlastnosti

- **Veřejná část** (`index.html`) – výběr služby, dynamický výpočet volných slotů, online rezervace, e-mailové potvrzení.
- **Admin panel** (`/admin/`) – přihlášení (1 účet, bcrypt), správa služeb a cen, otvírací doba + výjimky (svátky/dovolená), správa rezervací (potvrdit / přesunout / zrušit / dokončeno / nepřišel), kalendář, dashboard.
- **Dynamické sloty** – generují se po 15 minutách, respektují délku služby a zavírací dobu (např. služba 210 min nikdy nepřeteče).
- **Race condition safe** – `SELECT ... FOR UPDATE` v transakci.
- **Rate limit** – 5 rezervací z jedné IP za hodinu.
- **Honeypot** – tichá ochrana proti botům.
- **Brute-force ochrana** – 5 chybných pokusů → 15 min lock.
- **CSRF tokeny** – v admin panelu přes `X-CSRF-Token` hlavičku.
- **Logy** v `/logs/` – `access.log`, `actions.log`, `bookings.log`, `mail.log`, `errors.log`, `php_errors.log`.
- **HTML maily** – 4 šablony v dark/gold stylu (received / confirmed / rescheduled / cancelled).
- **Cancel přes token** – zákazník zruší rezervaci jedním klikem z mailu.
- **Optimalizováno na 10 000+ rezervací** – správné indexy, žádné `SELECT *`.

---

## Adresářová struktura

```
/                                  ← kořen Git repa & uživatele na hostingu
├── .gitignore
├── README.md
├── install.sql                    ← databázové schéma + seed
├── services.json                  ← legacy data (ponechat pro fallback)
│
├── includes/                      ← MIMO public_html – nepřístupné z webu
│   ├── config.example.php         ← šablona configu (commitnutá)
│   ├── config.php                 ← REÁLNÝ config (NIKDY necommitovat)
│   ├── bootstrap.php              ← session, PDO, error handler, helpery
│   ├── auth.php                   ← login, logout, brute-force ochrana
│   ├── csrf.php                   ← CSRF tokeny
│   ├── validator.php              ← v_string, v_email, v_phone, v_date, v_time, v_int
│   ├── logger.php                 ← log_event()
│   ├── mailer.php                 ← send_mail() + mail_booking_*()
│   └── booking_helpers.php        ← výpočet volných slotů, zámky
│
├── logs/                          ← MIMO public_html, chmod 770
│   └── (vznikají automaticky)     ← access.log, actions.log, bookings.log, mail.log, errors.log, php_errors.log
│
├── templates/emails/              ← HTML šablony s {{placeholdery}}
│   ├── booking_received.html
│   ├── booking_confirmed.html
│   ├── booking_rescheduled.html
│   └── booking_cancelled.html
│
└── public_html/                   ← DOCUMENT ROOT (sem míří doména)
    ├── .htaccess                  ← HTTPS redirect, security headers, cache
    ├── index.html                 ← veřejná stránka s rezervací
    ├── cancel.php                 ← zákaznický cancel přes token
    ├── favicon.png
    ├── apple-touch-icon.png
    ├── logo.png
    ├── assets/
    │   ├── css/styles.css
    │   └── js/
    │       ├── main.js            ← původní frontend logika (NEMĚNIT)
    │       └── booking-api.js     ← integrace s API (overlay)
    │
    ├── photos/
    │
    ├── admin/
    │   ├── .htaccess              ← deny direct access k admin.html
    │   ├── index.php              ← guard + injekce CSRF meta
    │   ├── admin.html             ← admin UI (přístup jen přes index.php)
    │   ├── admin.js               ← admin frontend logika
    │   ├── login.php
    │   └── logout.php
    │
    └── api/
        ├── public/                ← bez loginu
        │   ├── services.php       ← GET seznam aktivních služeb
        │   ├── slots.php          ← GET ?date=&service_id=
        │   └── book.php           ← POST nová rezervace
        │
        └── admin/                 ← vyžaduje admin session
            ├── bookings.php       ← GET / PATCH / DELETE
            ├── services.php       ← GET / POST / PATCH / DELETE
            ├── hours.php          ← GET / POST
            └── stats.php          ← GET dashboard čísla
```

---

## Požadavky

- **PHP 8.1+** s extenzemi: `pdo_mysql`, `mbstring`, `json`, `openssl`
- **MySQL 5.7+** nebo **MariaDB 10.3+**
- **Apache** s `mod_rewrite`, `mod_headers`, `mod_deflate`, `mod_expires`
- **PHP `mail()`** funkční (sendmail / postfix na hostingu)
- **HTTPS certifikát** (Let's Encrypt stačí)
- **Zápis do `/logs/`** (chmod 770)
- Hosting umožňující složky **mimo `public_html`** (běžné u Wedos, Active24, FORPSI atd.)

---

## Instalace – krok za krokem

### 1) Nahraj soubory na hosting

Strukturu výše (kořen repa = kořen FTP uživatele). Document root (kam míří doména) směřuje do `public_html/`. Složky `includes/`, `logs/`, `templates/` musí být **o úroveň výš**, aby z webu nebyly přístupné.

Pokud hosting nepodporuje složky mimo `public_html`, lze je dát i dovnitř, ale **MUSÍŠ** přidat `Require all denied` v jejich `.htaccess` – jinak budou kódy a logy veřejně čitelné.

### 2) Vytvoř databázi

V administraci hostingu (phpMyAdmin / cPanel):
- Vytvoř DB s collation **`utf8mb4_czech_ci`**.
- Vytvoř uživatele s plnými právy na danou DB.

### 3) Importuj schéma

Přes phpMyAdmin import → vyber `install.sql`. Nebo z příkazové řádky:

```bash
mysql -u DB_USER -p DB_NAME < install.sql
```

Skript vytvoří 5 tabulek (`services`, `working_hours`, `day_overrides`, `bookings`, `login_attempts`) a naplní:
- Otvírací dobu (Po–Pá 9–18, So 9–13, Ne zavřeno).
- 16 služeb podle nabídky kadeřnictví.

### 4) Vytvoř `config.php`

```bash
cp includes/config.example.php includes/config.php
```

Otevři `includes/config.php` a vyplň:

```php
'db' => [
    'host'     => 'localhost',
    'dbname'   => 'vkadernictvi',
    'user'     => 'tvoj_db_user',
    'password' => 'tvoje_db_heslo',
],

'admin' => [
    'username'      => 'faris',
    'password_hash' => '$2y$10$...',  // viz krok 5
],

'site' => [
    'name'            => 'Vkadeřnictví',
    'url'             => 'https://www.vkadernictvi.cz',
    'admin_email'     => 'rezervace@vkadernictvi.cz',
    'reply_to'        => 'rezervace@vkadernictvi.cz',
    'envelope_sender' => 'rezervace@vkadernictvi.cz',
],

'auth' => [
    'cookie_secure' => true,  // ⚠ V PRODUKCI na true!
    // ...
],

'debug' => false,             // ⚠ V PRODUKCI na false
```

### 5) Vygeneruj bcrypt hash hesla

Lokálně nebo přímo na hostingu (přes SSH nebo PHP soubor):

```bash
php -r "echo password_hash('TVOJE_HESLO', PASSWORD_BCRYPT) . PHP_EOL;"
```

Výstup (~60 znaků začíná `$2y$`) zkopíruj do `'password_hash'` v configu.

⚠ **Heslo nikdy neukládej v plain textu**, neposílej e-mailem ani necommituj.

### 6) Nastav oprávnění

```bash
chmod 755 public_html
chmod 750 includes
chmod 770 logs                     # PHP musí mít právo zapisovat
chmod 640 includes/config.php      # citlivé údaje
chmod 644 public_html/.htaccess
```

Vlastník `logs/` musí být uživatel pod kterým běží PHP (často `www-data` nebo FTP user).

### 7) Otestuj instalaci

| URL | Očekávaný výsledek |
|---|---|
| `https://www.vkadernictvi.cz/` | hlavní stránka, v sekci „Naše služby" se načte 16 služeb |
| `https://www.vkadernictvi.cz/api/public/services.php` | JSON `{ "ok": true, "data": [...] }` |
| `https://www.vkadernictvi.cz/admin/` | redirect na `login.php` |
| `https://www.vkadernictvi.cz/admin/admin.html` | **403 Forbidden** (nikdy nesmí jít přes přímou URL) |

Po přihlášení do `/admin/` ověř:
- načtení rezervací, služeb a otvírací doby (žádné chyby v konzoli)
- vytvoření testovací rezervace přes hlavní web → měl by přijít e-mail

### 8) Ověř logy

```bash
ls -la logs/
tail -f logs/errors.log
```

Pokud chyby v `errors.log` → debug. Pokud `mail.log` ukazuje neúspěch → ověř funkčnost `mail()` na hostingu.

---

## Konfigurace business pravidel

V `includes/config.php` v sekci `booking`:

| Klíč | Význam | Default |
|---|---|---|
| `slot_grid_min` | Mřížka slotů v minutách (15 = doporučeno) | 15 |
| `min_ahead_min` | Pro dnešek minimálně X min do startu | 60 |
| `max_days_ahead` | Jak daleko dopředu lze rezervovat (dny) | 90 |
| `rate_limit_per_hour` | Max rezervací z 1 IP / hodinu | 5 |

---

## E-mailové šablony

V `/templates/emails/` jsou 4 HTML šablony s placeholdery `{{customer_name}}`, `{{service_name}}`, `{{date}}`, `{{time}}`, `{{duration}}`, `{{price}}`, `{{cancel_url}}`, `{{site_name}}`, `{{site_url}}`, navíc `{{old_datetime}}` v `booking_rescheduled.html`.

Pokud chceš změnit text/styl, edituj přímo HTML – placeholdery zůstanou nahrazené automaticky.

---

## Bezpečnost – co je už vyřešeno

- Všechny SQL dotazy přes **PDO prepared statements**.
- **CSRF** ochrana v admin API přes `X-CSRF-Token` hlavičku (token v `<meta>`).
- **Brute-force** ochrana (`login_attempts` + 15 min lock).
- **Honeypot** v public booking formuláři (skryté pole `website`).
- **Rate limit** 5 rezervací/hod/IP.
- **Session security** – `httponly`, `samesite=Lax`, `secure` v produkci, regenerace ID každých 30 min.
- **CSP-friendly** – žádný inline JS v `cancel.php`, login.php (jen inline CSS).
- **HTTPS redirect** v root `.htaccess`.
- **Security hlavičky** – `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `HSTS`.
- **Skrytí citlivých souborů** – `.htaccess` blokuje `.sql`, `.log`, `.md`, `.example`, dotfiles atd.
- **Globální `set_exception_handler`** – výjimky jdou do `errors.log`, uživatel vidí 500 + obecnou hlášku (v produkci, kde `debug=false`).

---

## Údržba

### Reset hesla admina

Vygeneruj nový hash a přepiš `'password_hash'` v `includes/config.php`. Žádný zásah do DB nepotřeba.

### Reset brute-force locku (pokud jsi zamkl sám sebe)

```sql
DELETE FROM login_attempts WHERE ip_address = 'TVOJE_IP';
```

### Rotace logů

Logy v `/logs/` rostou. Pro produkci doporučujeme cron:

```cron
0 3 1 * * cd /cesta/k/projektu/logs && for f in *.log; do mv "$f" "$f.$(date +%Y%m).gz" && gzip "$f.$(date +%Y%m).gz"; done
```

Nebo systémový **logrotate** (na sdíleném hostingu obvykle nejde, archivuj ručně).

### Záloha databáze

```bash
mysqldump -u USER -p DB_NAME --default-character-set=utf8mb4 > backup_$(date +%Y%m%d).sql
```

Doporučujeme cron 1× denně.

---

## Časté problémy (FAQ)

**Nepřicházejí maily.**
Ověř `tail -f logs/mail.log`. Pokud `OK` ale mail nepřijde → spam filtr / SPF / DKIM. Hosting musí mít správně nastavenou doménu pro odchozí poštu z `envelope_sender`.

**Po přihlášení mě hned odhlásí.**
Nesedí cookie domény nebo `cookie_secure=true` na HTTP. Buď přepni na HTTPS, nebo dočasně `'cookie_secure' => false`.

**„Slot není dostupný" i když je den prázdný.**
Zkontroluj `working_hours` v DB pro daný `day_of_week` (1=Po, 7=Ne) a případné `day_overrides` na konkrétní datum. Pokud je `is_closed=1`, žádné sloty se negenerují.

**500 Internal Server Error.**
`tail -f logs/errors.log` → uvidíš stack trace. 99 % chyb = chybějící DB tabulka, špatné DB heslo, nebo `logs/` bez práva na zápis.

**Admin panel: „CSRF token mismatch".**
Stránka byla načtena z cache nebo session vypršela. Refresh (Ctrl+F5) → znovu přihlášení.

---

## Licence

Soukromý projekt pro kadeřnictví Vkadernictvi.cz.
Autor: Faris
