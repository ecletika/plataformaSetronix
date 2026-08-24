# Plataforma Setronix — Planeamento Operacional de Obras

Aplicação PHP + MySQL para o planeamento semanal de obras e alocação de equipas.
Substitui o armazenamento em `localStorage` do protótipo por uma base de dados
central, com contas de utilizador, MFA, log de alterações e backups.

**Ponto zero (esta entrega):** base de dados, autenticação com MFA, administração
de utilizadores, gestão e importação das listas base, log de alterações, backups,
e o protótipo de planeamento servido já autenticado.

---

## 1. Requisitos no cPanel

| Requisito | Notas |
|---|---|
| PHP 7.4 ou superior (recomendado 8.1+) | *Select PHP Version* |
| Extensões `pdo_mysql`, `mbstring`, `openssl` | obrigatórias |
| Extensão `zip` | leitura de `.xlsx`; sem ela, importar em `.csv` |
| Extensão `zlib` | comprime os backups (opcional) |
| MySQL 5.7+ / MariaDB 10.3+ | via *MySQL® Databases* |
| Certificado SSL ativo | a aplicação força HTTPS |

Sem Composer e sem bibliotecas externas — tudo funciona num alojamento partilhado normal.

## 2. Instalação

1. **Criar a base de dados** no cPanel → *MySQL® Databases*: uma base de dados,
   um utilizador, e `ALL PRIVILEGES` desse utilizador sobre essa base.
2. **Carregar os ficheiros** para `public_html/` (ou um subdiretório).
3. **Permissões:** `storage/` = 750 · `storage/backups/` = 750 · `storage/uploads/` = 750.
4. Abrir `https://<dominio>/install/index.php` e seguir os 5 passos:
   ambiente → base de dados → administrador inicial → listas base → concluir.
5. **Apagar a pasta `install/`** do servidor (o assistente lembra-o no fim).
   Antes disso, mova `install/cron_backup.php` para fora de `public_html`.
6. Confirmar que `config.php` ficou com permissões 640.

### Backup automático

cPanel → *Cron Jobs*, uma vez por dia:

```bash
/usr/local/bin/php /home/UTILIZADOR/bin/cron_backup.php
```

## 3. Estrutura

```
index.php              Aplicação de planeamento (protótipo servido autenticado)
login.php              Passo 1 — utilizador + palavra-passe
mfa.php                Passo 2 — inscrição e verificação TOTP
password.php           Alteração de palavra-passe
perfil.php             "A minha conta": dados, MFA, códigos de recuperação, sessões
logout.php

admin/index.php        Resumo do estado da plataforma
admin/users.php        Criar/editar utilizadores, repor palavra-passe e MFA
admin/lists.php        Gestão manual das listas base
admin/import.php       Importação de .xlsx / .csv
admin/audit.php        Log de alterações + tentativas de autenticação
admin/backup.php       Criar, descarregar, carregar e restaurar backups

api/lists.php          Listas base em JSON (sessão obrigatória)

lib/bootstrap.php      Arranque: config, sessão, cabeçalhos de segurança
lib/db.php             PDO + helpers de query
lib/auth.php           Autenticação, permissões, MFA, sessões
lib/totp.php           TOTP (RFC 6238) e Base32 em PHP puro
lib/xlsx.php           Leitor .xlsx / .csv em PHP puro
lib/lists.php          Listas base e lógica de importação
lib/backup.php         Dump e restauro da base de dados
lib/audit.php          Registo de auditoria
lib/layout.php         Estilos e navegação partilhados

install/schema.sql     Esquema completo da base de dados
install/index.php      Assistente de instalação
install/cron_backup.php Backup agendado (CLI)

legacy/                Protótipo HTML original + ficheiro Excel de origem
storage/               Backups e uploads (bloqueado ao acesso web)
```

## 4. Perfis e permissões

| Perfil | Pode |
|---|---|
| **Administrador** | tudo: utilizadores, listas, importações, log, backups |
| **Gestor de projeto** | obras, planeamentos, listas base, importações |
| **Supervisor** | planeamentos semanais |
| **Consulta** | apenas leitura |

Todos os perfis passam obrigatoriamente por MFA.

## 5. Segurança implementada

- Palavras-passe com `password_hash()` (bcrypt/Argon2 conforme o PHP do servidor).
- MFA TOTP obrigatório, com segredo cifrado em AES-256-GCM pela `app_key`.
- 10 códigos de recuperação de uso único, guardados apenas em hash.
- Bloqueio da conta após 5 falhas; limite de tentativas por IP.
- Sessões com regeneração de ID, expiração por inatividade e duração máxima.
- Token CSRF em todos os formulários.
- Cabeçalhos `X-Frame-Options`, `X-Content-Type-Options`, HSTS, `Referrer-Policy`.
- Log de auditoria com estado anterior/posterior em JSON.
- `lib/`, `legacy/` e `storage/` bloqueados por `.htaccess`.

> **Se a `app_key` do `config.php` se perder, todos os utilizadores terão de
> voltar a inscrever o MFA.** Guarde-a junto das credenciais da base de dados.

## 6. Formato do ficheiro de listas base

Primeira linha = cabeçalhos; cada coluna é uma lista independente lida de cima
para baixo. Células vazias são ignoradas, pelo que as colunas podem ter
comprimentos diferentes — exatamente como o `LISTA DE SELEÇÃO.xlsx` original.

| Cabeçalho | Lista |
|---|---|
| `CLIENTE` | Clientes |
| `PROJETO` | Projetos |
| `GESTOR DE PROJETO` | Gestores de projeto |
| `FPS/PSS` | FPS/PSS |
| `SUPERVISOR` | Supervisores |
| `CHEFE DE EQUIPA SETRONIX` | Chefes de equipa |
| `AJUDANTE SETRONIX 1` / `2` / `3` | Ajudantes (agregados sem duplicados) |
| `TAREFAS TIPO` | Tarefas tipo |

Modos: **Acrescentar** (mantém tudo o que já existe) ou **Substituir**
(desativa o que não constar do ficheiro). Nada é apagado — os valores são
desativados, para que o histórico continue legível.

## 7. Próxima fase

As tabelas `works`, `plans` e `plan_days` já estão criadas e espelham o modelo
de dados do protótipo. Falta ligar o front-end à API:

1. `api/works.php` e `api/plans.php` (GET/POST/DELETE em JSON).
2. Substituir as leituras/escritas em `localStorage` por chamadas à API.
3. Bloqueio otimista (`updated_at`) para edições simultâneas.
4. Log de alterações por obra/semana já suportado pela tabela `audit_log`.
5. Importação de férias e baixas a partir da plataforma de folhas de ponto.
