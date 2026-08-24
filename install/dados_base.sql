-- =====================================================================
-- Plataforma Setronix — dados base
--
-- Preenche as listas de seleção a partir do ficheiro
-- "LISTA DE SELEÇÃO.xlsx" fornecido pela Setronix.
--
-- Executar DEPOIS de install/schema.sql.
-- Pode ser executado várias vezes sem duplicar valores.
--
-- Gerado em 2026-08-24 · 122 valores
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Clientes (23)
-- ---------------------------------------------------------------------
INSERT INTO list_items (type_code, value, is_active, source) VALUES
  ('clients', 'Águas do Norte', 1, 'import'),
  ('clients', 'Almeida & Alves', 1, 'import'),
  ('clients', 'Cellnex', 1, 'import'),
  ('clients', 'CME', 1, 'import'),
  ('clients', 'Constructel', 1, 'import'),
  ('clients', 'EID', 1, 'import'),
  ('clients', 'EML', 1, 'import'),
  ('clients', 'Finerge', 1, 'import'),
  ('clients', 'Gerbasto', 1, 'import'),
  ('clients', 'Long Wave', 1, 'import'),
  ('clients', 'Marinha', 1, 'import'),
  ('clients', 'Ministério da Defesa Nacional - Exército', 1, 'import'),
  ('clients', 'NAV', 1, 'import'),
  ('clients', 'Novintel', 1, 'import'),
  ('clients', 'Rádio Comercial - Bauermedia', 1, 'import'),
  ('clients', 'Rádio Renascença', 1, 'import'),
  ('clients', 'Repart', 1, 'import'),
  ('clients', 'Setling', 1, 'import'),
  ('clients', 'Sudtel', 1, 'import'),
  ('clients', 'Tecniprisma', 1, 'import'),
  ('clients', 'Telcabo', 1, 'import'),
  ('clients', 'Tnord', 1, 'import'),
  ('clients', 'Viatel', 1, 'import')
ON DUPLICATE KEY UPDATE is_active = 1, source = VALUES(source);

-- ---------------------------------------------------------------------
-- Projetos (17)
-- ---------------------------------------------------------------------
INSERT INTO list_items (type_code, value, is_active, source) VALUES
  ('projects', 'Built to Suit', 1, 'import'),
  ('projects', 'Cellnex', 1, 'import'),
  ('projects', 'Construção', 1, 'import'),
  ('projects', 'Desmantelamento', 1, 'import'),
  ('projects', 'FTTH', 1, 'import'),
  ('projects', 'Manutenção', 1, 'import'),
  ('projects', 'Mobilidade Elétrica', 1, 'import'),
  ('projects', 'PMA', 1, 'import'),
  ('projects', 'Reengenharia', 1, 'import'),
  ('projects', 'Reforço', 1, 'import'),
  ('projects', 'Replace', 1, 'import'),
  ('projects', 'Siresp', 1, 'import'),
  ('projects', 'Swap Ericsson', 1, 'import'),
  ('projects', 'Swap Huawei', 1, 'import'),
  ('projects', 'Swap Nokia', 1, 'import'),
  ('projects', 'Swap NOS', 1, 'import'),
  ('projects', 'Vantage', 1, 'import')
ON DUPLICATE KEY UPDATE is_active = 1, source = VALUES(source);

-- ---------------------------------------------------------------------
-- Gestores de projeto (10)
-- ---------------------------------------------------------------------
INSERT INTO list_items (type_code, value, is_active, source) VALUES
  ('managers', 'André Justino', 1, 'import'),
  ('managers', 'Augusto Silva', 1, 'import'),
  ('managers', 'Hugo Vieira', 1, 'import'),
  ('managers', 'Joaquim Silva', 1, 'import'),
  ('managers', 'Josué Carvalho', 1, 'import'),
  ('managers', 'Nuno Coisinha', 1, 'import'),
  ('managers', 'Ricardo Portugal', 1, 'import'),
  ('managers', 'Santos Jorge', 1, 'import'),
  ('managers', 'Sérgio Felisberto', 1, 'import'),
  ('managers', 'Sérgio Graça', 1, 'import')
ON DUPLICATE KEY UPDATE is_active = 1, source = VALUES(source);

-- ---------------------------------------------------------------------
-- FPS/PSS (2)
-- ---------------------------------------------------------------------
INSERT INTO list_items (type_code, value, is_active, source) VALUES
  ('fps', 'Não', 1, 'import'),
  ('fps', 'Sim', 1, 'import')
ON DUPLICATE KEY UPDATE is_active = 1, source = VALUES(source);

-- ---------------------------------------------------------------------
-- Supervisores (15)
-- ---------------------------------------------------------------------
INSERT INTO list_items (type_code, value, is_active, source) VALUES
  ('supervisors', 'André Justino', 1, 'import'),
  ('supervisors', 'Augusto Silva', 1, 'import'),
  ('supervisors', 'Darlindo Oliveira', 1, 'import'),
  ('supervisors', 'Hugo Vieira', 1, 'import'),
  ('supervisors', 'Joaquim Silva', 1, 'import'),
  ('supervisors', 'José Abrantes', 1, 'import'),
  ('supervisors', 'Josué Carvalho', 1, 'import'),
  ('supervisors', 'Luís Soares', 1, 'import'),
  ('supervisors', 'Nuno Coisinha', 1, 'import'),
  ('supervisors', 'Pedro Guilherme', 1, 'import'),
  ('supervisors', 'Ricardo Capela', 1, 'import'),
  ('supervisors', 'Ricardo Portugal', 1, 'import'),
  ('supervisors', 'Santos Jorge', 1, 'import'),
  ('supervisors', 'Sérgio Felisberto', 1, 'import'),
  ('supervisors', 'Sérgio Graça', 1, 'import')
ON DUPLICATE KEY UPDATE is_active = 1, source = VALUES(source);

-- ---------------------------------------------------------------------
-- Chefes de equipa Setronix (22)
-- ---------------------------------------------------------------------
INSERT INTO list_items (type_code, value, is_active, source) VALUES
  ('setLeaders', 'Álvaro Lopes', 1, 'import'),
  ('setLeaders', 'António José', 1, 'import'),
  ('setLeaders', 'António Oliveira', 1, 'import'),
  ('setLeaders', 'Carlos Guilherme', 1, 'import'),
  ('setLeaders', 'Carlos Ribeiro', 1, 'import'),
  ('setLeaders', 'Darlindo Oliveira', 1, 'import'),
  ('setLeaders', 'Hugo Veríssimo', 1, 'import'),
  ('setLeaders', 'Isaac Caniço', 1, 'import'),
  ('setLeaders', 'Ivo Marques', 1, 'import'),
  ('setLeaders', 'Nelson Barros', 1, 'import'),
  ('setLeaders', 'Nelson Silva', 1, 'import'),
  ('setLeaders', 'Paulo Lopes', 1, 'import'),
  ('setLeaders', 'Ruben Ribeiro', 1, 'import'),
  ('setLeaders', 'Tempo de secagem_1', 1, 'import'),
  ('setLeaders', 'Tempo de secagem_2', 1, 'import'),
  ('setLeaders', 'Tempo de secagem_3', 1, 'import'),
  ('setLeaders', 'Tempo de secagem_4', 1, 'import'),
  ('setLeaders', 'Tempo de secagem_5', 1, 'import'),
  ('setLeaders', 'Tempo de secagem_6', 1, 'import'),
  ('setLeaders', 'Tempo de secagem_7', 1, 'import'),
  ('setLeaders', 'Tempo de secagem_8', 1, 'import'),
  ('setLeaders', 'Vitor Garcia', 1, 'import')
ON DUPLICATE KEY UPDATE is_active = 1, source = VALUES(source);

-- ---------------------------------------------------------------------
-- Ajudantes Setronix (22)
-- ---------------------------------------------------------------------
INSERT INTO list_items (type_code, value, is_active, source) VALUES
  ('setHelpers', 'Afonso Silvestre', 1, 'import'),
  ('setHelpers', 'André Correia', 1, 'import'),
  ('setHelpers', 'António Ramos', 1, 'import'),
  ('setHelpers', 'Caio Carvalho', 1, 'import'),
  ('setHelpers', 'Daniel Almeida', 1, 'import'),
  ('setHelpers', 'Daniel Cristovão', 1, 'import'),
  ('setHelpers', 'Dário Simões', 1, 'import'),
  ('setHelpers', 'Eliezer Moura', 1, 'import'),
  ('setHelpers', 'João Carvalho', 1, 'import'),
  ('setHelpers', 'José Pina', 1, 'import'),
  ('setHelpers', 'José Pinheiro', 1, 'import'),
  ('setHelpers', 'Miguel Simões', 1, 'import'),
  ('setHelpers', 'Paulo Matias', 1, 'import'),
  ('setHelpers', 'Pedro Coelho', 1, 'import'),
  ('setHelpers', 'Rafael Filipe', 1, 'import'),
  ('setHelpers', 'Rodrigo Jesus', 1, 'import'),
  ('setHelpers', 'Ruben Ferreira', 1, 'import'),
  ('setHelpers', 'Ruben Peixe', 1, 'import'),
  ('setHelpers', 'Rui Capela', 1, 'import'),
  ('setHelpers', 'Simão Pelixo', 1, 'import'),
  ('setHelpers', 'Simão Sequeira', 1, 'import'),
  ('setHelpers', 'Simão Silva', 1, 'import')
ON DUPLICATE KEY UPDATE is_active = 1, source = VALUES(source);

-- ---------------------------------------------------------------------
-- Tarefas tipo (11)
-- ---------------------------------------------------------------------
INSERT INTO list_items (type_code, value, is_active, source) VALUES
  ('tasks', 'Abertura de maciço', 1, 'import'),
  ('tasks', 'Acabamentos', 1, 'import'),
  ('tasks', 'Betonagem laje', 1, 'import'),
  ('tasks', 'Betonagem maciço', 1, 'import'),
  ('tasks', 'Inspeção elétrica', 1, 'import'),
  ('tasks', 'Instalação torre', 1, 'import'),
  ('tasks', 'Pré-instalação', 1, 'import'),
  ('tasks', 'Secagem laje', 1, 'import'),
  ('tasks', 'Secagem maciço', 1, 'import'),
  ('tasks', 'Survey', 1, 'import'),
  ('tasks', 'Swap', 1, 'import')
ON DUPLICATE KEY UPDATE is_active = 1, source = VALUES(source);

-- ---------------------------------------------------------------------
-- Registo da carga inicial
-- ---------------------------------------------------------------------
INSERT INTO import_runs (filename, mode, rows_read, items_added, status, message)
VALUES ('LISTA DE SELEÇÃO.xlsx (carga inicial por SQL)', 'merge', 23, 122, 'ok',
        'Dados base carregados a partir de install/dados_base.sql');

INSERT INTO audit_log (username, action, entity, summary)
VALUES ('sistema', 'import', 'list_item', 'Carga inicial das listas base (122 valores)');

-- Conferência: deve devolver 122 valores distribuídos por 8 listas.
SELECT type_code AS lista, COUNT(*) AS valores
  FROM list_items WHERE is_active = 1
 GROUP BY type_code ORDER BY type_code;
