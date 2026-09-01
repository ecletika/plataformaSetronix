-- =====================================================================
-- Plataforma Setronix
-- Esquema de base de dados (MySQL 5.7+ / MariaDB 10.3+)
-- Charset utf8mb4 para suportar acentuacao portuguesa.
--
-- A plataforma e apenas a casca: contas, autenticacao com MFA e um
-- repositorio de aplicacoes HTML autonomas enviadas por upload.
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
-- 2. APLICACOES HTML
--
-- Cada aplicacao e um ficheiro .html autonomo enviado por um
-- administrador. O ficheiro fica em storage/apps/ (fora do alcance da
-- web) e e servido por app_raw.php apenas a quem tem sessao iniciada.
-- Cada envio cria uma nova versao, o que permite repor a anterior.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS apps (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug               VARCHAR(64)  NOT NULL,
  name               VARCHAR(160) NOT NULL,
  description        VARCHAR(500) NULL,
  current_version_id INT UNSIGNED NULL,
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order         SMALLINT     NOT NULL DEFAULT 0,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_apps_slug (slug),
  KEY idx_apps_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_versions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_id       INT UNSIGNED NOT NULL,
  version      INT UNSIGNED NOT NULL DEFAULT 1,
  filename     VARCHAR(255) NOT NULL,
  storage_name VARCHAR(120) NOT NULL,
  size_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
  sha256       CHAR(64)     NULL,
  notes        VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_version (app_id, version),
  KEY idx_app_versions_app (app_id),
  CONSTRAINT fk_app_versions_app FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem pode abrir cada aplicacao.
--
-- Sem qualquer linha para uma aplicacao, ela e visivel para todos os
-- utilizadores. Assim que existir pelo menos uma linha, so os utilizadores
-- ai indicados a veem. Para esconder de toda a gente usa-se antes o
-- apps.is_active = 0.
CREATE TABLE IF NOT EXISTS user_apps (
  user_id     INT UNSIGNED NOT NULL,
  app_id      INT UNSIGNED NOT NULL,
  granted_by  INT UNSIGNED NULL,
  granted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, app_id),
  KEY idx_user_apps_app (app_id),
  CONSTRAINT fk_user_apps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_apps_app  FOREIGN KEY (app_id)  REFERENCES apps(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. AUDITORIA (log de alteracoes)
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
-- 4. DEFINICOES DA APLICACAO
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS settings (
  skey        VARCHAR(64)  NOT NULL,
  svalue      TEXT         NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (skey, svalue) VALUES
  ('schema_version', '2'),
  ('mfa_enforce_all', '1')
ON DUPLICATE KEY UPDATE svalue = svalue;
