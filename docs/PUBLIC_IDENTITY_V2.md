# Identidade pública v2 — plano de segurança

## Status

> **APROVADO — IMPLEMENTAÇÃO EM ANDAMENTO**
>
> O proprietário aprovou em 2026-07-29 o pacote recomendado
> DEC-PID2-01 a DEC-PID2-10. A aprovação desbloqueia o desenvolvimento, mas
> não autoriza executar migrations, realizar o corte, deploy ou mudança em
> produção. Cada item somente deve ser considerado implementado depois dos
> testes correspondentes.

## Veredito

**[Certeza]** Não é possível recuperar uma identidade com segurança usando
apenas nome, geolocalização, IP, User-Agent, `client_id` ou outro valor escolhido
pelo navegador.

O menor caminho seguro para o DaVez, que hoje não possui contas ou senhas
individuais, é:

1. substituir o token coletivo por tickets individuais, descartáveis e de curta
   duração;
2. criar uma sessão pública opaca no servidor depois do check-in autorizado;
3. restaurar o estado automaticamente apenas no dispositivo que possui o cookie
   da sessão;
4. desativar o re-login por nome;
5. exigir recuperação presencial/administrativa quando o cookie for perdido.

Uma futura conta, OTP ou passkey pode permitir recuperação autônoma, mas não faz
parte desta proposta mínima.

## Ameaça atual

O fluxo legado combina dois elementos que não provam identidade:

- um token operacional compartilhado por várias pessoas;
- um `client_id` criado e enviado pelo frontend.

O frontend também preserva nome, token e identificadores em `localStorage`. O
re-login procura um check-in por nome e aceita o mesmo token compartilhado. A
fila secundária usa `(dia, client_id)` como unicidade e a listagem pública
retorna `client_id`.

Consequências:

- quem conhece o token coletivo pode tentar assumir um nome existente;
- o navegador pode escolher ou copiar um `client_id`;
- limpar cookies ou trocar de dispositivo não possui recuperação individual
  confiável;
- a listagem pública expõe identificadores desnecessários;
- não existe revogação individual efetiva.

## Propriedades obrigatórias

| ID | Propriedade implementada localmente |
|---|---|
| PID2-01 | Nenhum ID vindo do frontend será fonte de autenticação ou autorização. |
| PID2-02 | Cada admissão usará ticket individual, de uso único e com expiração. |
| PID2-03 | O token bruto da sessão existirá apenas no cookie do dispositivo. |
| PID2-04 | O banco armazenará somente hash do token de sessão. |
| PID2-05 | Recarregar no mesmo dispositivo restaurará o estado pelo cookie. |
| PID2-06 | Nome isolado nunca recuperará uma sessão. |
| PID2-07 | Recuperação revogará sessões anteriores conforme política aprovada. |
| PID2-08 | A resposta pública não conterá `client_id` nem IDs internos. |
| PID2-09 | Tickets, cookies e códigos nunca serão registrados em logs. |
| PID2-10 | O corte do legado será explícito, testado e auditável. |

## Arquitetura recomendada

### Tickets individuais

O administrador emitirá um ticket para uma finalidade específica:

- `checkin`: autoriza um novo check-in no ciclo indicado;
- `recovery`: autoriza recuperar um check-in já identificado pelo
  administrador.

Características implementadas:

- código individual curto de 8 caracteres no formato `NNNN-llll`
  (4 dígitos + 4 letras minúsculas, ex.: `1166-aabb`), digitável;
- QR individual transporta a URL pública com o mesmo código no fragmento
  `#access_code`; o fragmento é removido do endereço pelo frontend antes de
  qualquer requisição;
- validade exata de 10 minutos;
- consumo único e atômico;
- rate limiting por rede e contexto;
- revogação administrativa;
- valor bruto exibido uma única vez;
- armazenamento como HMAC usando segredo externo ao repositório.

Um único QR ou código compartilhado entre todos continuaria sendo um token
coletivo e não atende a esta arquitetura.

### Sessão pública

Depois de consumir um ticket de check-in, o backend criará uma sessão vinculada
ao check-in daquele ciclo.

Cookie implementado para produção:

```text
Nome: __Host-davez_public
Secure: true
HttpOnly: true
SameSite: Strict
Path: /
Domain: ausente
```

O valor será aleatório, com pelo menos 32 bytes. Somente o hash será persistido.
O cookie não será gravado em `localStorage`, retornado por JSON ou usado como
identificador visual.

A sessão prova a posse do dispositivo autorizado no ciclo. Ela não representa
identidade civil permanente e não deve ser descrita como conta de usuário.

### Modelo de dados implementado no lote local

As estruturas abaixo constam de `database/schema.sql` e das migrations aditivas
`005..008`. Elas ainda não foram executadas ou validadas em MySQL real.

#### `admission_tickets`

| Campo | Finalidade |
|---|---|
| `id` | Identificador interno |
| `ticket_hash` | HMAC do código individual |
| `purpose` | `checkin` ou `recovery` |
| `checkin_id` | Vínculo obrigatório para recuperação |
| `operational_date` | Ciclo autorizado |
| `expires_at` | Expiração |
| `consumed_at` | Consumo único |
| `revoked_at` | Revogação |
| `created_at` | Auditoria |

#### `public_sessions`

| Campo | Finalidade |
|---|---|
| `id` | Identificador interno |
| `checkin_id` | Identidade operacional derivada no backend |
| `token_hash` | Hash do token aleatório |
| `created_at` | Criação |
| `last_seen_at` | Última atividade necessária |
| `expires_at` | Expiração absoluta |
| `revoked_at` | Revogação |
| `rotated_from_id` | Rastreabilidade de rotação |

#### Evolução de `fila_da_vez`

O lote adiciona `checkin_id` nullable e unicidade por `(dia, checkin_id)`.
O `client_id` legado permanece nullable apenas para compatibilidade de schema e
nunca autentica uma sessão.

## Fluxos implementados localmente

### Primeiro check-in no dispositivo

```text
Página sem sessão
  → administrador entrega ticket individual
  → navegador envia nome, ticket, localização e CSRF
  → backend valida ticket, ciclo, raio, expiração e rate limit
  → backend consome ticket + cria check-in + cria sessão na mesma transação
  → backend envia cookie HttpOnly
  → frontend consulta seu estado sem receber IDs internos
```

Se qualquer etapa transacional falhar, ticket, check-in e sessão devem sofrer
rollback conjunto.

### Retorno no mesmo dispositivo

```text
Página recarregada
  → cookie segue automaticamente
  → session_info valida hash, expiração e revogação
  → resposta contém somente estado público de "me"
```

Não haverá re-login por nome ou leitura de token no `localStorage`.

### Perda do cookie ou troca de dispositivo

Caminho mínimo recomendado:

```text
Sem cookie
  → endpoint legado de re-login responde 410 Gone
  → pessoa solicita ajuda ao administrador
  → administrador confirma presencialmente o check-in
  → administrador emite ticket de recovery vinculado ao checkin_id
  → ticket é consumido
  → sessões anteriores são revogadas
  → nova sessão é emitida
```

Sem confirmação administrativa, conta, OTP ou passkey, a recuperação deve ser
negada.

### Entrada na fila da vez

```text
Sessão pública válida + CSRF + localização
  → backend deriva checkin_id da sessão
  → backend confirma ciclo e elegibilidade
  → backend grava fila por checkin_id
```

Nome, token, `client_id`, papel ou permissão enviados pelo frontend serão
rejeitados.

## Impacto implementado por arquivo

### `index.html`

- remover geração, persistência e comparação de `client_id`;
- remover token, nome autenticado e credenciais do `localStorage`;
- remover `tryRelogin()` e recuperação por nome;
- trocar “token do turno” por ticket individual;
- manter o CSRF somente em memória;
- obter `authenticated`, `me.status`, `me.position` e `me.is_next` de
  `session_info.php`;
- tratar HTTP 410 como sessão perdida;
- exigir atualização quando `identity_version` não for compatível.

### `checkin.php`

- aceitar somente nome, ticket individual, localização e CSRF;
- rejeitar IDs de identidade fornecidos pelo cliente;
- validar e consumir ticket atomicamente;
- criar check-in e sessão na mesma transação;
- emitir cookie sem retornar token ou ID interno;
- remover dependência do token coletivo.

### `relogin.php`

- no corte mínimo, responder HTTP 410 e não consultar por nome;
- se a recuperação administrativa for aprovada, aceitar somente ticket de
  `recovery` já vinculado a um `checkin_id`;
- revogar sessões anteriores antes de emitir a nova.

### `session_info.php`

- tornar o endpoint estritamente de leitura;
- validar o cookie opaco;
- não gerar ou rotacionar token operacional;
- não retornar token de sessão, `client_id` ou ID do banco;
- retornar a versão de identidade, estado autenticado e visão `me`.

Forma resumida da resposta implementada:

```json
{
  "identity_version": 2,
  "authenticated": true,
  "operational_date": "AAAA-MM-DD",
  "me": {
    "status": "na_fila",
    "position": 3,
    "is_next": false
  }
}
```

### `DaVez/entrar.php`

- exigir sessão pública válida e CSRF;
- aceitar somente localização necessária;
- derivar `checkin_id`, nome e ciclo no backend;
- gravar a fila pelo `checkin_id`;
- rejeitar nome, token e `client_id` recebidos.

### `DaVez/listar.php`

- não retornar `client_id` ou IDs internos;
- separar visão pública mínima da visão administrativa completa;
- derivar `me` da sessão;
- remover DDL executado durante requisição HTTP em lote próprio.

### `DaVez/sair.php` e `DaVez/reordenar.php`

- permanecer exclusivamente administrativos;
- IDs internos podem existir no painel autenticado;
- os mesmos IDs não devem chegar à visão pública.

### `admin.php`

- emitir e revogar tickets;
- emitir recovery apenas após confirmação operacional;
- revogar sessões públicas;
- mostrar validade e estado sem revelar código ou cookie;
- registrar auditoria sanitizada das ações.

## Migrations aditivas preparadas

Nenhuma migration foi executada por este lote. A ordem preparada é:

1. `005_add_checkins_operational_date.sql`;
2. `006_create_admission_tickets.sql`;
3. `007_create_public_sessions.sql`;
4. `008_link_queue_to_checkins.sql`.

As colunas legadas permanecem para compatibilidade de schema, mas não
autenticam o fluxo v2. Aplicação, backfill, validação e rollback devem seguir
`docs/DATABASE_OPERATIONS.md` em um MySQL isolado.

Backfill de `checkin_id` serve para consistência, não para autenticação.
Associações ambíguas devem ser encaminhadas para revisão em vez de inferidas.

## Transição e corte

### Estratégia recomendada

Realizar corte no início de um novo ciclo:

1. aprovar as decisões pendentes;
2. validar backup e restore;
3. criar e testar migrations aditivas;
4. preparar backend, frontend e Service Worker v2;
5. encerrar o ciclo legado;
6. desativar o re-login por nome;
7. invalidar o token coletivo;
8. ativar tickets e sessões;
9. exigir novo check-in dos dispositivos;
10. observar um ciclo completo;
11. remover caminhos legados em lote posterior.

Não existe migração transparente segura a partir do `client_id`. Uma transição
`dual` manteria a vulnerabilidade ativa e, se inevitável, deve possuir janela
curta, data de encerramento e métricas explícitas.

O backend v2 deve rejeitar frontend antigo com resposta segura e orientação de
atualização. O cache da PWA precisa ser versionado no mesmo corte.

## Expiração e revogação

Padrão aprovado e implementado localmente:

- ticket válido por 10 minutos;
- ticket de uso único;
- sessão válida até o fim do ciclo;
- limite absoluto máximo de 24 horas;
- logout revoga no servidor;
- recuperação revoga sessões anteriores;
- limpeza do ciclo revoga sessões associadas;
- suspeita de abuso permite revogação administrativa;
- token da sessão é rotacionado depois de check-in e recuperação.

Esses tempos foram aprovados pelo proprietário em 2026-07-29.

## Privacidade

- não armazenar código ou cookie em `localStorage`;
- não retornar `client_id`, hash, cookie ou IDs internos;
- não usar fingerprint do dispositivo;
- não logar ticket, sessão, nome completo ou coordenadas;
- revisar a necessidade de persistir IP e User-Agent;
- definir retenção para tickets, sessões e nomes;
- limitar a fila completa ao painel administrativo quando possível;
- se nomes forem necessários publicamente, considerar abreviação.

## Plano de testes

### Unitários

- geração, HMAC, expiração e revogação de ticket;
- geração, hash, rotação e expiração de sessão;
- CSRF vinculado à sessão;
- rejeição de IDs fornecidos pelo frontend.

### Integração

- consumo único e concorrente de ticket;
- commit/rollback conjunto de ticket, check-in e sessão;
- ausência do token bruto em banco e logs;
- sessão expirada ou revogada não acessa a fila;
- recovery revoga sessão anterior;
- `checkin_id` é derivado apenas da sessão;
- re-login por nome retorna 410;
- listagem pública não contém identificadores;
- rate limiting de tickets inválidos.

### E2E

- primeiro check-in em dispositivo novo;
- reload no mesmo dispositivo;
- tentativa com mesmo nome em outro dispositivo;
- cópia de `client_id` legado;
- perda de cookie;
- recuperação administrativa;
- tentativa de CSRF cross-site;
- expiração no limite do ciclo;
- atualização obrigatória de PWA antiga.

### Políticas estáticas

- nenhum endpoint público lê `client_id` como identidade;
- `index.html` não persiste token ou identidade em `localStorage`;
- respostas públicas não incluem `client_id`;
- código ativo não consulta `settings.token`;
- endpoints HTTP não executam DDL.

## Decisões aprovadas pelo proprietário

| ID | Decisão aprovada |
|---|---|
| DEC-PID2-01 | Recuperação exclusivamente administrativa e presencial até existir conta, OTP ou passkey. |
| DEC-PID2-02 | Uma sessão ativa por check-in; recovery revoga a anterior. |
| DEC-PID2-03 | QR individual, com código individual digitável como fallback. |
| DEC-PID2-04 | Corte completo no início de um ciclo operacional. |
| DEC-PID2-05 | Fila pública mostra somente o próximo chamado e a visão autenticada `me`. |
| DEC-PID2-06 | Sessão válida até o fim do ciclo, limitada a 24 horas. |
| DEC-PID2-07 | Ticket válido por 10 minutos e de uso único. |
| DEC-PID2-08 | IP e User-Agent não serão persistidos no check-in v2. |
| DEC-PID2-09 | HTTPS obrigatório em staging e produção; HTTP permitido somente em localhost. |
| DEC-PID2-10 | Emissão administrativa de ticket individual, iniciando por piloto operacional. |

## Pacote técnico aprovado

O desenvolvimento deve seguir estes padrões:

- um ticket individual presencial por admissão;
- uma sessão ativa por check-in;
- cookie host-only, `Secure`, `HttpOnly` e `SameSite=Strict`;
- re-login por nome desativado;
- recuperação administrativa que revoga sessão anterior;
- corte no início de um ciclo;
- fila pública mínima, sem IDs;
- ticket de 10 minutos;
- sessão até o fim do ciclo, limitada a 24 horas;
- HTTPS obrigatório;
- nenhum uso autenticador de `client_id`.

## Critério para desbloquear ativação

A implementação local foi iniciada porque o item 1 foi concluído. Ativação,
migration e corte continuam condicionados aos itens 2 a 6:

1. **Concluído:** DEC-PID2-01 a DEC-PID2-10 aprovadas em 2026-07-29;
2. o processo de distribuição de tickets tiver responsável definido;
3. HTTPS, secrets e storage de sessão estiverem disponíveis;
4. backup, restore e rollback das migrations estiverem documentados;
5. o impacto do corte sobre o ciclo ativo tiver sido aceito;
6. os critérios de privacidade da listagem estiverem aprovados.
