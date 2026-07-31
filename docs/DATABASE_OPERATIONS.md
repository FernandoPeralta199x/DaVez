# Operação do banco de dados

## Veredito

O schema desta pasta é uma reconstrução mínima para **instalações novas e
descartáveis**. Ele foi derivado exclusivamente das consultas presentes nos
arquivos PHP rastreados pelo Git.

Ele não prova que um banco legado em uso tenha os mesmos tipos, defaults,
índices ou constraints. Não aplique `database/schema.sql` nem as migrations
diretamente em produção.

## Contrato derivado

O código atual usa seis tabelas:

| Tabela | Evidência no código | Responsabilidade |
|---|---|---|
| `settings` | `checkin.php`, `admin.php`, `DaVez/entrar.php` | janela da chamada e geolocalização base; token legado permanece no schema |
| `checkins` | `checkin.php`, `recover.php`, `admin.php` | fila principal, ciclo operacional e encerramento |
| `fila_da_vez` | `DaVez/*.php`, `admin.php` | fila secundária do ciclo operacional |
| `reports` | `admin.php` | snapshots de relatórios operacionais |
| `admission_tickets` | `admin.php`, `checkin.php`, `recover.php` | tickets HMAC de check-in e recovery |
| `public_sessions` | autenticação pública e logout | sessões opacas vinculadas a check-in |

O rate limiter não usa MySQL. As migrations v2 adicionam relações explícitas
entre check-ins, tickets, sessões e fila. As chaves estrangeiras usam
`ON DELETE RESTRICT`.

O registro inicial de `settings` nasce com a chamada fechada, token vazio e
raio de 1 metro. Com a base ainda em `0,0`, esse default faz a geolocalização
falhar de forma segura até que um administrador configure valores válidos.

## Regra única de ciclo operacional

A regra de domínio centralizada em
`src/Domain/OperationalCycle.php` define:

- timezone: `America/Sao_Paulo`;
- início inclusivo: 06:00:00;
- término exclusivo: 06:00:00 do dia seguinte;
- data operacional: data civil do início do ciclo.

Exemplo:

```text
2026-07-29 05:59:59 -> ciclo 2026-07-28
2026-07-29 06:00:00 -> ciclo 2026-07-29
```

### Integração em runtime

Os endpoints capturam uma única referência temporal por request por meio de
`OperationalContext`. Consultas de ciclo usam sempre o intervalo
`[início, fim)`, sem `CURDATE()` e sem funções locais duplicadas.

O fluxo público v2 não usa nem rotaciona o token coletivo. `session_info.php`
é estritamente de leitura quanto aos dados de aplicação; ele apenas inicializa
o contexto same-origin em cookie/sessão PHP.

## Migrations

As migrations são numeradas e devem ser aplicadas estritamente nesta ordem:

1. `database/migrations/001_create_settings.sql`
2. `database/migrations/002_create_checkins.sql`
3. `database/migrations/003_create_fila_da_vez.sql`
4. `database/migrations/004_create_reports.sql`
5. `database/migrations/005_add_checkins_operational_date.sql`
6. `database/migrations/006_create_admission_tickets.sql`
7. `database/migrations/007_create_public_sessions.sql`
8. `database/migrations/008_link_queue_to_checkins.sql`
9. `database/migrations/009_create_delivery_events.sql`

As migrations `001` a `004`, `006`, `007` e `009` usam
`CREATE TABLE IF NOT EXISTS`. Isso não valida uma tabela incompatível com o
mesmo nome. As migrations `005` e `008` usam `ALTER TABLE`, devem ser executadas
exatamente uma vez e exigem preflight que confirme a ausência das novas
colunas, chaves e constraints.

A migration `009` cria `delivery_events`, um log append-only de despachos que
alimenta o ranking de motoboys. Ela deve ser aplicada por um usuário com
privilégio de DDL (não pelo usuário de runtime do app). A tabela é intencionalmente
sem chave estrangeira para `checkins`, porque precisa sobreviver à limpeza do
ciclo; a limpeza (`limpar`) não a apaga.

`database/schema.sql` reúne o mesmo contrato e deve ser usado apenas para criar
um banco vazio em ambiente local ou de staging descartável.

## Preflight obrigatório para banco legado

Use uma conta somente leitura e exporte apenas metadados, nunca dados. Antes de
qualquer migration, registre:

```sql
SELECT VERSION(), @@sql_mode, @@time_zone;

SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'settings',
    'checkins',
    'fila_da_vez',
    'reports',
    'admission_tickets',
    'public_sessions'
  );

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'settings',
    'checkins',
    'fila_da_vez',
    'reports',
    'admission_tickets',
    'public_sessions'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'settings',
    'checkins',
    'fila_da_vez',
    'reports',
    'admission_tickets',
    'public_sessions'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
```

Depois:

1. compare os metadados com `database/schema.sql`;
2. classifique cada diferença como compatível, conversível ou bloqueadora;
3. confirme `checkins.id` e `fila_da_vez.id` como `BIGINT UNSIGNED`,
   `fila_da_vez.dia` como `DATE` e todas as tabelas envolvidas como InnoDB;
4. verifique a versão real do MySQL/MariaDB e o enforcement de `CHECK`;
5. confirme que os nomes de coluna, índice e constraint das migrations
   `005..008` ainda não existem;
6. não faça backfill de `operational_date` até confirmar timezone e semântica
   dos `DATETIME` legados;
7. meça lock e reconstrução dos dois `ALTER TABLE` em clone descartável;
8. faça backup e restaure-o em ambiente descartável;
9. aplique `005..008` na ordem e execute testes de integração;
10. só então planeje uma janela controlada para o ambiente real.

## Identidade pública v2 no banco

`admission_tickets.ticket_hash` armazena somente HMAC-SHA-256 binário. O ticket
bruto não pertence ao banco. O prazo é fixado em dez minutos e o consumo deve
ser uma atualização condicional dentro da mesma transação que cria ou recupera
o check-in e cria a sessão.

`public_sessions.token_hash` armazena somente SHA-256 binário de token aleatório
com pelo menos 32 bytes. A chave única `(checkin_id, active_slot)` permite uma
única sessão atual. Ao revogar, substituir ou encerrar uma sessão, a aplicação
deve preencher `revoked_at`, `revocation_reason` e definir
`active_slot=NULL` na mesma transação.

As foreign keys compostas garantem que ticket, sessão e item da fila apontem
para o mesmo `checkin_id` e `operational_date`. Registros legados podem manter
`checkins.operational_date` e `fila_da_vez.checkin_id` nulos.

As referências usam `ON DELETE RESTRICT`. Antes de apagar um `checkin`, o fluxo
v2 deve revogar e remover, conforme a política de retenção aprovada, sessões,
tickets e itens da fila associados. A limpeza atual não pode operar sobre
check-ins v2 enquanto esse tratamento transacional não estiver implementado.

## Aplicação em ambiente descartável

Exemplo ilustrativo, sem credenciais reais:

```powershell
mysql --host=HOST --user=MIGRATION_USER --password DATABASE_NAME `
  < database/schema.sql
```

Não execute esse comando com valores de produção sem aprovação, backup e
validação de restore.

O usuário de migration deve ser separado do usuário da aplicação. Depois da
instalação, o usuário de runtime deve possuir somente os privilégios mínimos
necessários, normalmente `SELECT`, `INSERT`, `UPDATE` e `DELETE` nas tabelas da
aplicação. Ele não deve possuir `CREATE`, `ALTER` ou `DROP`.

## DDL em requisições

Os endpoints, incluindo `DaVez/entrar.php` e `DaVez/listar.php`, não executam
mais `CREATE TABLE`, `ALTER TABLE` nem inspeção com `SHOW TABLES` ou
`SHOW COLUMNS`. Se o schema esperado não estiver instalado, o request falha com
erro operacional seguro. A criação e evolução das tabelas ficam restritas às
migrations executadas fora do runtime.

## Ordem atômica

`src/Database/AtomicOrderAllocator.php` oferece o contrato seguro para reservar
e persistir uma posição:

1. adquire advisory lock exclusivo por fila e ciclo;
2. inicia uma transação;
3. lê a maior ordem;
4. calcula `máximo + 1`;
5. persiste a nova linha dentro da mesma transação;
6. confirma a transação;
7. libera o advisory lock em qualquer resultado.

Os callbacks de leitura e persistência, `TransactionRunner` e `AdvisoryLock`
devem compartilhar a **mesma sessão MySQL**. Separar conexões quebra a garantia.
O callback de persistência deve lançar exceção se a escrita não ocorrer.

### Falha ao liberar o advisory lock

O terceiro argumento opcional do construtor de `AtomicOrderAllocator` é um
callback de observabilidade. Ele é chamado quando `RELEASE_LOCK` falha e recebe
somente:

```text
event             = advisory_lock_release_failed
committed         = true | false
exception_class   = classe da exceção, sem a mensagem
```

O evento não contém nome ou escopo do lock, identificadores, payloads, nomes,
tokens ou mensagens da exceção. O callback deve encaminhar esses campos para
um logger ou contador com allowlist. Qualquer exceção lançada pelo próprio
callback é contida pelo helper.

A semântica evita retries perigosos:

- se a transação falhar antes de um commit confirmado, o helper tenta liberar
  o lock e relança a falha original, mesmo quando `RELEASE_LOCK` ou o callback
  também falham;
- se o commit for confirmado e somente `RELEASE_LOCK` falhar, o helper emite o
  evento com `committed=true` e retorna a posição persistida normalmente;
- o chamador não deve repetir uma operação cujo commit já foi confirmado;
- uma falha de aquisição não inicia transação nem tenta liberar um lock que não
  foi obtido.

Quando a liberação não puder ser confirmada, a conexão deve ser considerada
suspeita pela camada operacional e não deve ser reutilizada indefinidamente.
Locks do MySQL também são liberados quando a sessão é encerrada.

O helper está integrado aos produtores de ordem de `checkins` e
`fila_da_vez`. As reordenações usam o mesmo lock por fila/ciclo, bloqueiam o
conjunto atual com `SELECT ... FOR UPDATE`, exigem IDs exatos e verificam a
sequência final `1..N`. Conflitos de estado retornam HTTP 409; lock ocupado
retorna HTTP 503 com orientação de retry.

O fluxo administrativo de relatório, limpeza do ciclo e fechamento da chamada
é executado em uma única transação. Qualquer falha reverte as três operações.

Endpoints HTTP não executam DDL nem introspecção de schema. Schema e migrations
devem ser preparados fora do caminho de request.

## Geofence e identidade pública

`src/Domain/Geofence.php` é a única implementação de distância e usa Haversine.
Base `0,0` ou raio menor ou igual a zero falham de forma fechada. Os dois fluxos
de entrada usam o mesmo helper.

O fluxo v2 usa ticket Crockford Base32 de uso único, HMAC no banco e sessão
opaca armazenada somente como hash. `client_id` permanece nullable no schema
para dados legados, mas não é aceito como identidade pelo check-in ou pela fila
v2. Consulte `docs/PUBLIC_IDENTITY_V2.md`.

## Forward rollback

Não existe um rollback destrutivo genérico. Essa operação seria insegura:
uma migration idempotente pode encontrar e reutilizar uma tabela legada, e um
arquivo `down.sql` não conseguiria distinguir dados antigos de dados criados
pela instalação.

Plano obrigatório antes de existirem dados v2:

1. tirar backup consistente antes de qualquer DDL;
2. registrar checksum, horário e versão do backup;
3. restaurar em ambiente descartável;
4. validar contagens e fluxos essenciais no restore;
5. aplicar a migration;
6. se a validação falhar, interromper a ativação da identidade v2;
7. retornar a aplicação à versão anterior mantendo o schema aditivo;
8. restaurar o backup somente se a falha de DDL exigir;
9. registrar a ocorrência e reconciliar escritas realizadas durante a janela.

Depois que tickets ou sessões forem emitidos, o rollback deve interromper novas
emissões, revogar credenciais ativas e preservar as tabelas para auditoria. Não
é seguro reativar automaticamente o token coletivo ou o relogin por nome.

Para instalações descartáveis sem dados, o rollback pode ser recriar o banco
por completo. Essa decisão não se aplica automaticamente a staging persistente
ou produção.

## Backup e restore mínimos

Antes de staging ou produção, valide:

- backup com schema, constraints, índices e dados;
- criptografia e controle de acesso ao arquivo;
- retenção definida;
- restore em banco vazio;
- conferência de contagens por tabela;
- execução dos testes de integração;
- tempo de recuperação conhecido;
- descarte seguro do backup de teste.

Não armazene dumps no repositório.

## Dados sensíveis e retenção

O contrato contém campos que podem conter dados pessoais:

- `checkins.nome`;
- `checkins.client_id`;
- `checkins.ip`;
- `checkins.user_agent`;
- `reports.payload_json`.

O check-in v2 não grava IP nem User-Agent; as colunas permanecem nullable para
compatibilidade com registros legados. Isso não autoriza retenção indefinida.
Antes de produção, defina finalidade, acesso, retenção, anonimização e descarte.
`payload_json` pode duplicar dados da fila e exige o mesmo ou maior rigor.

## Incertezas explícitas

- Não existe dump de schema autorizado no repositório.
- A versão real e o modo SQL do servidor não foram verificados.
- Os tipos e defaults do banco legado são desconhecidos.
- A collation do ambiente legado é desconhecida.
- O comportamento de `CHECK` depende da versão do MySQL/MariaDB.
- A regra de unicidade de nomes não está formalizada.
- `client_id` pode ser nulo em inserções manuais.
- `checkins.operational_date` permanece nulo para registros legados até existir
  uma estratégia de backfill validada.
- As foreign keys v2 usam exclusão restrita; o fluxo de limpeza precisa tratar
  as dependências antes do corte.
- O suporte às expressões de data usadas nos `CHECK` depende da versão real.
- Não há teste de concorrência contra um MySQL real.

## Validação sem banco

Os testes desta etapa não conectam ao MySQL:

```powershell
php tests/domain/operational_cycle_test.php
php tests/domain/operational_context_test.php
php tests/domain/geofence_test.php
php tests/domain/token_cycle_test.php
php tests/domain/queue_reorder_test.php
php tests/domain/report_snapshot_test.php
php tests/database/atomic_order_allocator_test.php
php tests/database/settings_token_cycle_cas_policy_test.php
php tests/database/queue_exit_compaction_policy_test.php
php tests/database/identity_v2_schema_contract_test.php
php tests/database/runtime_data_policy_test.php
php tests/database/schema_contract_test.php
```

Eles validam ciclo e referência única, geofence, token/identificadores legados,
reordenação exata, snapshot de relatório, ordem de
lock/transação/persistência, rollback em falha, limite do nome do lock e os
contratos estáticos de runtime e schema.

Ainda são necessários testes de integração em MySQL descartável para:

- sintaxe e compatibilidade das migrations;
- enforcement real das constraints;
- planos de execução dos índices;
- concorrência na atribuição de ordem;
- backup e restore;
- upgrade de um clone anonimizado do banco legado.
