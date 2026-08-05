# Revisão de segurança — DaVez 1.2.0 RC2

## Método

Foi executada uma revisão padrão de repositório em modo terminal, seguindo o
fluxo do skill Codex Security `security-scan`. O launcher app-backed do Codex
Security não estava disponível neste ambiente; portanto não existe `scanId` nem
medição de tokens do scanner.

Cobertura:

- inventário de 167 arquivos no snapshot RC2;
- lint de todos os arquivos PHP;
- execução de todos os testes PHP e Node;
- busca por segredos, chaves privadas e credenciais;
- inspeção de consultas SQL, sinks de HTML/DOM, filesystem e processos;
- revisão de autenticação, sessão, CSRF, rate limiting e cookies;
- revisão dos endpoints públicos, administrativos e de PDF;
- checksum dos arquivos do snapshot;
- validação final: 78 arquivos PHP em lint, 25 testes PHP e 13 testes Node.

Bibliotecas minificadas de QR Code e Sortable foram contabilizadas por versão,
licença e checksum, mas não passaram por revisão semântica linha a linha.
Imagens PNG foram verificadas como artefatos binários, não como código.

## Threat model

O modelo está em `docs/SECURITY_THREAT_MODEL_RC2.md`.

Ativos principais:

- conta e sessão administrativa;
- identidade pública, códigos diários e sessões;
- ordem da fila e eventos de entrega;
- relatórios e PDFs;
- configuração de geofence;
- segredos de HMAC, rate limiting e banco.

## Candidatos e decisões

### C-01 — PDF sem controles de acesso ou limites

**Decisão:** corrigido.

Os três endpoints exigem autenticação administrativa, método GET, allowlist de
parâmetros, rate limit, limites de volume e headers `no-store`, `nosniff` e
`Cross-Origin-Resource-Policy: same-origin`.

### C-02 — Exportações pesadas sem limite

**Decisão:** corrigido.

O ranking limita a 500 classificados e intervalo de 366 dias. O índice de
relatórios limita a 1.000 registros e exige intervalo coerente.

### C-03 — SQL injection na paginação ou filtros

**Decisão:** rejeitado após validação.

Datas, limite e offset são vinculados em prepared statements. Os placeholders
dinâmicos da cláusula `IN` são gerados pela aplicação, e os nomes permanecem
valores vinculados.

### C-04 — XSS nas novas tabelas e indicadores

**Decisão:** rejeitado após validação.

Valores vindos do backend passam por `escapeHtml` antes de serem inseridos em
strings HTML. O indicador de relógio também escapa conteúdo dinâmico.

### C-05 — Segredos no pacote

**Decisão:** rejeitado após varredura.

Foi encontrado somente um valor sintético em teste automatizado. Não foram
encontradas chaves privadas, tokens de provedor ou credenciais reais.

### C-06 — Mistura de empresas

**Decisão:** diferido; bloqueador arquitetural para SaaS.

O schema atual é monoempresa. Ele não deve ser apresentado como multi-tenant.
Antes de cadastrar empresas diferentes, todas as tabelas de negócio precisam de
contexto de tenant/loja, backfill, constraints e testes de acesso cruzado.

### C-07 — Ranking identifica pessoa pelo nome

**Decisão:** diferido.

Duas pessoas com o mesmo nome podem ser agregadas no mesmo ranking. A solução
correta é `driver_id` permanente, integrada à futura fundação multi-tenant.

### C-08 — Rate limiter baseado em arquivos

**Decisão:** aceito para uma instância; diferido para escala horizontal.

O armazenamento privado local é adequado ao deployment atual de instância
única. Várias instâncias exigem controle centralizado, como Redis.

### C-09 — Content Security Policy

**Decisão:** hardening diferido.

O painel ainda possui CSS e JavaScript inline. Uma CSP restritiva deve começar
em `Content-Security-Policy-Report-Only`, seguida de extração progressiva dos
assets e adoção de nonce/hash.

## Análise compacta de caminhos de ataque

### A-01 — visitante tenta exportar dados administrativos

`ranking_pdf.php`, `reports_pdf.php` e `report_pdf.php` chamam
`davez_require_admin()` antes de consultar o banco. Uma sessão ausente ou
expirada encerra o fluxo antes do sink PDF.

### A-02 — usuário autenticado manipula datas para alterar SQL

O input passa por formato estrito de data e limites de intervalo. As datas são
vinculadas; não entram na estrutura SQL. Limite e offset também são inteiros
validados e vinculados.

### A-03 — nome persistido contém HTML/JavaScript

Os nomes chegam ao frontend como JSON e são inseridos somente após
`escapeHtml`. No PDF, o texto é codificado e os caracteres de sintaxe PDF são
escapados antes do stream.

### A-04 — arquivo ou URL arbitrária no endpoint de download

Os endpoints aceitam apenas ID ou filtros conhecidos e geram o documento em
memória. Não existe `readfile()` ou caminho recebido do cliente.

### A-05 — futura Empresa A solicita objeto da Empresa B

O snapshot atual não possui tenant. A proteção necessária ainda não existe e,
por isso, multiempresa está explicitamente bloqueada. A fase futura deve derivar
tenant/loja da sessão, negar por padrão e testar IDs cruzados em toda rota.

## Invariantes validados estaticamente

- mutações administrativas exigem CSRF;
- endpoints administrativos exigem sessão;
- consultas novas usam prepared statements;
- downloads não recebem caminhos de arquivo do cliente;
- respostas PDF não usam cache compartilhado;
- dados de interface são escapados;
- release não inclui `config.php`, `.env`, logs, relatórios ou testes;
- o código diário bruto não é persistido.

## Lacunas

Não validado neste ambiente:

- MySQL/Percona real;
- plano de execução e índices com volume representativo;
- concorrência e deadlocks;
- Nginx/PHP-FPM;
- E2E autenticado;
- pentest dinâmico;
- restauração de backup.

**Conclusão:** nenhum novo achado crítico ou alto permaneceu reportável no
escopo implementado. Os principais riscos restantes são arquiteturais e de
validação integrada, não uma autorização para produção automática.
