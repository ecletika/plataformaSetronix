-- =====================================================================
-- Migracao v1 -> v2
--
-- A v1 continha o modulo de planeamento de obras (listas base, obras,
-- planeamentos, importacoes e backups). A v2 e apenas a casca: contas +
-- aplicacoes HTML enviadas por upload.
--
-- ORDEM DE EXECUCAO:
--   1. Faca uma copia de seguranca da base de dados (cPanel -> phpMyAdmin
--      -> Exportar). Este script APAGA dados de forma irreversivel.
--   2. Execute install/schema.sql  (cria as tabelas apps e app_versions)
--   3. Execute este ficheiro       (remove as tabelas que deixaram de ser usadas)
--
-- As tabelas de contas (users, mfa_recovery_codes, user_sessions,
-- login_attempts), o audit_log e as settings ficam intactos: ninguem
-- perde a conta nem o MFA.
-- =====================================================================

SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS plan_days;
DROP TABLE IF EXISTS plans;
DROP TABLE IF EXISTS works;
DROP TABLE IF EXISTS import_runs;
DROP TABLE IF EXISTS list_items;
DROP TABLE IF EXISTS list_types;
DROP TABLE IF EXISTS backups;

SET FOREIGN_KEY_CHECKS = 1;

DELETE FROM settings WHERE skey IN ('app_name', 'backup_retention_days');

UPDATE settings SET svalue = '2' WHERE skey = 'schema_version';
