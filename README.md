# Plataforma Setronix

Casca em PHP + MySQL para publicar aplicações HTML feitas à medida.

A plataforma não tem lógica de negócio própria. Faz três coisas:

1. **Autentica** os utilizadores (palavra-passe + MFA, sessões, bloqueios).
2. **Gere as contas** — criar, editar, repor palavra-passe, repor MFA.
3. **Aloja aplicações**: um administrador envia um ficheiro `.html` e ele fica
   imediatamente disponível para os utilizadores autenticados.

Uma aplicação é um **único ficheiro HTML autónomo** — com o CSS e o JavaScript
lá dentro, como os que o ChatGPT gera. Não é preciso alterar nada na plataforma
para publicar, substituir ou remover uma aplicação.

---

## 1. Requisitos no cPanel

| Requisito | Notas |
|---|---|
| PHP 7.4 ou superior (recomendado 8.1+) | *Select PHP Version* |
| Extensões `pdo_mysql`, `mbstring`, `openssl` | obrigatórias |
| MySQL 5.7+ / MariaDB 10.3+ | via *MySQL® Databases* |
| Certificado SSL ativo | a aplicação força HTTPS |

Sem Composer e sem bibliotecas externas — funciona num alojamento partilhado normal.

## 2. Instalação

1. **Criar a base de dados** no cPanel → *MySQL® Databases*: uma base de dados,
   um utilizador, e `ALL PRIVILEGES` desse utilizador sobre essa base.
2. **Carregar os ficheiros** para `public_html/` (ou um subdiretório).
3. **Permissões:** `storage/` = 750 · `storage/apps/` = 750.
4. Abrir `https://<dominio>/install/index.php` e seguir os 4 passos:
   ambiente → base de dados → administrador inicial → concluir.
5. **Apagar a pasta `install/`** do servidor (o assistente lembra-o no fim).
6. Confirmar que `config.php` ficou com permissões 640.

### Atualizar uma instalação da versão anterior

A v1 tinha o módulo de planeamento de obras embutido. Para passar à v2:

1. Exportar a base de dados (cPanel → phpMyAdmin → *Exportar*).
2. Correr `install/schema.sql` — cria `apps` e `app_versions`.
3. Correr `install/migrar_v2.sql` — remove as tabelas que deixaram de ser usadas.

O `install/schema.sql` só cria o que ainda não existe, por isso pode ser
executado outra vez sempre que uma atualização acrescentar tabelas — foi o caso
da tabela `user_apps`, que guarda quem pode abrir cada aplicação. Se faltar
alguma, a plataforma diz qual é em vez de devolver um erro em branco.

As contas, o MFA e o log de alterações mantêm-se intactos.

### Cópias de segurança

A plataforma já não faz backups por si. Configure em cPanel → *Backup* a cópia
periódica da base de dados **e** da pasta `storage/apps/`, onde ficam os
ficheiros HTML.

## 3. Estrutura

```
index.php              Ecrã inicial — as aplicações disponíveis
app.php                Abre uma aplicação (barra fina + iframe)
app_raw.php            Serve o ficheiro HTML (exige sessão)
login.php              Passo 1 — utilizador + palavra-passe
mfa.php                Passo 2 — TOTP e códigos de recuperação
password.php           Alteração obrigatória da palavra-passe
perfil.php             A minha conta
logout.php             Terminar sessão

admin/index.php        Resumo da plataforma
admin/apps.php         Enviar, substituir, repor e remover aplicações
admin/users.php        Gestão de contas
admin/audit.php        Log de alterações

lib/bootstrap.php      Arranque: config, sessão, cabeçalhos de segurança
lib/db.php             PDO e helpers de query
lib/auth.php           Autenticação, MFA, permissões
lib/apps.php           Aplicações e versões
lib/totp.php           TOTP (RFC 6238) e códigos de recuperação
lib/qrcode.php         Gerador de códigos QR (para a ativação do MFA)
lib/audit.php          Log de alterações
lib/helpers.php        Utilitários (CSRF, cifra, escape, ...)
lib/layout.php         Cabeçalho, rodapé e estilos partilhados

assets/logo-setronix.png  Logótipo, servido pela própria plataforma

install/index.php      Assistente de instalação (apagar depois de instalar)
install/schema.sql     Esquema da base de dados
install/migrar_v2.sql  Migração a partir da versão anterior

storage/apps/          Ficheiros HTML das aplicações (fora do alcance da web)
```

## 4. Publicar uma aplicação

1. Peça ao ChatGPT (ou a quem a fizer) **um único ficheiro HTML**, com o CSS e o
   JavaScript incluídos. Se a página buscar ficheiros externos, esses ficheiros
   não são enviados.
2. **Administração → Aplicações → Nova aplicação**: dê um nome, uma descrição
   opcional e escolha o ficheiro.
3. A aplicação passa a aparecer no ecrã inicial de todos os utilizadores.

Para **substituir**, abra a aplicação em *Gerir* e envie o ficheiro novo: fica
como versão activa e a anterior continua guardada. Se a versão nova tiver um
problema, basta **Repor** a anterior.

Para **esconder sem apagar**, desmarque "Visível para os utilizadores".

### Quem vê cada aplicação

Por omissão, uma aplicação publicada aparece a **todos** os utilizadores.

Para a reservar a algumas pessoas, abra-a em *Gerir* e, em **Quem pode abrir**,
passe-as para a coluna da direita. A partir do momento em que houver alguém à
direita, só essas pessoas a veem — e quem tentar abrir o endereço direto recebe
um "não encontrada". Esvaziar a coluna volta a abri-la a todos.

A mesma atribuição pode ser feita pelo lado do utilizador, em
**Administração → Utilizadores → Editar**. Aí a coluna da direita — *Vê estas
aplicações* — é literalmente o que a pessoa encontra ao entrar, incluindo as
aplicações abertas a toda a gente, que aparecem fixas e não se retiram ali.

> **A confusão mais comum:** dar acesso a uma aplicação reservada não retira as
> outras. Se a pessoa continua a ver uma aplicação que não lhe atribuiu, é porque
> essa aplicação não tem ninguém atribuído e continua aberta a todos. Reserve-a
> também, escolhendo quem a pode ver.

Quem tem o perfil *Administrador* ou *Gestor de aplicações* vê sempre todas as
aplicações — de outra forma seria possível ficar sem acesso ao que se publicou.

Limite por ficheiro: 8 MB (configurável em `config.php`, chave `apps.max_mb`;
o servidor também tem de aceitar o upload — ver `upload_max_filesize`).

## 5. Nome e logótipo

O nome que aparece no cabeçalho, no ecrã de entrada e no título do separador
altera-se em **Administração → Resumo → Identificação**. Fica guardado na base
de dados, não no `config.php` — não é preciso mexer em ficheiros no servidor.

O logótipo é `assets/logo-setronix.png`, servido pela própria plataforma e não
pelo site institucional: assim o painel não fica dependente de um pedido
externo para se apresentar. Para o trocar, substitua o ficheiro mantendo o nome.
Sobre a barra escura é apresentado numa placa branca, porque o texto do logótipo
é vermelho e não teria contraste suficiente sobre o azul-escuro.

## 6. Segurança: MFA e palavras-passe

Em **Administração → Resumo → Segurança**:

| Definição | O que faz |
|---|---|
| Exigir MFA a todos | Ligado, ninguém entra sem associar uma aplicação autenticadora. Desligado, cada pessoa decide em *A minha conta*, e o administrador pode exigi-lo conta a conta na ficha do utilizador. |
| Comprimento mínimo | Caracteres exigidos numa palavra-passe nova. Além do comprimento, é sempre exigida pelo menos uma letra e um algarismo. |

Desligar a exigência **não retira o MFA a ninguém**: quem já tem um dispositivo
associado continua a ter de introduzir o código. Também limpa a marca individual
de todas as contas — de outra forma o interruptor não teria efeito, porque as
contas são criadas com essa marca ligada. Com a exigência desligada, aparece na
ficha de cada utilizador uma caixa **Exigir MFA a esta conta**, para o impor a
quem precisa (por exemplo, aos administradores).

> Duas notas honestas, para ficarem escritas: uma palavra-passe de 6 caracteres
> quebra-se em minutos se a base de dados vazar, e é precisamente o MFA que
> mantém a conta fechada quando isso acontece. Ambas as definições existem para
> serem apertadas mais tarde sem alterar código.

## 7. A ficha do utilizador

Em **Administração → Utilizadores**, a tabela serve para procurar e ler — não tem
botões. Escolha uma linha e a ficha abre por baixo, com as ações agrupadas:
verificação em duas etapas, palavra-passe, sessões e conta.

Sobre o MFA, convém saber o que cada um pode fazer:

| | |
|---|---|
| **Associar o dispositivo** | Só a própria pessoa, em *A minha conta*. Exige ler o código QR no telemóvel dela. |
| **Exigir** | O administrador, na ficha. Obriga aquela conta a associar antes de entrar. |
| **Repor** | O administrador, na ficha. Apaga o dispositivo — para quando alguém troca ou perde o telemóvel. Só aparece se houver dispositivo associado. |

## 8. Perfis e permissões

| Perfil | Pode |
|---|---|
| **Administrador** | tudo: contas, aplicações, acessos, log de alterações |
| **Gestor de aplicações** | abrir aplicações; enviar, substituir e remover aplicações |
| **Utilizador** | abrir as aplicações publicadas |
| **Consulta** | abrir as aplicações publicadas |

## 9. Onde ficam os dados das aplicações

A plataforma guarda o **ficheiro** da aplicação; **não** guarda os dados que a
aplicação produz. Uma página HTML autónoma normalmente usa o `localStorage` do
browser — isso significa que os dados ficam no computador de cada utilizador,
não são partilhados entre pessoas e desaparecem se o browser for limpo.

Para que os dados sejam centrais e partilhados, a aplicação tem de falar com um
servidor. Isso é trabalho adicional, fora do âmbito desta casca.

## 10. Nota sobre o HTML enviado

O ficheiro HTML enviado corre na mesma origem que a plataforma, com a sessão do
utilizador iniciada. Isso é o que permite que o `localStorage` da aplicação
funcione e persista, mas também significa que **o código enviado é confiado**:
pode fazer pedidos autenticados à própria plataforma.

Por isso o envio está limitado a administradores e a gestores de aplicações.
Envie apenas ficheiros de origem conhecida — não publique HTML que não tenha sido
pedido por si.
