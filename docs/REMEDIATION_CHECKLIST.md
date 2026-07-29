# DaVez — checklist consolidado de remediação

## Controle do documento

- Projeto: DaVez
- Workspace: `X:\Help`
- Data da consolidação: 2026-07-29
- Ambiente observado: local
- Maturidade: MVP em remediação
- Escopo: código, documentação e arquivos de exemplo rastreáveis
- Exclusões deliberadas: `config.php`, `.env` real, `.private`, logs,
  relatórios, artifacts e dados de runtime

Este checklist consolida o que existe no workspace, o que foi validado durante
a remediação e o que continua pendente. Ele não autoriza migration, deploy,
merge ou alteração de produção.

## Legenda de status

| Status | Significado |
|---|---|
| **IMPLEMENTADO** | Código, teste ou documentação existe localmente. Não implica deploy ou validação em produção. |
| **PARCIAL** | Existe fundação ou correção, mas falta integração, validação real ou encerramento do legado. |
| **PLANEJADO** | Trabalho descrito, ainda não implementado. |
| **BLOQUEADO POR DECISÃO** | Depende de escolha explícita do proprietário ou autoridade externa. |
| **NÃO VALIDADO** | Não há evidência suficiente de execução no ambiente necessário. |

## Veredito executivo

**[Certeza]** O projeto ainda não está pronto para staging ou produção.

Os principais bloqueadores são:

1. identidade pública ainda baseada em token coletivo e `client_id` legado;
2. ausência de validação com MySQL real;
3. ausência de E2E dos fluxos completos;
4. migrations, backup, restore e rollback ainda não executados em ambiente
   representativo;
5. segregação de webroot/storage ainda incompleta;
6. monitoramento operacional ainda não implementado;
7. checks remotos do GitHub não estão comprovados para o PR #1.

## Fase 0 — governança, baseline e rastreabilidade

| ID | Severidade | Camada | Status | Evidência segura | Critério de aceite | Teste/validação | Dependências |
|---|---|---|---|---|---|---|---|
| GOV-001 | Informativo | Repositório | IMPLEMENTADO | `README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `docs/ARCHITECTURE.md`, `.gitignore` | Baseline documentado, arquivos privados ignorados e estrutura compreensível | Revisão estática dos arquivos públicos | Nenhuma |
| GOV-002 | Alto | Secrets | IMPLEMENTADO | `.gitignore`, `.env.example`, `config.example.php`, `tests/security/secret_hygiene_policy.test.js` | Configuração real, env, logs, relatórios, dumps e chaves não entram no repositório | `node tests/security/secret_hygiene_policy.test.js` passou nesta sessão | Manter a política no CI |
| GOV-003 | Alto | Privacidade | IMPLEMENTADO | Regras de exclusão em `.gitignore`; referência operacional deste checklist | Relatório privado continua fora do artefato público e não é lido por validações automáticas | Conteúdo privado deliberadamente não validado | Responsável deve manter armazenamento e acesso controlados |
| GOV-004 | Médio | Auditoria | NÃO VALIDADO | O arquivo `CLAUDE_DAVEZ_AUDITORIA_E_PLANO_CORRECAO-1.md` citado pelo proprietário não está presente em `X:\Help`; nenhum conteúdo privado foi procurado fora do workspace | O documento é disponibilizado dentro do workspace ou seus achados relevantes são migrados, sanitizados e rastreados | Documento citado ausente; conteúdo não analisado | Proprietário deve disponibilizar uma cópia autorizada |
| GOV-005 | Médio | GitHub | PARCIAL | PR remoto [#1](https://github.com/FernandoPeralta199x/DaVez/pull/1) | PR revisado, checks obrigatórios verdes e merge aprovado | Estado histórico desta sessão: não draft e sem checks; não reconsultado por esta documentação | CI remoto e revisão humana |
| GOV-006 | Médio | Mudanças | PARCIAL | Branch local integrada e arquivos de testes existentes | Cada lote possui PR próprio, diff pequeno, testes e rollback documentado | Não validado o estado remoto final de todos os lotes | Definir estratégia de branches e merges |

## Fase 1 — fundação de segurança

| ID | Severidade | Camada | Status | Evidência segura | Critério de aceite | Teste/validação | Dependências |
|---|---|---|---|---|---|---|---|
| SEC-001 | Crítico | Autenticação admin | IMPLEMENTADO | `src/Security/AdminAuth.php`, `src/Security/Session.php`, `admin.php`, `config.example.php` | Login usa `ADMIN_USER` e `ADMIN_PASSWORD_HASH`; não existe HTTP Basic nem senha administrativa em texto puro | `tests/security/security_foundation_test.php`, `tests/security/endpoint_security_policy.test.js` | Configuração externa correta e HTTPS |
| SEC-002 | Alto | Sessão | IMPLEMENTADO | `src/Security/Session.php` | ID rotacionado após login, expiração por inatividade/absoluta e cookies `HttpOnly`, `SameSite` e `Secure` em HTTPS | Teste focado passou durante a integração de segurança | Servidor deve informar HTTPS corretamente |
| SEC-003 | Alto | CSRF | IMPLEMENTADO | `src/Security/Csrf.php`, `admin.php`, `DaVez/reordenar.php`, `DaVez/sair.php` | Toda mutação administrativa exige sessão, método correto e CSRF | Política estática e teste da fundação disponíveis | E2E autenticado ainda necessário |
| SEC-004 | Alto | Métodos HTTP | IMPLEMENTADO | `src/Http/Request.php`, `admin.php`, endpoints `DaVez/` | `toggle_chamada`, `limpar`, reordenação e saída não modificam estado por GET | `tests/security/endpoint_security_policy.test.js` | Validar clientes antigos/PWA |
| SEC-005 | Alto | Validação | IMPLEMENTADO | `src/Security/Input.php`, `src/Http/Request.php` e integrações nos endpoints | Allowlist, tipo, tamanho, faixa e IDs são validados antes de uso | Testes de fundação e política estática | Ampliar testes por rota |
| SEC-006 | Alto | Erros públicos | IMPLEMENTADO | `src/Http/JsonResponse.php`, endpoints PHP | Respostas não expõem SQL, stack trace, exception, `debug` ou valores sensíveis | `tests/security/endpoint_security_policy.test.js` | Testar falhas reais de MySQL |
| SEC-007 | Alto | Rate limiting | IMPLEMENTADO | `src/Security/RateLimiter.php`, `.env.example`, endpoints protegidos | Estado com lock, nome HMAC, storage absoluto fora do webroot e limites por operação | `tests/security/rate_limiter_test.php` passou durante a integração | Para múltiplas instâncias, trocar por storage centralizado |
| SEC-008 | Alto | CSRF público | PARCIAL | `src/Security/PublicCsrf.php`, `session_info.php`, `checkin.php`, `relogin.php`, `DaVez/entrar.php` | Requisição pública possui contexto same-origin; identidade deixa de depender de token coletivo/client ID | Teste de contexto público existe | Substituir pelo modelo de identidade pública v2 |
| SEC-009 | Alto | Higiene de secrets | IMPLEMENTADO | `tests/security/secret_hygiene_policy.test.js` | CI falha para private keys, tokens reconhecíveis, URLs de banco credenciadas e assignments literais suspeitos | `node --check` e execução passaram sobre os arquivos públicos permitidos | Manter o teste no workflow remoto |
| SEC-010 | Alto | Endpoints de diagnóstico | IMPLEMENTADO | `tests/security/production_artifact_policy.test.js` | Endpoints de diagnóstico removidos não retornam ao artefato | Política estática disponível e executada durante a remediação | Manter allowlist de release |
| SEC-011 | Médio | Headers/CSP | PARCIAL | `.htaccess`, `src/Http/JsonResponse.php`, `docs/SECURITY_IMPLEMENTATION.md` | CSP, frame protection, referrer policy e headers coerentes em todas as respostas e no servidor real | Política local disponível; browser/header scan no servidor real não executado | Definir CSP compatível e configurar equivalente para Nginx/IIS quando aplicável |
| SEC-012 | Crítico | Credenciais externas | NÃO VALIDADO | Apenas nomes vazios em `.env.example` e `config.example.php` | Credenciais administrativas e de banco comprometidas/legadas são rotacionadas fora do repositório | Não há acesso autorizado aos secrets reais | Proprietário/infraestrutura |

## Fase 2 — identidade pública v2

Documento de arquitetura: `docs/PUBLIC_IDENTITY_V2.md`.

**[Certeza]** A identidade pública v2 está planejada e não implementada.

| ID | Severidade | Camada | Status | Evidência segura | Critério de aceite | Teste/validação | Dependências |
|---|---|---|---|---|---|---|---|
| PID2-001 | Crítico | Admissão | BLOQUEADO POR DECISÃO | `docs/PUBLIC_IDENTITY_V2.md` | Token coletivo substituído por ticket individual, descartável e de curta duração | Testes unitários e concorrentes de consumo único | DEC-PID2-03, 04, 07 e 10 |
| PID2-002 | Crítico | Sessão pública | BLOQUEADO POR DECISÃO | Proposta de `public_sessions` no documento v2 | Token aleatório fica apenas em cookie `HttpOnly`; banco guarda hash; sessão vincula `checkin_id` | Unidade, integração e E2E de rotação/revogação | DEC-PID2-02, 06 e 09 |
| PID2-003 | Crítico | Recuperação | BLOQUEADO POR DECISÃO | Fluxo de recovery em `docs/PUBLIC_IDENTITY_V2.md` | Lookup por nome é desativado; recuperação exige ticket individual vinculado ao check-in | `relogin.php` retorna 410 no legado; recovery revoga sessão anterior | DEC-PID2-01 e 02 |
| PID2-004 | Alto | Frontend | PLANEJADO | Impacto sobre `index.html` documentado | Token e identidade deixam o `localStorage`; `client_id` não é gerado/enviado | Política estática e E2E em dispositivo novo/reload | Backend v2 e corte coordenado da PWA |
| PID2-005 | Alto | Fila | PLANEJADO | Evolução proposta para `fila_da_vez.checkin_id` | Fila deriva a identidade da sessão; não aceita nome/token/client ID do request | Integração MySQL e concorrência | Migrations aditivas e PID2-002 |
| PID2-006 | Alto | Privacidade | BLOQUEADO POR DECISÃO | Proposta para `DaVez/listar.php` | Resposta pública não contém `client_id` ou IDs internos; nomes seguem política aprovada | Teste de contrato da resposta pública | DEC-PID2-05 e 08 |
| PID2-007 | Alto | Migração | BLOQUEADO POR DECISÃO | Plano de corte em `docs/PUBLIC_IDENTITY_V2.md` | Corte versionado no início do ciclo ou transição dual com prazo explícito | Ensaio de migration, rollback e PWA antiga | DEC-PID2-04 e 09 |
| PID2-008 | Médio | Revogação | BLOQUEADO POR DECISÃO | Política proposta de tickets/sessões | Logout, recovery, limpeza de ciclo e abuso revogam no servidor | Testes de expiração e revogação | DEC-PID2-01, 02, 06 e 07 |

### Decisões bloqueantes da identidade

| ID | Status | Decisão do proprietário | Recomendação padrão | Critério para encerrar |
|---|---|---|---|---|
| DEC-PID2-01 | BLOQUEADO POR DECISÃO | Recuperação será apenas administrativa? | Sim, presencial, até existir conta/OTP/passkey | Decisão registrada com responsável operacional |
| DEC-PID2-02 | BLOQUEADO POR DECISÃO | Quantos dispositivos ativos por check-in? | Um; recovery revoga o anterior | Política de sessão aprovada |
| DEC-PID2-03 | BLOQUEADO POR DECISÃO | Ticket digitável ou QR individual? | QR individual; código individual como fallback | Processo de emissão/distribuição testado |
| DEC-PID2-04 | BLOQUEADO POR DECISÃO | Corte de ciclo ou transição dual? | Corte no início de um ciclo | Janela e rollback aprovados |
| DEC-PID2-05 | BLOQUEADO POR DECISÃO | Quais nomes podem aparecer publicamente? | Apenas próximo chamado e visão `me` | Política de privacidade aprovada |
| DEC-PID2-06 | BLOQUEADO POR DECISÃO | Validade da sessão? | Até o fim do ciclo; máximo de 24 horas | Timeout documentado |
| DEC-PID2-07 | BLOQUEADO POR DECISÃO | Validade do ticket? | 10 minutos e uso único | TTL e limites aprovados |
| DEC-PID2-08 | BLOQUEADO POR DECISÃO | IP/User-Agent continuarão armazenados? | Remover, salvo justificativa e retenção | Base legal/finalidade/retention documentadas |
| DEC-PID2-09 | BLOQUEADO POR DECISÃO | HTTPS está garantido? | Tornar obrigatório | Certificado, proxy e cookies validados |
| DEC-PID2-10 | BLOQUEADO POR DECISÃO | A operação suporta ticket individual? | Validar antes do desenvolvimento | Piloto operacional aprovado |

## Fase 3 — banco, migrations, domínio e concorrência

| ID | Severidade | Camada | Status | Evidência segura | Critério de aceite | Teste/validação | Dependências |
|---|---|---|---|---|---|---|---|
| DB-001 | Alto | Schema | PARCIAL | `database/schema.sql`, migrations `001` a `004` | Banco novo é criado de forma reproduzível e compatível com a aplicação | `tests/database/schema_contract_test.php`; não aplicado a MySQL real | Preflight, backup e ambiente MySQL |
| DB-002 | Alto | Migrations | PARCIAL | `database/migrations/001_create_settings.sql` a `004_create_reports.sql`, `docs/DATABASE_OPERATIONS.md` | Migrations executadas em banco vazio e legado, com rollback/restore ensaiados | Contrato estático disponível; execução real não validada | Dump anonimizado e autorização |
| DB-003 | Alto | DDL em runtime | IMPLEMENTADO | `tests/database/runtime_data_policy_test.php`, `src/Database/` | Nenhum endpoint HTTP executa `CREATE`, `ALTER`, `SHOW TABLES` ou mudança de schema | Teste de política e busca estática passaram; MySQL real ainda não validado | Manter migrations fora do runtime |
| DB-004 | Alto | Ciclo operacional | IMPLEMENTADO | `src/Domain/OperationalCycle.php`, `OperationalContext.php`, `tests/domain/` e endpoints integrados | Todas as rotas usam uma única regra `[06:00, 06:00)` e timezone definido | Testes unitários e política estática passaram | Validar timezone no MySQL real |
| DB-005 | Alto | Ciclo de token | PARCIAL | `src/Domain/TokenCycle.php`, `src/Database/SettingsTokenCycle.php`, `tests/database/settings_token_cycle_cas_policy_test.php` | Regra centralizada, rotação concorrente por compare-and-set e, na v2, token coletivo removido | Domínio e contrato CAS passaram; concorrência MySQL não validada | MySQL real e identidade v2 |
| DB-006 | Alto | Geofence | PARCIAL | `src/Domain/Geofence.php`, `tests/domain/geofence_test.php` | Check-in e fila usam a mesma regra de distância e faixas válidas | Teste unitário disponível; localização real não validada | Integração de endpoints e E2E |
| DB-007 | Crítico | Concorrência | PARCIAL | `AtomicOrderAllocator.php`, `AdvisoryLock.php`, `LockedTransactionRunner.php`, interfaces MySQLi, teste de alocação | Check-ins simultâneos nunca recebem a mesma ordem; falha de lock não segue sem proteção | Teste unitário disponível; concorrência em MySQL real não validada | Banco InnoDB e teste paralelo |
| DB-007A | Alto | Lock entre filas | PLANEJADO | `admin.php`, ação `toggle_close`; estratégia canônica em `src/Database/LockedTransactionRunner.php` | Ao alterar `checkins` e remover de `fila_da_vez` na mesma transação, adquirir todos os locks necessários em ordem canônica, incluindo `fila_da_vez:<dia>`, sem deadlock ou janela de corrida | Teste concorrente MySQL deve executar `toggle_close`, entrada e reordenação em paralelo e comprovar ordem/estado consistentes | Definir estratégia de múltiplos locks, ordem global e timeout; MySQL/InnoDB |
| DB-008 | Alto | Reordenação | PARCIAL | `src/Domain/QueueReorder.php`, `tests/domain/queue_reorder_test.php`, `tests/database/queue_exit_compaction_policy_test.php` | IDs válidos, únicos e pertencentes ao ciclo são atualizados atomicamente; saída recompõe posições `1..N` | Unidade e contratos estáticos passaram; integração MySQL não validada | Transação real |
| DB-009 | Médio | Relatórios | PARCIAL | `src/Domain/ReportSnapshot.php`, `tests/domain/report_snapshot_test.php` | Snapshot consistente, JSON válido e limpeza somente após relatório persistido | Unidade disponível; transação real não validada | MySQL e política de retenção |
| DB-010 | Crítico | MySQL real | NÃO VALIDADO | Não há acesso a banco representativo nesta consolidação | Schema, migrations, queries, charset, timezone, índices e rollback passam em banco vazio e legado anonimizado | Suite de integração MySQL | Ambiente e credenciais de teste |

## Fase 4 — logs, storage e webroot

| ID | Severidade | Camada | Status | Evidência segura | Critério de aceite | Teste/validação | Dependências |
|---|---|---|---|---|---|---|---|
| LOG-001 | Alto | Logging | IMPLEMENTADO | `log.php`, `tests/security/log_event_test.php`, `logging_policy.test.js` | Tokens, nomes, coordenadas, IDs, IP/UA e erros SQL não são persistidos; métricas permitidas permanecem | Teste de integração e política passaram durante a remediação | Manter allowlist |
| LOG-002 | Alto | Histórico | PARCIAL | Regra de preservação privada registrada; conteúdo não lido | Log/relatório histórico permanece fora do webroot e do Git, com acesso e retenção definidos | Local privado deliberadamente não inspecionado | Proprietário define retenção/descarte |
| LOG-002A | Alto | Privacidade de dados | PARCIAL | `checkin.php`, colunas `checkins.ip` e `checkins.user_agent` em `database/schema.sql` | IP e User-Agent deixam de ser persistidos ou possuem finalidade, base legal, acesso e retenção mínima formalmente aprovados | Teste de integração MySQL deve comprovar a política escolhida; dados reais não foram inspecionados | DEC-PID2-08 e política de retenção |
| LOG-003 | Alto | Caminho de runtime | IMPLEMENTADO | `APP_LOG_PATH` documentado e teste de logger | Produção aponta para diretório privado absoluto | Não validado no servidor real | Configuração externa e permissões |
| LOG-004 | Alto | Exposição | PARCIAL | `tests/security/storage_exposure_policy.test.php`, `.gitignore` | Logs, relatórios, rate limit e backups não são servidos por HTTP | Política estática disponível; servidor real não validado | Configuração do webserver |
| LOG-005 | Alto | Webroot | PLANEJADO | Arquitetura alvo em `docs/ARCHITECTURE.md` | Único webroot é `public/`; `src`, config, storage, docs e testes ficam fora | Teste do artefato e varredura HTTP | Refatoração incremental e deploy |
| LOG-006 | Médio | Retenção | PLANEJADO | `docs/OPERATIONS.md` | Rotação por tempo/tamanho, retenção, descarte e alerta de disco definidos | Teste operacional e simulação de volume | Política do proprietário |

## Fase 5 — PWA e interface pública

| ID | Severidade | Camada | Status | Evidência segura | Critério de aceite | Teste/validação | Dependências |
|---|---|---|---|---|---|---|---|
| PWA-001 | Alto | Cache | IMPLEMENTADO | `service-worker.js`, `tests/security/service_worker_cache_policy.test.js` | Cache contém somente assets públicos; PHP, admin, APIs, logs e relatórios ficam fora | Política automatizada passou durante a remediação | Manter versão do cache |
| PWA-002 | Médio | Atualização | PARCIAL | `service-worker.js`, controles de atualização em `index.html` | Dispositivos antigos recebem atualização e não continuam em protocolo incompatível | Navegador local foi usado em lote anterior; dispositivos reais atuais não validados | Corte coordenado com identidade v2 |
| PWA-003 | Médio | Manifest/icons | PARCIAL | `manifest.json`, `icons/` | Nome, cores, dimensões e ícones `any`/`maskable` são válidos | JSON validado; dimensões/maskable finais não validados | QA PWA |
| UI-PUB-001 | Médio | Acessibilidade | PARCIAL | `index.html`, `tests/frontend/public_interface_accessibility.test.js` | Labels, foco, contraste, `aria-live`, teclado e reduced motion passam WCAG aplicável | Teste estático disponível; auditoria axe/manual não validada | Browser e dispositivos |
| UI-PUB-002 | Médio | Responsividade | PARCIAL | `index.html` | Fluxos principais funcionam em 360, 390, 768, 1024 e 1440 px | QA em navegador passou em 360×800 e 1440×900 no lote público; demais dimensões e dispositivos reais não validados | Browser/device lab |
| UI-PUB-003 | Médio | Estados | PARCIAL | `index.html` possui estados de conectividade/atualização | Loading, erro, vazio, offline, localização negada e sessão expirada são claros | E2E/manual não validado | Backend real e identidade v2 |
| UI-PUB-004 | Alto | Privacidade | BLOQUEADO POR DECISÃO | `docs/PUBLIC_IDENTITY_V2.md` | Frontend não guarda token/identidade e não exibe IDs | Política estática planejada | DEC-PID2-05 e implementação v2 |

## Fase 6 — painel administrativo

| ID | Severidade | Camada | Status | Evidência segura | Critério de aceite | Teste/validação | Dependências |
|---|---|---|---|---|---|---|---|
| UI-ADM-001 | Alto | Segurança | IMPLEMENTADO | `admin.php`, fundação em `src/Security/` | Login, logout, CSRF, métodos e rate limiting protegem as ações | Políticas de segurança disponíveis | E2E autenticado |
| UI-ADM-002 | Médio | Design | IMPLEMENTADO | `admin.php`, `tests/frontend/admin_interface_contract.test.js` | Design Soft Structuralism operacional, light/dark, responsivo e sem quebrar IDs/contratos | Contrato estático e lint passaram; comparação visual autenticada não executada | QA visual integrado |
| UI-ADM-003 | Médio | Acessibilidade | PARCIAL | `admin.php`, `tests/frontend/admin_interface_contract.test.js` | Abas, diálogo, toasts e reordenação funcionam por teclado e leitor de tela | Semântica, fallback Subir/Descer, foco e reduced motion cobertos estaticamente; axe/screen reader não executados | Browser autenticado |
| UI-ADM-004 | Médio | Responsividade | NÃO VALIDADO | `admin.php` | Sem overflow e ações utilizáveis em mobile/tablet/desktop | Matriz 360–1440 px | Browser real |
| UI-ADM-005 | Médio | Feedback | PARCIAL | `fetchJsonAdmin`, estados de interface, toast e diálogo em `admin.php` | Nenhum sucesso é exibido quando HTTP falha; 401 redireciona com mensagem; estados loading/vazio/erro são explícitos | Contrato estático passou; E2E de falhas não executado | Backend/MySQL |
| UI-ADM-006 | Médio | Modularização | PLANEJADO | `admin.php` concentra PHP, HTML, CSS e JS | Extrair módulos sem refatoração simultânea de regra de negócio | Testes de caracterização antes/depois | Cobertura E2E |

## Fase 7 — CI, release e operação

| ID | Severidade | Camada | Status | Evidência segura | Critério de aceite | Teste/validação | Dependências |
|---|---|---|---|---|---|---|---|
| CI-001 | Alto | CI | IMPLEMENTADO | `.github/workflows/ci.yml`, `tests/operations/ci_read_only_contract_test.php` | Workflow executa lint e testes sem ler secrets/dados ou alterar ambiente | Contrato local disponível; execução remota não comprovada | Push/PR e GitHub Actions habilitado |
| CI-002 | Médio | Validação local | IMPLEMENTADO | `scripts/validate.ps1` | Um comando executa lint e suítes relevantes, falhando cedo | Execução combinada final passou com PHP 8.5.9, 18 testes PHP, 8 testes Node, manifesto e `git diff --check` | Repetir no commit candidato e no CI remoto |
| REL-001 | Alto | Artefato | IMPLEMENTADO | `scripts/build-release.ps1`, `tests/operations/release_allowlist_contract_test.php`, `production_artifact_policy.test.js` | Release usa allowlist e exclui config real, env, logs, relatórios, docs privados e testes indevidos | Contratos locais disponíveis | Inspeção do artefato gerado |
| REL-002 | Alto | PR checks | NÃO VALIDADO | PR #1 remoto não draft, sem checks no estado observado | Branch protection exige CI verde antes de merge | Reconsulta do GitHub necessária | Configuração do repositório |
| OPS-001 | Médio | Deploy | PARCIAL | `docs/DEPLOYMENT.md` | Deploy possui preflight, artefato, ordem, smoke test e rollback | Procedimento documentado; não executado | Staging |
| OPS-002 | Alto | Ambientes | PLANEJADO | `.env.example`, docs operacionais | Local, staging e produção possuem bancos, secrets e storage separados | Verificação de configuração sem imprimir valores | Infraestrutura |
| OPS-003 | Crítico | Backup | PARCIAL | `docs/BACKUP_RESTORE.md` | Backup consistente é gerado antes de migration/deploy | Documento existe; backup real não executado nesta sessão | Banco real e storage seguro |
| OPS-004 | Crítico | Restore | NÃO VALIDADO | `docs/BACKUP_RESTORE.md` | Restore completo é executado e medido em ambiente isolado | Teste real obrigatório | Backup válido e MySQL de teste |
| OPS-005 | Alto | Rollback | NÃO VALIDADO | `docs/DEPLOYMENT.md`, `docs/DATABASE_OPERATIONS.md` | Aplicação e banco retornam à versão anterior sem perda não planejada | Ensaio de rollback | Staging e backup |
| OPS-006 | Alto | Monitoramento | PLANEJADO | `docs/OPERATIONS.md` | Alertas cobrem 5xx, falha DB, lock/concorrência, disco, sessão, rate limit e PWA | Simulação de alertas | Plataforma de observabilidade |
| OPS-007 | Médio | Custos/capacidade | NÃO VALIDADO | Nenhuma telemetria real disponível | Limites, armazenamento, tráfego e crescimento são medidos | Teste de carga e métricas | Ambiente representativo |

## Fase 8 — testes e validação final

| ID | Severidade | Camada | Status | Evidência segura | Critério de aceite | Teste/validação | Dependências |
|---|---|---|---|---|---|---|---|
| TST-001 | Alto | Sintaxe | IMPLEMENTADO | PHP 8.5.9 local, arquivos PHP e testes | Todo PHP rastreável passa `php -l` | Execução combinada final passou | Repetir no commit candidato |
| TST-002 | Alto | Segurança | PARCIAL | `tests/security/` | Todas as políticas e testes dinâmicos passam no commit candidato | Execução combinada final local passou; E2E e CI remoto não validados | Commit candidato e CI remoto |
| TST-003 | Médio | Domínio | PARCIAL | `tests/domain/` | Ciclo, token, geofence, fila e relatório possuem cobertura suficiente | Todos os testes de domínio passaram na execução combinada final; revisão de cobertura ainda necessária | Revisão de cobertura |
| TST-004 | Alto | Banco | PARCIAL | `tests/database/` | Contratos, runtime DDL, CAS, compactação e alocação atômica passam | Todos os testes sem banco passaram na execução combinada final | MySQL real para integração |
| TST-005 | Crítico | MySQL | NÃO VALIDADO | Nenhum banco real foi acessado nesta consolidação | Suite cria banco vazio, migra legado anonimizado e executa fluxos críticos | Ambiente MySQL isolado | Credenciais de teste e autorização |
| TST-006 | Crítico | Concorrência real | NÃO VALIDADO | Fundação em `src/Database/` | Requisições paralelas não duplicam ordem e falham com segurança sem lock | Teste paralelo com múltiplas conexões MySQL | MySQL/InnoDB |
| TST-007 | Crítico | E2E público | NÃO VALIDADO | Frontend e endpoints existem | Check-in, reload, re-login/410 futuro, fila, localização, erro e expiração funcionam | Playwright/browser real | MySQL, HTTPS e identidade definida |
| TST-008 | Crítico | E2E admin | NÃO VALIDADO | Login e painel implementados | Login, CSRF, sessão expirada, toggle, limpeza, relatório, reorder e logout passam | Browser autenticado + MySQL | Ambiente integrado |
| TST-009 | Alto | Dispositivos/PWA | NÃO VALIDADO | PWA implementada | Android/iOS, rede lenta, offline, atualização e cache antigo funcionam | Matriz de dispositivos | HTTPS e build candidato |
| TST-010 | Crítico | Backup/restore | NÃO VALIDADO | Documentação disponível | Restore produz aplicação funcional e dados consistentes | Ensaio completo | Banco e storage isolados |

## Gate de staging

| ID | Status | Requisito para liberar |
|---|---|---|
| STG-001 | BLOQUEADO POR DECISÃO | DEC-PID2-01 a DEC-PID2-10 resolvidas ou risco legado formalmente aceito com prazo |
| STG-002 | NÃO VALIDADO | MySQL isolado com migrations, seed sintético e integração verde |
| STG-003 | NÃO VALIDADO | HTTPS, secrets, storage privado e permissões verificados |
| STG-004 | NÃO VALIDADO | Backup e restore executados |
| STG-005 | NÃO VALIDADO | E2E público e administrativo verde |
| STG-006 | NÃO VALIDADO | CI remoto obrigatório e checks verdes |
| STG-007 | PLANEJADO | Monitoramento e rollback configurados |

**Conclusão de staging:** não liberado.

## Gate de produção

| ID | Status | Requisito para liberar |
|---|---|---|
| PRD-001 | NÃO VALIDADO | Todos os gates de staging concluídos |
| PRD-002 | BLOQUEADO POR DECISÃO | Identidade pública v2 e política de privacidade aprovadas |
| PRD-003 | NÃO VALIDADO | Teste de carga, concorrência e capacidade |
| PRD-004 | NÃO VALIDADO | Backup automático e restore periódico |
| PRD-005 | PLANEJADO | Alertas, logs sanitizados e resposta a incidente |
| PRD-006 | NÃO VALIDADO | Deploy e rollback ensaiados em staging |
| PRD-007 | BLOQUEADO POR DECISÃO | Responsáveis operacionais, retenção e suporte definidos |

**Conclusão de produção:** não liberado.

## Ordem recomendada dos próximos lotes

1. estabilizar o estado combinado e executar `scripts/validate.ps1`;
2. revisar e ativar CI remoto obrigatório;
3. decidir DEC-PID2-01 a DEC-PID2-10;
4. implementar identidade pública v2 em lote isolado;
5. preparar MySQL isolado e validar migrations;
6. testar concorrência real e fluxos transacionais;
7. executar E2E público e administrativo;
8. corrigir webroot/storage e headers;
9. concluir QA da PWA e interface pública;
10. executar QA visual e de acessibilidade do painel autenticado;
11. executar backup, restore, deploy e rollback em staging;
12. configurar monitoramento;
13. reavaliar formalmente readiness de produção.

## Comandos de validação previstos

Executar somente no ambiente autorizado:

```powershell
pwsh -File scripts/validate.ps1
node tests/security/secret_hygiene_policy.test.js
node tests/security/endpoint_security_policy.test.js
node tests/security/service_worker_cache_policy.test.js
node tests/frontend/public_interface_accessibility.test.js
node tests/frontend/admin_interface_contract.test.js
php tests/security/security_foundation_test.php
php tests/database/schema_contract_test.php
php tests/database/settings_token_cycle_cas_policy_test.php
php tests/database/queue_exit_compaction_policy_test.php
php tests/database/runtime_data_policy_test.php
```

Esses comandos locais não substituem MySQL real, E2E, restore, staging ou
validação operacional.
