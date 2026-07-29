# Testes

Os testes automatizados de segurança ficam em `tests/security`.

## Política do artefato de produção

```bash
node tests/security/production_artifact_policy.test.js
```

O teste impede o retorno dos endpoints públicos de diagnóstico removidos no lote
F-015.

Prioridades para a futura suíte:

1. testes de caracterização das respostas existentes;
2. autenticação, autorização e CSRF;
3. entrada, saída e reordenação das duas filas;
4. concorrência na atribuição de posições;
5. rotação e expiração de sessão;
6. limpeza do ciclo e geração de relatórios;
7. sanitização de logs e erros públicos.

## Testes disponíveis

### Domínio e persistência sem MySQL

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

Valida os contratos puros de ciclo, geofence, token legado, reordenação e
relatório, além da disciplina estática de lock/transação e da ausência de DDL,
`CURDATE()` e introspecção de schema nos endpoints HTTP. A concorrência real
ainda exige MySQL descartável.

### Política estática de logging

```powershell
node tests/security/logging_policy.test.js
```

Verifica que os endpoints não enviam POST bruto, tokens, nomes, coordenadas, identificadores ou erros de banco para o logger.

### Integração do logger

```powershell
php tests/security/log_event_test.php
```

Grava um evento em arquivo temporário pela implementação real de `log_event` e confirma que dados sensíveis são descartados enquanto métricas operacionais permitidas permanecem.

### Política do cache da PWA

```powershell
node tests/security/service_worker_cache_policy.test.js
```

Confirma que apenas assets estáticos conhecidos entram no cache. Navegações usam rede com fallback offline, enquanto PHP, APIs, painel, logs, relatórios, origens externas e métodos não GET permanecem fora do Service Worker.

### Fundação de segurança

```powershell
php tests/security/security_foundation_test.php
php tests/security/rate_limiter_test.php
```

Valida sessão administrativa segura, autenticação por hash, expiração, CSRF,
contexto público same-origin, validação de entrada, métodos HTTP, erros públicos
e rate limiting com estado opaco.

### Política dos endpoints

```powershell
node tests/security/endpoint_security_policy.test.js
node tests/security/admin_script_syntax.test.js
```

Impede o retorno de autenticação Basic/senha em texto puro, mutações
administrativas por GET, respostas com `sql_error`/`debug` e endpoints sem os
controles obrigatórios. Também compila o JavaScript inline do painel sem
executá-lo.
