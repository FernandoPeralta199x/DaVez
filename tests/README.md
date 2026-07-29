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
