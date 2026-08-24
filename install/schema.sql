-- =====================================================================
-- Plataforma Setronix - Planeamento Operacional de Obras
-- Esquema de base de dados (MySQL 5.7+ / MariaDB 10.3+)
-- Charset utf8mb4 para suportar acentuacao portuguesa.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. UTILIZADORES, PERFIS E SEGURANCA
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username        VARCHAR(64)  NOT NULL,
  email           VARCHAR(190) NOT NULL,
  full_name       VARCHAR(160) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('admin','gestor','supervisor','leitor') NOT NULL DEFAULT 'leitor',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_pw  TINYINT(1)   NOT NULL DEFAULT 1,
  mfa_enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  mfa_secret      VARBINARY(512) NULL,
  mfa_confirmed_at DATETIME    NULL,
  mfa_required    TINYINT(1)   NOT NULL DEFAULT 1,
  failed_logins   INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME     NULL,
  last_login_at   DATETIME     NULL,
  last_login_ip   VARCHAR(45)  NULL,
  password_changed_at DATETIME NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by      INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Codigos de recuperacao MFA (guardados apenas em hash, uso unico)
CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  code_hash   VARCHAR(255) NOT NULL,
  used_at     DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mfa_rc_user (user_id),
  CONSTRAINT fk_mfa_rc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessoes aplicacionais (permite terminar sessoes remotamente e auditar)
CREATE TABLE IF NOT EXISTS user_sessions (
  id            CHAR(64)     NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  ip            VARCHAR(45)  NULL,
  user_agent    VARCHAR(255) NULL,
  mfa_passed    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas de autenticacao (rate limiting + deteccao de ataque)
CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username    VARCHAR(64)  NULL,
  ip          VARCHAR(45)  NULL,
  successful  TINYINT(1)   NOT NULL DEFAULT 0,
  stage       ENUM('password','mfa','recovery') NOT NULL DEFAULT 'password',
  reason      VARCHAR(80)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_ip_time (ip, created_at),
  KEY idx_attempts_user_time (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. LISTAS BASE (origem: ficheiro "LISTA DE SELECAO.xlsx")
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS list_types (
  code         VARCHAR(32)  NOT NULL,
  label        VARCHAR(120) NOT NULL,
  excel_header VARCHAR(120) NULL,
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS list_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_code   VARCHAR(32)  NOT NULL,
  value       VARCHAR(190) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  source      ENUM('manual','import') NOT NULL DEFAULT 'manual',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_list_item (type_code, value),
  KEY idx_list_type_active (type_code, is_active),
  CONSTRAINT fk_list_item_type FOREIGN KEY (type_code) REFERENCES list_types(code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historico de importacoes de ficheiros base
CREATE TABLE IF NOT EXISTS import_runs (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NULL,
  filename          VARCHAR(255) NOT NULL,
  file_hash         CHAR(64)     NULL,
  mode              ENUM('merge','replace') NOT NULL DEFAULT 'merge',
  rows_read         INT UNSIGNED NOT NULL DEFAULT 0,
  items_added       INT UNSIGNED NOT NULL DEFAULT 0,
  items_reactivated INT UNSIGNED NOT NULL DEFAULT 0,
  items_deactivated INT UNSIGNED NOT NULL DEFAULT 0,
  status            ENUM('ok','error') NOT NULL DEFAULT 'ok',
  message           TEXT         NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_import_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. OBRAS E PLANEAMENTO SEMANAL
--    (espelha o modelo do prototipo HTML: works[] e plans[])
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS works (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid         INT UNSIGNED NOT NULL,
  client      VARCHAR(190) NOT NULL,
  project     VARCHAR(190) NOT NULL,
  cost_center VARCHAR(64)  NOT NULL,
  cost_desc   VARCHAR(255) NOT NULL,
  manager     VARCHAR(190) NOT NULL,
  fps         VARCHAR(8)   NOT NULL,
  fps_end     DATE         NULL,
  value_eur   DECIMAL(12,2) NULL,
  is_archived TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  INT UNSIGNED NULL,
  updated_by  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_works_uid (uid),
  KEY idx_works_client (client),
  KEY idx_works_manager (manager),
  KEY idx_works_cc (cost_center)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid          INT UNSIGNED NOT NULL,
  work_id      INT UNSIGNED NOT NULL,
  week_start   DATE         NOT NULL,
  supervisor   VARCHAR(190) NOT NULL,
  set1_leader  VARCHAR(190) NULL,
  set1_helper1 VARCHAR(190) NULL,
  set1_helper2 VARCHAR(190) NULL,
  set1_helper3 VARCHAR(190) NULL,
  set2_leader  VARCHAR(190) NULL,
  set2_helper1 VARCHAR(190) NULL,
  set2_helper2 VARCHAR(190) NULL,
  set2_helper3 VARCHAR(190) NULL,
  con_leader   VARCHAR(190) NULL,
  con_helper1  VARCHAR(190) NULL,
  con_helper2  VARCHAR(190) NULL,
  con_helper3  VARCHAR(190) NULL,
  progress     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status       ENUM('planned','executed','not-executed') NOT NULL DEFAULT 'planned',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by   INT UNSIGNED NULL,
  updated_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_uid (uid),
  KEY idx_plans_week (week_start),
  KEY idx_plans_work (work_id),
  KEY idx_plans_leader1 (set1_leader),
  KEY idx_plans_leader2 (set2_leader),
  KEY idx_plans_supervisor (supervisor),
  CONSTRAINT fk_plans_work FOREIGN KEY (work_id) REFERENCES works(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dias em obra + descricao dos trabalhos (mon..sun)
CREATE TABLE IF NOT EXISTS plan_days (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id   INT UNSIGNED NOT NULL,
  day_key   ENUM('mon','tue','wed','thu','fri','sat','sun') NOT NULL,
  day_date  DATE         NOT NULL,
  task      VARCHAR(500) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plan_day (plan_id, day_key),
  KEY idx_plan_day_date (day_date),
  CONSTRAINT fk_plan_days_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. AUDITORIA (log de alteracoes)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(64)  NULL,
  action      VARCHAR(48)  NOT NULL,
  entity      VARCHAR(48)  NULL,
  entity_id   VARCHAR(64)  NULL,
  summary     VARCHAR(255) NULL,
  data_before LONGTEXT     NULL,
  data_after  LONGTEXT     NULL,
  ip          VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_time (created_at),
  KEY idx_audit_user (user_id),
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. BACKUPS
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS backups (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename    VARCHAR(255) NOT NULL,
  size_bytes  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  kind        ENUM('manual','auto','pre-restore','pre-import') NOT NULL DEFAULT 'manual',
  sha256      CHAR(64)     NULL,
  user_id     INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_backups_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. DEFINICOES DA APLICACAO
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS settings (
  skey        VARCHAR(64)  NOT NULL,
  svalue      TEXT         NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. DADOS INICIAIS
-- ---------------------------------------------------------------------

INSERT INTO list_types (code, label, excel_header, sort_order) VALUES
  ('clients',     'Clientes',                 'CLIENTE',                   1),
  ('projects',    'Projetos',                 'PROJETO',                   2),
  ('managers',    'Gestores de projeto',      'GESTOR DE PROJETO',         3),
  ('fps',         'FPS/PSS',                  'FPS/PSS',                   4),
  ('supervisors', 'Supervisores',             'SUPERVISOR',                5),
  ('setLeaders',  'Chefes de equipa Setronix','CHEFE DE EQUIPA SETRONIX',  6),
  ('setHelpers',  'Ajudantes Setronix',       'AJUDANTE SETRONIX 1',       7),
  ('tasks',       'Tarefas tipo',             'TAREFAS TIPO',              8)
ON DUPLICATE KEY UPDATE label = VALUES(label), excel_header = VALUES(excel_header), sort_order = VALUES(sort_order);

INSERT INTO settings (skey, svalue) VALUES
  ('schema_version', '1'),
  ('app_name', 'Planeamento Operacional de Obras'),
  ('mfa_enforce_all', '1'),
  ('backup_retention_days', '90')
ON DUPLICATE KEY UPDATE svalue = svalue;
