# Operação do banco de dados

## Veredito

O schema desta pasta é uma reconstrução mínima para **instalações novas e
descartáveis**. Ele foi derivado exclusivamente das consultas presentes nos
arquivos PHP rastreados pelo Git.

Ele não prova que um banco legado em uso tenha os mesmos tipos, defaults,
índices ou constraints. Não aplique `database/schema.sql` nem as migrations
diretamente em produção.

## Contrato derivado

O código atual usa quatro tabelas:

| Tabela | Evidência no código | Responsabilidade |
|---|---|---|
| `settings` | `checkin.php`, `session_info.php`, `admin.php`, `DaVez/entrar.php` | token, janela da chamada e geolocalização base |
| `checkins` | `checkin.php`, `relogin.php`, `admin.php`, `DaVez/entrar.php` | fila principal e encerramento de atendimentos |
| `fila_da_vez` | `DaVez/*.php`, `admin.php` | fila secundária do ciclo operacional |
| `reports` | `admin.php` | snapshots de relatórios operacionais |

Não há evidência de uma relação por chave estrangeira entre essas tabelas.
Por isso, nenhuma foreign key foi inventada.

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

O ciclo de três dias do token legado usa a mesma referência temporal. A rotação
ainda pode persistir estado durante `GET` em `session_info.php` para preservar o
contrato do frontend atual. Isso é um gap conhecido: a identidade pública v2
deve tornar esse endpoint estritamente de leitura.

## Migrations

As migrations são numeradas e devem ser aplicadas estritamente nesta ordem:

1. `database/migrations/001_create_settings.sql`
2. `database/migrations/002_create_checkins.sql`
3. `database/migrations/003_create_fila_da_vez.sql`
4. `database/migrations/004_create_reports.sql`

Cada migration usa `CREATE TABLE IF NOT EXISTS`. Isso torna a reaplicação
inofensiva em uma instalação nova já criada, mas **não transforma uma tabela
legada incompatível**. Se uma tabela com o mesmo nome já existir, o MySQL não
compara nem corrige sua estrutura.

`database/schema.sql` reúne o mesmo contrato e deve ser usado apenas para criar
um banco vazio em ambiente local ou de staging descartável.

## Preflight obrigatório para banco legado

Use uma conta somente leitura e exporte apenas metadados, nunca dados. Antes de
qualquer migration, registre:

```sql
SELECT VERSION();

SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('settings', 'checkins', 'fila_da_vez', 'reports');

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('settings', 'checkins', 'fila_da_vez', 'reports')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('settings', 'checkins', 'fila_da_vez', 'reports')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
```

Depois:

1. compare os metadados com `database/schema.sql`;
2. classifique cada diferença como compatível, conversível ou bloqueadora;
3. verifique a versão real do MySQL/MariaDB e o suporte a `CHECK`;
4. crie uma migration de upgrade específica, sem sobrescrever as migrations
   de instalação nova;
5. faça backup;
6. restaure o backup em banco descartável;
7. aplique e teste a migration nesse clone;
8. só então planeje uma janela controlada para o ambiente real.

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

## Geofence e identificadores legados

`src/Domain/Geofence.php` é a única implementação de distância e usa Haversine.
Base `0,0` ou raio menor ou igual a zero falham de forma fechada. Os dois fluxos
de entrada usam o mesmo helper.

O código curto e o `client_id` legados mantêm seus formatos, mas agora são
gerados por `random_int` e `random_bytes`. Eles continuam sendo compatibilidade
temporária, não uma identidade autenticável; a substituição definitiva está em
`docs/PUBLIC_IDENTITY_V2.md`.

## Rollback

Não existe um rollback genérico com `DROP TABLE`. Essa operação seria insegura:
uma migration idempotente pode encontrar e reutilizar uma tabela legada, e um
arquivo `down.sql` não conseguiria distinguir dados antigos de dados criados
pela instalação.

Plano obrigatório:

1. tirar backup consistente antes de qualquer DDL;
2. registrar checksum, horário e versão do backup;
3. restaurar em ambiente descartável;
4. validar contagens e fluxos essenciais no restore;
5. aplicar a migration;
6. se a validação falhar, interromper a aplicação;
7. restaurar o backup verificado;
8. registrar a ocorrência e reconciliar escritas realizadas durante a janela.

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

O contrato legado exige campos que podem conter dados pessoais:

- `checkins.nome`;
- `checkins.client_id`;
- `checkins.ip`;
- `checkins.user_agent`;
- `reports.payload_json`.

O schema preserva esses campos porque o código os utiliza; isso não autoriza
retenção indefinida. Antes de produção, defina finalidade, acesso, retenção,
anonimização e descarte para cada campo. `payload_json` pode duplicar dados da
fila e exige o mesmo ou maior rigor.

## Incertezas explícitas

- Não existe dump de schema autorizado no repositório.
- A versão real e o modo SQL do servidor não foram verificados.
- Os tipos e defaults do banco legado são desconhecidos.
- A collation do ambiente legado é desconhecida.
- O comportamento de `CHECK` depende da versão do MySQL/MariaDB.
- A regra de unicidade de nomes não está formalizada.
- `client_id` pode ser nulo em inserções manuais.
- A fila principal não persiste a data operacional em coluna própria; portanto,
  não foi criada uma constraint única por ciclo.
- Não há evidência suficiente para criar foreign keys.
- Não há evidência suficiente para definir política de exclusão em cascata.
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
