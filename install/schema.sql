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

-- Aplicacoes que o utilizador retirou da sua propria lista.
--
-- Nao lhe retira o acesso: e so uma arrumacao do lado dele. Um
-- administrador pode repor a aplicacao na ficha do utilizador.
CREATE TABLE IF NOT EXISTS user_apps_hidden (
  user_id    INT UNSIGNED NOT NULL,
  app_id     INT UNSIGNED NOT NULL,
  hidden_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, app_id),
  KEY idx_hidden_app (app_id),
  CONSTRAINT fk_hidden_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_hidden_app  FOREIGN KEY (app_id)  REFERENCES apps(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preferencias pessoais (cor da barra de topo, por agora). Cada
-- utilizador escolhe as suas e nao afetam mais ninguem.
CREATE TABLE IF NOT EXISTS user_prefs (
  user_id  INT UNSIGNED NOT NULL,
  pkey     VARCHAR(48)  NOT NULL,
  pvalue   VARCHAR(120) NULL,
  PRIMARY KEY (user_id, pkey),
  CONSTRAINT fk_user_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

-- Politica alteravel em Administracao -> Resumo, sem mexer no config.php.
--   mfa_enforce_all      1 = MFA obrigatorio a todos; 0 = cada um decide
--   password_min_length  minimo de caracteres das palavras-passe
INSERT INTO settings (skey, svalue) VALUES
  ('schema_version', '2'),
  ('mfa_enforce_all', '0'),
  ('password_min_length', '6')
ON DUPLICATE KEY UPDATE svalue = svalue;


-- =====================================================================
-- Dados das aplicacoes alojadas
-- =====================================================================
-- Ate aqui a plataforma so guardava o ficheiro HTML; os dados viviam no
-- localStorage do browser de cada pessoa -- perdiam-se ao limpar o
-- browser e ninguem via o que os outros escreviam. Estas tabelas passam
-- os dados para o servidor.
--
-- Cada linha aponta para a aplicacao (app_id): a mesma instalacao pode
-- alojar duas copias da aplicacao sem os dados se misturarem.
--
-- O uid e o identificador que a aplicacao usa do lado do browser. E ele
-- que liga um planeamento a uma obra, por isso e guardado tal e qual.

-- Registo dos campos que cada versao da aplicacao declara guardar.
-- E o que permite avisar quando uma versao nova traz campos novos.
CREATE TABLE IF NOT EXISTS app_campos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_id       INT UNSIGNED NOT NULL,
  colecao      VARCHAR(64)  NOT NULL,
  campo        VARCHAR(64)  NOT NULL,
  tipo         VARCHAR(32)  NOT NULL DEFAULT 'texto',
  tem_coluna   TINYINT(1)   NOT NULL DEFAULT 0,
  visto_em     INT UNSIGNED NULL,
  criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campo (app_id, colecao, campo),
  CONSTRAINT fk_campos_app FOREIGN KEY (app_id) REFERENCES apps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Obras.
CREATE TABLE IF NOT EXISTS app_obras (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_id     INT UNSIGNED NOT NULL,
  uid        INT UNSIGNED NOT NULL,
  client     VARCHAR(160) NOT NULL DEFAULT '',
  project    VARCHAR(160) NOT NULL DEFAULT '',
  cost       VARCHAR(80)  NOT NULL DEFAULT '',
  cost_desc  VARCHAR(255) NOT NULL DEFAULT '',
  manager    VARCHAR(160) NOT NULL DEFAULT '',
  fps        VARCHAR(160) NOT NULL DEFAULT '',
  fps_end    DATE         NULL,
  valor      DECIMAL(14,2) NULL,
  closed     TINYINT(1)   NOT NULL DEFAULT 0,
  closed_at  DATE         NULL,
  extras     LONGTEXT     NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  alterado_em DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  alterado_por INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_obra (app_id, uid),
  KEY idx_obra_gestor (app_id, manager),
  CONSTRAINT fk_obras_app FOREIGN KEY (app_id) REFERENCES apps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planeamentos semanais. work_uid aponta para app_obras.uid da mesma
-- aplicacao; nao e chave estrangeira porque a aplicacao envia os dois
-- conjuntos de uma vez e a ordem de gravacao nao esta garantida.
CREATE TABLE IF NOT EXISTS app_planeamentos (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_id       INT UNSIGNED NOT NULL,
  uid          INT UNSIGNED NOT NULL,
  work_uid     INT UNSIGNED NOT NULL,
  week         DATE         NULL,
  supervisor   VARCHAR(160) NOT NULL DEFAULT '',
  set1_leader  VARCHAR(160) NOT NULL DEFAULT '',
  set1_helper1 VARCHAR(160) NOT NULL DEFAULT '',
  set1_helper2 VARCHAR(160) NOT NULL DEFAULT '',
  set1_helper3 VARCHAR(160) NOT NULL DEFAULT '',
  set2_leader  VARCHAR(160) NOT NULL DEFAULT '',
  set2_helper1 VARCHAR(160) NOT NULL DEFAULT '',
  set2_helper2 VARCHAR(160) NOT NULL DEFAULT '',
  set2_helper3 VARCHAR(160) NOT NULL DEFAULT '',
  contractor_name VARCHAR(160) NOT NULL DEFAULT '',
  con_leader   VARCHAR(160) NOT NULL DEFAULT '',
  con_helper1  VARCHAR(160) NOT NULL DEFAULT '',
  con_helper2  VARCHAR(160) NOT NULL DEFAULT '',
  con_helper3  VARCHAR(160) NOT NULL DEFAULT '',
  progress     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status       VARCHAR(20)  NOT NULL DEFAULT 'planned',
  extras       LONGTEXT     NULL,
  criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  alterado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  alterado_por INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plano (app_id, uid),
  KEY idx_plano_obra (app_id, work_uid),
  KEY idx_plano_semana (app_id, week),
  CONSTRAINT fk_planos_app FOREIGN KEY (app_id) REFERENCES apps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dias em obra de cada planeamento. Uma linha por dia assinalado: a
-- ausencia de linha quer dizer "nao ha trabalho nesse dia", distincao
-- que sete colunas de texto nao conseguiam guardar.
CREATE TABLE IF NOT EXISTS app_planeamento_dias (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plano_id  BIGINT UNSIGNED NOT NULL,
  dia       ENUM('mon','tue','wed','thu','fri','sat','sun') NOT NULL,
  descricao TEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dia (plano_id, dia),
  CONSTRAINT fk_dias_plano FOREIGN KEY (plano_id) REFERENCES app_planeamentos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Definicoes da aplicacao (ex.: objetivo minimo semanal de producao).
CREATE TABLE IF NOT EXISTS app_definicoes (
  app_id      INT UNSIGNED NOT NULL,
  chave       VARCHAR(64)  NOT NULL,
  valor       TEXT         NULL,
  alterado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (app_id, chave),
  CONSTRAINT fk_defs_app FOREIGN KEY (app_id) REFERENCES apps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
