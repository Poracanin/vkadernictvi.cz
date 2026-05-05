-- =====================================================================
-- vkadernictvi.cz – Rezervační systém
-- Instalační SQL skript
--
-- Použití:
--   1) Vytvoř databázi v cPanelu (např. user_vkadernictvi)
--   2) Importuj tento soubor přes phpMyAdmin nebo: mysql -u user -p db < install.sql
--   3) Hodnoty z config.example.php nakopíruj do config.php
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+01:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabulka: services (nabízené služby + ceník)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `services`;
CREATE TABLE `services` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(150) NOT NULL,
    `duration_min` SMALLINT UNSIGNED NOT NULL,
    `price`        DECIMAL(8,2) DEFAULT NULL COMMENT 'NULL = na dotaz',
    `icon`         VARCHAR(60)  DEFAULT NULL,
    `category`     VARCHAR(40)  DEFAULT NULL,
    `subcategory`  VARCHAR(40)  DEFAULT NULL,
    `description`  TEXT         DEFAULT NULL,
    `sort_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------
-- Tabulka: working_hours (otvírací doba podle dne v týdnu)
-- day_of_week 1=Po, 2=Út, 3=St, 4=Čt, 5=Pá, 6=So, 7=Ne (ISO – PHP date('N'))
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `working_hours`;
CREATE TABLE `working_hours` (
    `day_of_week` TINYINT UNSIGNED NOT NULL,
    `open_time`   TIME DEFAULT NULL,
    `close_time`  TIME DEFAULT NULL,
    `is_closed`   TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------
-- Tabulka: day_overrides (svátky, dovolená, mimořádné otvíračky)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `day_overrides`;
CREATE TABLE `day_overrides` (
    `date`       DATE NOT NULL,
    `open_time`  TIME DEFAULT NULL,
    `close_time` TIME DEFAULT NULL,
    `is_closed`  TINYINT(1) NOT NULL DEFAULT 0,
    `reason`     VARCHAR(150) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------
-- Tabulka: bookings (rezervace)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `bookings`;
CREATE TABLE `bookings` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `service_id`     INT UNSIGNED NOT NULL,
    `start_at`       DATETIME NOT NULL,
    `end_at`         DATETIME NOT NULL,
    `customer_name`  VARCHAR(120) NOT NULL,
    `customer_email` VARCHAR(190) NOT NULL,
    `customer_phone` VARCHAR(30)  NOT NULL,
    `note`           TEXT DEFAULT NULL,
    `status`         ENUM('pending','confirmed','cancelled','done','no_show') NOT NULL DEFAULT 'pending',
    `cancel_token`   CHAR(64) NOT NULL,
    `ip_address`     VARCHAR(45) DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_cancel_token` (`cancel_token`),
    KEY `idx_start_at` (`start_at`),
    KEY `idx_status_start` (`status`, `start_at`),
    KEY `idx_email` (`customer_email`),
    KEY `fk_service` (`service_id`),
    CONSTRAINT `fk_bookings_service` FOREIGN KEY (`service_id`)
        REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------------
-- Tabulka: login_attempts (bruteforce ochrana)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
    `ip_address`   VARCHAR(45) NOT NULL,
    `attempts`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_attempt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `locked_until` DATETIME DEFAULT NULL,
    PRIMARY KEY (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Výchozí data
-- =====================================================================

-- Otvírací doba – Po-Pá 9-18, So 9-13, Ne zavřeno
INSERT INTO `working_hours` (`day_of_week`, `open_time`, `close_time`, `is_closed`) VALUES
    (1, '09:00:00', '18:00:00', 0),
    (2, '09:00:00', '18:00:00', 0),
    (3, '09:00:00', '18:00:00', 0),
    (4, '09:00:00', '18:00:00', 0),
    (5, '09:00:00', '18:00:00', 0),
    (6, '09:00:00', '13:00:00', 0),
    (7, NULL, NULL, 1);

-- Služby (seed dle services.json – reálná data webu)
INSERT INTO `services` (`name`, `duration_min`, `price`, `icon`, `category`, `subcategory`, `description`, `sort_order`, `is_active`) VALUES
    ('Vstupní návštěva — čištění vlasů',                120, 2150.00, 'fa-spray-can', 'damske', 'poprve',     'Před barvením nebo zesvětlením je u nás podmínkou první návštěva, která se skládá z čištění vlasů.', 10,  1),
    ('Vstupní návštěva — čištění vlasů + střih',       120, 2650.00, 'fa-cut',       'damske', 'poprve',     '', 20,  1),
    ('Dámský střih',                                    60,  1550.00, 'fa-cut',       'damske', 'poprve',     '', 30,  1),
    ('Foukaná',                                          60,  950.00,  'fa-wind',      'damske', 'poprve',     '', 40,  1),
    ('Barvení odrostu BARVOU',                           120, 2150.00, 'fa-palette',   'damske', 'pravidelne', 'Barvení odrostu BARVOU, nejedná se o zesvětlení — doplnění melíru apod.', 50,  1),
    ('Barvení odrostu + střih',                          120, 2650.00, 'fa-palette',   'damske', 'pravidelne', '', 60,  1),
    ('Barva / přeliv celé délky',                        120, 2650.00, 'fa-tint',      'damske', 'pravidelne', 'V případě velké hustoty je účtován příplatek za každou použitou tubu barvy navíc.', 70,  1),
    ('Barva / přeliv celé délky + střih',                120, 3250.00, 'fa-tint',      'damske', 'pravidelne', 'V případě velké hustoty je účtován příplatek za každou použitou tubu barvy navíc.', 80,  1),
    ('Foukaná (pravidelně)',                             60,  950.00,  'fa-wind',      'damske', 'pravidelne', '', 90,  1),
    ('Zesvětlení (foilayage, balayage, melír)',          300, NULL,    'fa-magic',     'damske', 'pravidelne', 'Neobjednávat se! Rezervace bude zrušena. Na službu je možné se objednat po předchozí domluvě.', 100, 1),
    ('Pánský střih',                                     40,  650.00,  'fa-cut',       'panske', NULL,         '', 110, 1),
    ('Stříhání + vousy',                                 60,  950.00,  'fa-user-tie',  'panske', NULL,         '', 120, 1),
    ('Střih + barvení vousů',                            120, 1200.00, 'fa-palette',   'panske', NULL,         '', 130, 1),
    ('Střih + úprava vousů + péče o pleť + masáž',       90,  1600.00, 'fa-spa',       'panske', NULL,         '', 140, 1),
    ('Vousy',                                            30,  550.00,  'fa-user-tie',  'panske', NULL,         '', 150, 1),
    ('Dětský střih',                                     30,  250.00,  'fa-child',     'detske', NULL,         '', 160, 1);
