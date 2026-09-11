-- =====================================================================
-- Repor colunas em falta nas tabelas de dados das aplicacoes
-- =====================================================================
-- Correr no phpMyAdmin da base de dados da plataforma, ou pelo terminal.
--
-- E seguro correr as vezes que forem precisas: cada coluna so e criada se
-- nao existir, e nada e apagado nem alterado. Os dados que ja la estao
-- ficam onde estao.
--
-- Para que serve: se a tabela app_planeamentos nasceu sem as colunas das
-- equipas, o supervisor grava e os chefes de equipa nao. Isto poe as
-- colunas de pe. A seguir, os planeamentos novos gravam tudo.

-- ---------------------------------------------------------------------
-- Um passo por coluna. O MySQL nao tem "ADD COLUMN IF NOT EXISTS", por
-- isso pergunta-se primeiro e so depois se prepara o comando.
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS setronix_repor_coluna;

DELIMITER //
CREATE PROCEDURE setronix_repor_coluna(
  IN p_tabela VARCHAR(64),
  IN p_coluna VARCHAR(64),
  IN p_tipo   VARCHAR(120)
)
BEGIN
  DECLARE ja INT DEFAULT 0;
  SELECT COUNT(*) INTO ja
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name   = p_tabela
     AND column_name  = p_coluna;

  IF ja = 0 THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tabela, '` ADD COLUMN `', p_coluna, '` ', p_tipo);
    PREPARE passo FROM @sql;
    EXECUTE passo;
    DEALLOCATE PREPARE passo;
    SELECT CONCAT('criada: ', p_tabela, '.', p_coluna) AS resultado;
  ELSE
    SELECT CONCAT('ja existia: ', p_tabela, '.', p_coluna) AS resultado;
  END IF;
END //
DELIMITER ;

-- ---------------------------------------------------------------------
-- Planeamentos semanais: supervisor, as duas equipas e o subempreiteiro
-- ---------------------------------------------------------------------
CALL setronix_repor_coluna('app_planeamentos', 'work_uid',        'INT UNSIGNED NOT NULL DEFAULT 0');
CALL setronix_repor_coluna('app_planeamentos', 'week',            'DATE NULL');
CALL setronix_repor_coluna('app_planeamentos', 'supervisor',      'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'set1_leader',     'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'set1_helper1',    'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'set1_helper2',    'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'set1_helper3',    'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'set2_leader',     'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'set2_helper1',    'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'set2_helper2',    'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'set2_helper3',    'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'contractor_name', 'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'con_leader',      'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'con_helper1',     'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'con_helper2',     'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'con_helper3',     'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_planeamentos', 'progress',        'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
CALL setronix_repor_coluna('app_planeamentos', 'status',          'VARCHAR(20) NOT NULL DEFAULT "planned"');
CALL setronix_repor_coluna('app_planeamentos', 'extras',          'LONGTEXT NULL');
CALL setronix_repor_coluna('app_planeamentos', 'criado_em',       'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
CALL setronix_repor_coluna('app_planeamentos', 'alterado_em',     'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL setronix_repor_coluna('app_planeamentos', 'alterado_por',    'INT UNSIGNED NULL');

-- ---------------------------------------------------------------------
-- Obras
-- ---------------------------------------------------------------------
CALL setronix_repor_coluna('app_obras', 'client',       'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_obras', 'project',      'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_obras', 'cost',         'VARCHAR(80) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_obras', 'cost_desc',    'VARCHAR(255) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_obras', 'manager',      'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_obras', 'fps',          'VARCHAR(160) NOT NULL DEFAULT ""');
CALL setronix_repor_coluna('app_obras', 'fps_end',      'DATE NULL');
CALL setronix_repor_coluna('app_obras', 'valor',        'DECIMAL(14,2) NULL');
CALL setronix_repor_coluna('app_obras', 'closed',       'TINYINT(1) NOT NULL DEFAULT 0');
CALL setronix_repor_coluna('app_obras', 'closed_at',    'DATE NULL');
CALL setronix_repor_coluna('app_obras', 'extras',       'LONGTEXT NULL');
CALL setronix_repor_coluna('app_obras', 'criado_em',    'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
CALL setronix_repor_coluna('app_obras', 'alterado_em',  'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL setronix_repor_coluna('app_obras', 'alterado_por', 'INT UNSIGNED NULL');

-- ---------------------------------------------------------------------
-- Nivel de permissao por aplicacao (viewer / editor / admin)
--
-- Quem ja tinha acesso fica como 'editor', que e o valor por omissao da
-- coluna. Para mudar alguem, use Administracao -> Permissoes.
-- ---------------------------------------------------------------------
CALL setronix_repor_coluna('user_apps', 'nivel',
     "ENUM('viewer','editor','admin') NOT NULL DEFAULT 'editor' AFTER app_id");

DROP PROCEDURE IF EXISTS setronix_repor_coluna;
