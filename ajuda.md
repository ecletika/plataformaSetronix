# Manual da Plataforma Setronix

## O que é a plataforma

A Plataforma Setronix é uma casca de autenticação e gestão de contas construída em PHP e MySQL. Não contém lógica de negócio própria. Faz três coisas: autentica os utilizadores com palavra-passe e verificação em duas etapas (MFA), gere as contas de utilizador, e aloja aplicações.

Uma aplicação na Setronix é um único ficheiro HTML autónomo — com o CSS e o JavaScript incluídos dentro do ficheiro. Um administrador envia o ficheiro através de um formulário, e a aplicação passa a estar disponível imediatamente para os utilizadores autenticados. Não é preciso alterar nada no código da plataforma para publicar, substituir ou remover uma aplicação.

## Perfis e o que cada um pode fazer

### Administrador
O Administrador tem acesso a tudo:
- Vê o separador **Administração** com todos os separadores: Resumo, Aplicações, Utilizadores, Log de alterações.
- Pode ver e abrir qualquer aplicação publicada.
- Pode enviar, substituir, repor versões anteriores e apagar aplicações HTML.
- Pode criar, editar, desativar e reativar contas de utilizador.
- Pode repor a palavra-passe de qualquer utilizador, e exigir ou repor o MFA de uma conta. **Não pode ativar o MFA de outra pessoa** — isso exige ler o código QR no telemóvel dela.
- Pode consultar o log completo de alterações na plataforma.
- Pode alterar as definições de segurança: exigir MFA a todos e comprimento mínimo da palavra-passe.
- Pode alterar o nome da plataforma que aparece no cabeçalho e no ecrã de entrada.

### Gestor de Aplicações
O Gestor de Aplicações gere o repositório de ficheiros:
- Vê o separador **Administração** apenas com o separador Aplicações.
- Pode ver e abrir qualquer aplicação publicada.
- Pode enviar, substituir, repor versões anteriores e apagar aplicações HTML.
- Não pode criar ou alterar contas, nem ver o log de alterações.

### Utilizador
O Utilizador tem acesso de leitura:
- Vê o separador **Aplicações** com a lista de aplicações a que tem acesso.
- Pode abrir qualquer aplicação atribuída a si ou que esteja aberta a todos.
- Pode editar os seus dados (nome, e-mail) em **A minha conta**.
- Pode ativar e gerir a sua verificação em duas etapas.
- Pode alterar a sua palavra-passe.

### Consulta
O Consulta tem acesso de leitura idêntico ao Utilizador:
- Vê o separador **Aplicações** com a lista de aplicações a que tem acesso.
- Pode abrir qualquer aplicação atribuída a si ou que esteja aberta a todos.
- Pode editar os seus dados em **A minha conta**.
- Pode ativar e gerir a sua verificação em duas etapas.
- Pode alterar a sua palavra-passe.

## Entrar na plataforma

### Passo 1 — Palavra-passe

O utilizador abre o ecrã de entrada e introduz o seu nome de utilizador ou endereço de e-mail e a palavra-passe. Se a palavra-passe estiver correta, passa ao passo seguinte.

Se a palavra-passe estiver errada, a plataforma rejeita a entrada. Após 5 tentativas falhadas (configurável), a conta fica bloqueada durante 15 minutos. Após 25 tentativas falhadas a partir do mesmo endereço IP nos últimos 15 minutos, esse IP fica bloqueado para novas tentativas durante 15 minutos.

### Passo 2 — Verificação em duas etapas (MFA)

Depois da palavra-passe aceite, o utilizador vê o ecrã de verificação em duas etapas. O comportamento aqui depende se o MFA já está ativado:

**Se o MFA não está ativado:**
- Se o MFA é exigido (globalmente ou conta a conta), o utilizador tem de associar um dispositivo autenticador (Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden, etc.). Lê um código QR com o telemóvel e confirma com os 6 dígitos apresentados pela aplicação.
- A plataforma gera 10 códigos de recuperação, que o utilizador tem de guardar num local seguro (gestor de palavras-passe ou papel).
- Se o MFA é opcional, o utilizador pode clicar "Agora não — entrar sem ativar" para continuar sem associar um dispositivo. Pode ativar depois em **A minha conta**.

**Se o MFA já está ativado:**
- O utilizador introduz os 6 dígitos apresentados na aplicação autenticadora.
- Se não tem o telemóvel, introduz um código de recuperação no formato XXXX-XXXX. Cada código só funciona uma vez.

### Alteração obrigatória da palavra-passe (primeira entrada)

Quando um utilizador é criado por um administrador, o seu registo é marcado com "deve alterar a palavra-passe no próximo acesso". Após passar MFA, é redirecionado para o ecrã de alteração de palavra-passe. Tem de introduzir a palavra-passe atual (que o administrador forneceu), e definir uma nova. A nova palavra-passe tem de ter o comprimento mínimo configurado, incluir pelo menos uma letra e um algarismo, e não começar por uma sequência óbvia (12345, password, setronix, etc.).

### Bloqueio por tentativas falhadas

Uma conta é bloqueada temporariamente se:
- O utilizador falha 5 tentativas de palavra-passe (configurável como "máximo de tentativas falhadas"). O bloqueio dura 15 minutos (configurável como "minutos de bloqueio").
- Um administrador pode desbloquear a conta manualmente em **Administração → Utilizadores → [ficha do utilizador] → Desbloquear conta**.

Um IP é bloqueado temporariamente se:
- Fizer 25 tentativas de autenticação falhadas nos últimos 15 minutos.
- O bloqueio é automático e dura 15 minutos.

## A aplicação principal

Ao iniciar sessão, a plataforma abre diretamente a **aplicação predefinida** de cada pessoa. É esse o ecrã principal: a aplicação ocupa o ecrã todo, com a barra de topo por cima. Não há botão para voltar — os menus da barra continuam sempre disponíveis e é por eles que se muda de aplicação ou se vai à administração.

O nome que aparece ao lado do logótipo é o da aplicação aberta. **Carregar no logótipo ou nesse nome volta ao ecrã principal**, de qualquer página da plataforma.

### Como se escolhe a predefinida

Quando um administrador atribui uma aplicação a alguém que ainda não tinha nenhuma escolhida, essa passa a ser a predefinida — a pessoa entra e a aplicação abre, sem ter de configurar nada.

Quem já tinha escolhido a sua mantém-na: uma atribuição nova não lhe muda o hábito.

Para mudar, abra o menu **Aplicações** na barra de topo e carregue na **estrela** ao lado da aplicação que quer. A estrela fica amarela e a aplicação passa a abrir sozinha. Carregar outra vez na mesma estrela desliga a abertura automática, e passa a entrar na lista.

### Mudar de aplicação

O botão **Aplicações**, na barra de topo, só aparece a quem tem mais do que uma — com uma só não haveria nada para escolher.

Carregue nele e abre um menu com todas as suas aplicações. Carregar no nome abre a aplicação; carregar na **estrela** à direita marca-a como predefinida, sem sair da página onde está.

### A lista completa

Chega-se à lista por **A minha conta → As minhas aplicações**. Cada linha tem o nome da aplicação, a versão, e dois botões à direita:

| Botão | O que faz |
|---|---|
| Estrela | Marca a aplicação como predefinida — passa a abrir ao entrar |
| Caixote | Retira a aplicação da sua lista |

O caixote **não lhe tira o acesso**: é apenas arrumação, para quem tem aplicações que não usa. A aplicação desaparece da lista e do menu, mas se retirar a que estava marcada como predefinida, deixa de haver abertura automática e passa a entrar na lista.

Se se enganar, ou mudar de ideias, **peça a um administrador para a repor** — ele faz isso na sua ficha, em Administração → Utilizadores.

## Aparência

Em **A minha conta → Aparência** escolhe a cor da barra de topo — oito cores predefinidas ou qualquer outra. Só muda a barra; o resto da plataforma fica igual, e a escolha não altera o que os outros veem.

A cor do texto da barra é decidida automaticamente: passa a preto sobre cores claras e a branco sobre cores escuras, para a barra nunca ficar ilegível.

## Administração

O menu **Administração** está disponível para Administradores e Gestores de Aplicações, com diferentes separadores conforme o perfil.

### Resumo (apenas Administrador)

Este separador mostra o estado geral da plataforma e permite ajustar duas definições críticas.

**Identificação:**
- Campo de texto: **Nome da plataforma** — o nome que aparece no cabeçalho, no ecrã de entrada e no título do separador. Máximo 120 caracteres. Botão **Guardar**.

**Segurança:**
- Caixa de seleção: **Exigir verificação em duas etapas a todos** — quando ativada, todos os utilizadores têm de passar por MFA antes de entrar. Quando desativada, cada utilizador decide se a ativa em **A minha conta**. Desligar isto **não retira o MFA a ninguém** que já o tenha ativado.
- Campo numérico: **Comprimento mínimo da palavra-passe** — número entre 4 e 64. Aplica-se apenas a palavras-passe criadas ou alteradas a partir de agora. A padrão é 6.
- Botão **Guardar**.

**Estado da plataforma:**
- Mostra três números: utilizadores ativos (de quantos no total), aplicações visíveis (de quantas no total), versões guardadas.

**Alertas:**
- Se houver utilizadores que ainda não ativaram MFA, aparece um aviso.
- Se houve mais de 20 tentativas de autenticação falhadas nas últimas 24 horas, aparece um aviso.

**Última publicação:**
- Tabela com nome da aplicação, número de versão, tamanho do ficheiro e data da última versão enviada. Se nenhuma aplicação foi publicada, mostra "—".

**Últimas alterações:**
- Tabela com as 12 alterações mais recentes: data, utilizador que fez a alteração, tipo de ação, descrição. Um botão **Ver log completo** leva ao separador **Log de alterações**.

### Aplicações

Este separador é acedido por Administradores e Gestores de Aplicações.

**Tabela de aplicações publicadas:**
Coluna por coluna:
- **Nome** — nome da aplicação. Se tem descrição, aparece em letra pequena abaixo.
- **Versão** — número da versão ativa. Se não há ficheiro, mostra "—".
- **Tamanho** — tamanho em bytes da versão ativa (ex.: "234 KB"). Se não há ficheiro, mostra "—".
- **Atualizada** — data e hora da última versão.
- **Estado** — etiqueta verde "ativa" ou cinzenta "oculta" (consoante o campo "Visível para os utilizadores"). Se tem utilizadores atribuídos, aparece uma segunda etiqueta com o número (ex.: "3 utilizadores"). Se está aberta a todos, aparece etiqueta "todos".
- **Botões:** "Abrir" (abre a aplicação num novo separador do browser) e "Gerir" (carrega os formulários de detalhes desta aplicação).

**Carregamento de ficheiros:**

Para uma aplicação já existente (clicada em "Gerir"):

1. **Enviar nova versão** — formulário com:
   - Campo obrigatório: **Ficheiro HTML** (aceita .html ou .htm). Máximo 8 MB (configurável em config.php).
   - Campo opcional: **Nota** (máximo 255 caracteres, ex.: "corrige bug da listagem").
   - Botão **Publicar esta versão**. A versão anterior fica guardada e pode ser reposta.

2. **Versões** — tabela com todas as versões da aplicação:
   - **#** — número de versão. A versão ativa tem etiqueta "activa".
   - **Ficheiro** — nome do ficheiro original enviado.
   - **Tamanho** — tamanho em bytes.
   - **Data** — data e hora do envio.
   - **Nota** — descrição introduzida no envio.
   - **Botões:**
     - "Pré-ver" — abre a versão num novo separador.
     - "Repor" (apenas se não é a versão ativa) — marca esta versão como ativa. Todos os utilizadores passam a ver esta.
     - "Apagar" (apenas se não é a versão ativa) — remove a versão do servidor. Aparece uma confirmação.

3. **Quem pode abrir** — lista de transferência (duas colunas):
   - **Coluna esquerda:** utilizadores que não têm acesso.
   - **Coluna direita:** utilizadores que podem abrir esta aplicação.
   - Com a coluna direita vazia, a aplicação está aberta a **todos** os utilizadores.
   - As aplicações que estão abertas a todos (porque ninguém lhes foi atribuído) aparecem fixas na coluna direita com a nota "aberta a todos" — não se retiram ali. Para as reservar, é preciso atribuir pessoas.
   - Botão **Guardar acesso**.

4. **Dados da aplicação** — formulário de edição:
   - **Nome** (obrigatório, máximo 160 caracteres).
   - **Descrição** (opcional, máximo 500 caracteres).
   - Caixa de seleção: **Visível para os utilizadores** — quando marcada, a aplicação aparece no ecrã inicial. Quando desmarcada, fica oculta mas não é apagada.
   - Botão **Guardar**.

5. **Apagar** — formulário destrutivo:
   - Texto: "Apaga a aplicação e todas as versões do servidor."
   - Campo de confirmação: **Escreva [nome da aplicação] para confirmar**.
   - Botão **Apagar definitivamente**. Após confirmação, a aplicação e todas as versões são removidas permanentemente.

**Nova aplicação:**
Formulário para criar uma aplicação:
- **Nome** (obrigatório, máximo 160 caracteres).
- **Descrição** (opcional, máximo 500 caracteres).
- **Ficheiro HTML** (obrigatório, .html ou .htm, máximo 8 MB).
- Botão **Publicar**.

### Utilizadores

Este separador é apenas para Administrador.

**Tabela de utilizadores:**
Coluna por coluna:
- **Utilizador** — nome de utilizador e e-mail abaixo em letra pequena.
- **Nome** — nome completo.
- **Perfil** — etiqueta com o perfil (Administrador, Gestor de aplicações, Utilizador, Consulta).
- **Estado** — etiqueta "Ativo" ou "Inativo". Se a conta está bloqueada por tentativas, aparece uma segunda etiqueta "Bloqueado".
- **MFA** — etiqueta "Associado" ou "Por associar". Se o MFA é exigido, aparece em letra pequena "exigido".
- **Último acesso** — data e hora do último login (ex.: "2024-06-15 14:32").

Ao clicar numa linha, a ficha da pessoa abre por baixo com os formulários de ação.

**Criar utilizador:**
Formulário no topo:
- **Nome de utilizador** (obrigatório) — 3 a 64 caracteres, letras minúsculas, números, ponto, hífen ou underscore.
- **E-mail** (obrigatório) — validado como e-mail.
- **Nome completo** (obrigatório).
- **Perfil** (obrigatório) — dropdown com os quatro perfis.
- **Palavra-passe inicial** (opcional) — se deixado vazio, gera-se uma automaticamente. Se indicada, tem de respeitar as regras de robustez (comprimento mínimo, letra, algarismo, sem sequências óbvias).
- Caixa de seleção: **Conta ativa**.
- Caixa de seleção: **Exigir MFA a esta conta** (apenas se o MFA não é exigido globalmente).
- **Acesso às aplicações** — lista de transferência para escolher que aplicações a pessoa vê. As aplicações abertas a todos aparecem fixas na coluna direita com "aberta a todos".
- Botão **Criar utilizador**. Após sucesso, aparece a palavra-passe gerada numa caixa destacada, com a instrução "Anote agora — não voltará a ser mostrada".

**Editar utilizador:**
Clicando "Editar dados" na ficha, reabre o formulário de criar com os dados atuais. Permite alterar tudo exceto a palavra-passe (há um botão específico para isso).

**Ficha do utilizador (ações por contexto):**

1. **Verificação em duas etapas:**
   - Se MFA não está ativado: mostra "Por associar" e explica que o utilizador tem de ativar em **A minha conta**.
   - Se MFA está ativado: mostra "Associado" com data, e o número de códigos de recuperação por usar.
   - Comutador (apenas se MFA não é exigido globalmente): **Exigir MFA a esta conta** / **opcional para esta conta**. Ao ativar, obriga a pessoa a passar por MFA no próximo login.
   - Botão (apenas se MFA está ativado): **Repor MFA (novo dispositivo)** — remove o dispositivo associado, forçando a pessoa a associar um novo no próximo login.

2. **Palavra-passe:**
   - Botão **Repor palavra-passe** — gera uma nova, mostra-a uma única vez e marca a conta para forçar alteração no próximo login.
   - Botão **Desbloquear conta** (apenas se a conta está bloqueada) — limpa as tentativas falhadas e o tempo de bloqueio.

3. **Sessões:**
   - Mostra quantas sessões estão abertas nas últimas 12 horas.
   - Botão **Terminar sessões** — fecha a pessoa em todos os equipamentos onde tem login.

4. **Conta:**
   - Botão **Editar dados** — volta ao formulário de criar/editar.
   - Botão **Desativar conta** (apenas se não é o próprio utilizador) — desativa a conta e termina todas as sessões. Se é uma conta administrador, só deixa desativar se há outro administrador ativo.
   - Botão **Reativar conta** (apenas se a conta está inativa) — torna a conta ativa novamente.

**Nota importante:** As contas não são apagadas — são desativadas, de forma a que o log de alterações continue coerente.

### Log de alterações

Este separador é apenas para Administrador.

**Filtros:**
- **Utilizador** — dropdown com nomes de utilizadores que fizeram alterações.
- **Ação** — dropdown com tipos de ação (create, update, delete, login, logout, password_change, mfa_enroll, mfa_reset, etc.).
- **Entidade** — dropdown com tipos de entidade afetada (user, app, system).
- **De** — data de início (formato YYYY-MM-DD).
- **Até** — data de fim (formato YYYY-MM-DD).
- Botões: **Filtrar**, **Limpar**, **Exportar CSV**.

**Tabela do log:**
Coluna por coluna:
- **Data** — data e hora completa da alteração.
- **Utilizador** — nome de utilizador de quem fez a alteração.
- **Ação** — tipo de ação (ex.: create, update, delete).
- **Entidade** — o que foi afetado (ex.: user, app) e o seu ID (ex.: "user #5").
- **Descrição** — resumo legível (ex.: "Utilizador criado: jsilva").
- **IP** — endereço IP de origem.
- **Detalhe** — se existem dados antes/depois da alteração, aparece um botão "ver" que mostra o JSON com as mudanças.

**Paginação:**
- Mostra quantos registos existem, página atual e total de páginas.
- Botões de navegação: "← Anterior" e "Seguinte →".

**Últimas tentativas de autenticação falhadas:**
Abaixo da tabela do log, aparece uma tabela com as 25 últimas tentativas de autenticação falhadas:
- **Data** — quando ocorreu.
- **Utilizador indicado** — nome de utilizador introduzido (pode ser inválido se alguém adivinhou).
- **Fase** — em que passo falhou (password, mfa, recovery).
- **Motivo** — razão (ex.: bad_password, unknown_user, ip_rate_limited).
- **IP** — endereço de origem.

## A minha conta

Cada utilizador autenticado pode aceder a **A minha conta** através do menu.

**Dados pessoais:**
- **Nome completo** (editável).
- **E-mail** (editável, tem de ser único).
- Botão **Guardar alterações**.
- Link **Alterar palavra-passe** — leva a um formulário específico onde introduz a palavra-passe atual, a nova e a confirmação.
- Mostra o **Último início de sessão** (data, hora e IP).

**Verificação em duas etapas:**

Se não tem MFA ativado:
- Explicação: "A conta está protegida apenas pela palavra-passe. Com a verificação em duas etapas, quem souber a sua palavra-passe continua sem conseguir entrar."
- Botão **Ativar agora** — leva ao ecrã de ativação (lê código QR, confirma com 6 dígitos, guarda códigos de recuperação).

Se tem MFA ativado:
- Mostra "Ativa" com a data de ativação.
- Mostra o número de códigos de recuperação por usar. Se restam 2 ou menos, aparece um aviso.
- Três blocos de ação:

  1. **Gerar novos códigos** — pede a palavra-passe para confirmar, gera 10 novos códigos (os antigos deixam de funcionar imediatamente).
  
  2. **Trocar de telemóvel** — pede a palavra-passe para confirmar, remove o dispositivo associado e termina a sessão. Na volta, tem de associar o novo dispositivo.
  
  Ambos os blocos têm um campo de confirmação de palavra-passe.

**Sessões ativas:**
Tabela com as sessões abertas nos últimos 12 horas:
- **Início** — quando a sessão começou.
- **Última atividade** — quando foi acedida pela última vez.
- **IP** — endereço IP.
- **Dispositivo** — primeiros 70 caracteres do user agent (tipo de browser e SO).

## Regras que causam dúvidas

### Visibilidade das aplicações e atribuição de utilizadores

Uma aplicação publicada passa a estar disponível, por omissão, a **todos os utilizadores autenticados**.

Quando um administrador atribui a aplicação a pessoas específicas (clicando em "Gerir aplicação" → "Quem pode abrir"), ela deixa de estar aberta a todos e passa a ser visível **apenas para as pessoas atribuídas**.

Se depois o administrador esvazia essa lista (move toda a gente para a coluna esquerda), a aplicação volta a estar **aberta a todos**.

Corolário: uma aplicação sem ninguém atribuído é visível a toda a gente. Assim que há alguém atribuído, passa a ser só desses.

### MFA e o papel do administrador

Um utilizador pode ativar o seu próprio MFA em **A minha conta** — basta ler o código QR com o telemóvel.

Um administrador **não consegue ativar o MFA de outra pessoa** (porque exige ler o código QR no telemóvel dela, o que é físico). O que um administrador **pode** fazer:
- **Exigir** MFA a uma conta: obriga a pessoa a passar por MFA no próximo login (em **A minha conta**, se for voluntário, ou no ecrã de entrada, se for obrigatório globalmente).
- **Repor** MFA de uma conta: remove o dispositivo associado, forçando a pessoa a associar um novo no próximo login.

### Retirar uma aplicação não é perder o acesso

Quando alguém carrega no caixote de uma aplicação, ela sai da lista **dessa pessoa** e mais nada: a aplicação continua publicada, os outros continuam a vê-la, e a pessoa continua a ter acesso.

Um administrador repõe-na em **Administração → Utilizadores**, escolhendo a linha da pessoa e usando a caixa **Aplicações retiradas** na ficha. Aparece lá a lista do que ela retirou, com um botão **Repor** em cada uma e um **Repor todas** quando há mais do que uma.

### As contas não se apagam

Quando um administrador desativa uma conta em **Administração → Utilizadores**, a conta não é apagada da base de dados — é apenas marcada como inativa. O utilizador deixa de conseguir entrar, e todas as sessões são terminadas. Mas o registo continua lá para que o log de alterações permaneça coerente e auditável.

Para reativar, o administrador pode clicar no botão **Reativar conta** na mesma ficha.

## Perguntas frequentes

### Como atribuo uma aplicação a um utilizador?

Existem dois caminhos:
1. Em **Administração → Aplicações**, clique em "Gerir" na aplicação, vá a **Quem pode abrir** e passe o utilizador para a coluna da direita.
2. Em **Administração → Utilizadores**, clique na linha do utilizador, clique em **Editar dados**, vá a **Acesso às aplicações** e passe a aplicação para a coluna da direita.

Ambas as formas chegam ao mesmo resultado.

### Um utilizador diz que não vê uma aplicação que devia ver. O que faço?

Verifique:
1. A aplicação está **Visível para os utilizadores** (em **Administração → Aplicações**, com o campo desmarcado fica oculta).
2. Se a aplicação está **aberta a todos**, o utilizador deve ver. Se está **reservada a pessoas específicas**, o seu nome tem de estar na lista **Quem pode abrir**.
3. Se a aplicação está reservada a outras pessoas e o utilizador continua a ver, é porque a aplicação ainda está **aberta a todos** (ninguém lhe foi atribuído ainda). Atribua-a às pessoas que devem vê-la.

### Perdi o acesso ao MFA (perdi o telemóvel). Como entro?

Se tem códigos de recuperação, introduza-os no ecrã de verificação em duas etapas (formato XXXX-XXXX). Cada código só funciona uma vez.

Se não tem códigos ou já os usou todos, contacte um administrador. O administrador vai a **Administração → Utilizadores**, clica na sua ficha, e no bloco **Verificação em duas etapas**, clica em **Repor MFA (novo dispositivo)**.

### Como gero novos códigos de recuperação se os antigos acabaram?

Em **A minha conta**, vá a **Verificação em duas etapas** (se tem MFA ativado), clique em **Gerar novos códigos** e confirme a palavra-passe. Os códigos antigos deixam de funcionar imediatamente, e os novos ficam guardados.

### Qual é a palavra-passe mínima?

O administrador define o comprimento em **Administração → Resumo → Segurança → Comprimento mínimo da palavra-passe**. A padrão é 6 caracteres. Além disso, a palavra-passe tem sempre de incluir pelo menos uma letra e um algarismo.

### Quanto tempo dura uma sessão?

A plataforma encerra a sessão se o utilizador fica inativo durante 120 minutos (2 horas), ou após 12 horas de duração absoluta — o que ocorrer primeiro. Ao tentar aceder, é redirecionado para o login.

### Posso enviar uma aplicação com ficheiros externos (CSS, JS, imagens)?

Não. Envie um **único ficheiro HTML** com tudo lá dentro. Se a página buscar ficheiros externos (de um CDN ou de outro servidor), esses ficheiros não são enviados com a aplicação. Inclua o CSS e o JavaScript inline dentro do HTML.

### O que acontece aos dados que a aplicação produz?

A plataforma só aloja o **ficheiro HTML** da aplicação. Não guarda os dados que a aplicação produz. Uma página HTML normalmente usa `localStorage` do browser, o que significa que os dados ficam no computador de cada utilizador, não são partilhados e desaparecem se o browser for limpo. Para dados centrais e partilhados, a aplicação tem de falar com um servidor externo (trabalho adicional, fora do âmbito desta casca).
