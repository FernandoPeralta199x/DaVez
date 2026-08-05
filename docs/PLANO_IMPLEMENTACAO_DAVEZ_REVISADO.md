# Plano de Implementação Full Stack - DaVez

**Versão revisada, auditada e alinhada ao repositório**  
**Data da revisão:** 04/08/2026  
**Repositório analisado:** `FernandoPeralta199x/DaVez`  
**Commit de referência:** `8bc1c10391babc4c49c93eb415dcc5b1c2d50a70`  
**Tipo de análise:** revisão estática do texto, do código e da documentação; não houve execução do banco, testes E2E ou carga real.

> **Veredito executivo:** as funcionalidades solicitadas são tecnicamente viáveis, mas o DaVez atual ainda é uma aplicação monoempresa. A implementação completa depende primeiro de uma fundação multi-tenant, usuários persistidos no banco, autorização por tenant/loja e migrações seguras. Não é correto iniciar pelas telas de gerenciamento de empresas.

---

## Sumário

1. Correções aplicadas nesta versão
2. Resultado da varredura do texto
3. Resultado da análise do GitHub
4. Validação com referências técnicas oficiais
5. Arquitetura alvo
6. Navegação do sistema
7. Ranking por data
8. Relatórios, paginação e PDF
9. Data, hora e fuso
10. Expiração dos códigos às 01:30
11. Multiempresa e multissessão
12. Perfis de acesso - 3 tipos
13. Cadastro e gerenciamento de empresas
14. Pausa, ativação, contrato e teste
15. Exclusão segura
16. Banco de dados e isolamento
17. APIs sugeridas
18. Logs e auditoria
19. Robustez, disponibilidade e infraestrutura
20. Estratégia de implementação por fases
21. Testes obrigatórios
22. Critérios de aceite
23. Riscos e decisões pendentes
24. Referências técnicas

---

# 1. Correções aplicadas nesta versão

## 1.1 Perfis de acesso corrigidos

O documento anterior listava seis perfis. A definição foi corrigida para **três tipos de acesso**:

| Perfil | Escopo | Resumo |
|---|---|---|
| `SUPER_ADMIN` | Plataforma inteira | Todas as empresas, configurações globais, contratos, logs e gerenciamento de contas |
| `ADMIN_EMPRESA` | Uma única empresa/loja autorizada | Opera somente o ambiente da própria empresa, conforme as restrições do contrato e das permissões |
| `ENTREGADOR` | Operação do motoboy | Check-in, fila, recuperação de acesso e funções operacionais permitidas |

Foram removidos da especificação final os perfis independentes `SUPORTE`, `OPERADOR` e `CLIENTE`. As funções de suporte global ficam com `SUPER_ADMIN`; a conta da empresa é representada por `ADMIN_EMPRESA`.

## 1.2 Credenciais administrativas

Usuários globais iniciais:

- `FernandoPeralta` - `SUPER_ADMIN`
- `RobertMoura` - `SUPER_ADMIN`

As senhas compartilhadas na conversa **não são reproduzidas neste documento**. Elas devem ser consideradas expostas, substituídas antes do uso real, armazenadas somente como hash e configuradas fora do Git.

Requisitos:

- senha temporária somente no bootstrap;
- troca obrigatória no primeiro acesso;
- hash `Argon2id` quando disponível, com `bcrypt` apenas como fallback compatível;
- MFA para `SUPER_ADMIN`;
- bloqueio temporário após tentativas inválidas;
- revogação de sessões após redefinição de senha;
- nenhum segredo em migration, seed público, commit ou arquivo `.env.example` preenchido.

## 1.3 Terminologia padronizada

- “cliente” no cadastro administrativo passa a ser **empresa**;
- “conta cliente” passa a ser **ADMIN_EMPRESA**;
- “loja” representa a unidade operacional da empresa;
- “motoboy” e “entregador” são representados por uma entidade permanente `driver`;
- cada empresa possui um `tenant_id`;
- cada unidade possui um `store_id`.

## 1.4 Data e hora

A ideia de confiar na data/hora do computador foi corrigida. O relógio do dispositivo não deve ser fonte oficial. O backend deve usar horário confiável, armazenar em UTC e converter para o fuso da empresa.

## 1.5 Disponibilidade

A expressão “100% sem erro ou queda” foi substituída por uma meta mensurável. Meta inicial recomendada:

```text
SLO de disponibilidade: 99,9% ao mês
```

A disponibilidade só poderá ser prometida após teste de carga, monitoramento, backup, restore e validação da infraestrutura real.

---

# 2. Resultado da varredura do texto

## 2.1 Pontos consistentes

O plano está correto ao priorizar:

- isolamento entre empresas;
- paginação no backend;
- geração de PDF controlada pelo servidor;
- logs por empresa;
- pausa e ativação de contas;
- contratos e períodos de teste;
- proteção de credenciais;
- backup e rollback antes de alterações críticas;
- implementação em fases sem pular etapas;
- testes de segurança, integração, carga e recuperação.

## 2.2 Inconsistências corrigidas

### Formato de data

O texto solicitava “mês/dia/ano”, mas o público principal está no Brasil. Para reduzir erro operacional:

- interface: `DD/MM/AAAA`;
- API e banco: `AAAA-MM-DD` ou ISO 8601;
- aceitar digitação manual somente com máscara e validação inequívoca.

Caso o produto precise operar em outros países, o formato visual deverá seguir a localidade da empresa.

### Capacidade

O requisito “1.000 ou mais entregadores de cada empresa” combinado com “1.000 ou mais empresas” pode representar:

```text
1.000 empresas x 1.000 entregadores = 1.000.000 de entregadores cadastrados
```

Isso é diferente de 1.000 entregadores simultâneos. O planejamento deve separar:

- total cadastrado;
- ativos por dia;
- simultâneos;
- requisições por segundo;
- PDFs simultâneos;
- volume mensal de logs;
- tamanho dos relatórios.

### Multissessão

O texto mistura dois conceitos:

1. várias empresas e usuários usando o sistema simultaneamente;
2. o mesmo usuário usando vários dispositivos.

As duas situações devem ser tratadas separadamente, com política configurável de sessões por perfil.

### Exclusão de empresa

A exclusão física imediata não é adequada para contratos, auditoria e suporte. O botão será mantido, mas com fluxo de arquivamento, soft delete, retenção e exclusão definitiva controlada.

---

# 3. Resultado da análise do GitHub

## 3.1 Estado atual do projeto

O repositório declara que o DaVez é um MVP em remediação incremental e que ainda não deve ser usado em produção. A documentação informa que MySQL real, concorrência, HTTPS, E2E, backup/restore, deploy e rollback ainda precisam de validação representativa.

Stack identificada:

- PHP procedural;
- MySQL/MariaDB por MySQLi;
- HTML, CSS e JavaScript sem framework principal;
- PWA com Service Worker;
- migrations SQL versionadas;
- testes PHP e Node;
- GitHub Actions configurado;
- armazenamento privado para logs e rate limiting.

## 3.2 Pontos positivos existentes

A base atual já contém controles relevantes:

- sessão PHP com `use_only_cookies`, `strict_mode`, `HttpOnly`, `SameSite` e suporte a cookie `Secure`;
- CSRF para mutações administrativas;
- autenticação por hash;
- rate limiting;
- validação de entrada;
- prepared statements em fluxos críticos;
- transações e advisory locks para concorrência da fila;
- logs sanitizados fora do webroot;
- migrations separadas do runtime;
- código diário guardado como HMAC, não em texto puro;
- testes de políticas de segurança e build de release;
- guia de deploy e backup.

## 3.3 Bloqueadores para o plano solicitado

### Banco monoempresa

O schema atual possui:

- `settings` singleton com `id = 1`;
- `checkins` sem `tenant_id` e `store_id`;
- `fila_da_vez` sem empresa;
- `reports` sem empresa;
- `delivery_events` sem empresa;
- `daily_access_codes` sem empresa.

Conclusão: o sistema atual não pode separar dados de várias empresas com segurança.

### Autenticação atual não atende ao painel de contas

Os papéis atuais são apenas `admin` e `operator`, configurados por variáveis de ambiente. A sessão administrativa identifica principalmente o papel, não um usuário persistente com `user_id`, `tenant_id` e `store_id`.

Para o novo modelo, é necessário migrar para usuários no banco e três papéis:

```text
SUPER_ADMIN
ADMIN_EMPRESA
ENTREGADOR
```

### Relatórios sem paginação

A ação atual de listagem executa:

```sql
ORDER BY id DESC
LIMIT 200
```

Não existem `page`, `per_page`, contagem total ou filtro de data no endpoint atual.

### Ranking limitado a períodos fixos

O painel atual aceita `dia`, `semana` ou `mes`. Não há intervalo personalizado por `date_from` e `date_to`.

### Motoboy identificado pelo nome

`delivery_events` usa `nome` como base do ranking. Isso pode misturar pessoas diferentes com o mesmo nome. É necessário criar `driver_id` permanente.

### Ciclo atual começa às 06:00

`OperationalCycle` possui `DEFAULT_START_HOUR = 6` e aceita apenas hora inteira. Para 01:30, a classe precisa suportar minuto e os testes devem cobrir a fronteira temporal.

### Multissessão do entregador não está disponível

A tabela `public_sessions` possui restrição de uma única sessão ativa por check-in. O novo requisito deve decidir se o entregador poderá ter uma ou várias sessões simultâneas.

### Logs não são auditoria multiempresa

O logger atual grava eventos sanitizados em arquivo privado. Isso é adequado para observabilidade técnica simples, mas não substitui uma tabela de auditoria por tenant, loja e usuário.

### Escalabilidade do rate limiter

O rate limiter atual usa arquivos locais. Isso funciona em uma única instância, mas não é apropriado para várias instâncias de aplicação sem storage compartilhado. Em escala, migrar para Redis ou mecanismo centralizado.

## 3.4 Situação do CI

Existe workflow de validação para PHP, JavaScript e políticas. A consulta do conector não retornou status ou execução associada ao commit mais recente. Portanto, não é possível afirmar neste relatório que o commit atual está com CI verde.

## 3.5 Parecer técnico

| Área | Estado atual | Compatibilidade com o plano |
|---|---|---|
| Segurança básica | Boa fundação para MVP | Parcialmente compatível |
| Multiempresa | Não implementado | Bloqueador |
| Três perfis finais | Não implementado | Requer migração |
| Ranking por data | Períodos fixos | Requer extensão |
| Relatórios paginados | Limite fixo de 200 | Requer alteração |
| PDF | Não identificado | Requer módulo novo |
| Expiração 01:30 | Ciclo às 06:00 | Requer alteração central |
| Logs por empresa | Arquivo técnico global | Requer auditoria no banco |
| Contratos/teste | Não implementado | Requer módulo novo |
| Pausar/ativar empresa | Não implementado | Requer middleware global |
| Escala extrema | Não validada | Requer infraestrutura e carga |

---

# 4. Validação com referências técnicas oficiais

## 4.1 Autorização

O desenho proposto está alinhado à OWASP quando aplica:

- menor privilégio;
- negar por padrão;
- validar permissão em toda requisição;
- não confiar em IDs enviados pelo navegador;
- testar autorização unitária e integrada.

A OWASP também classifica falhas de autorização por objeto como risco principal de APIs. No DaVez, isso significa que todo acesso a relatório, loja, motoboy, código, log e contrato deve validar `tenant_id`, `store_id` e ownership.

## 4.2 Isolamento de tenants

As referências da AWS e Microsoft tratam isolamento de tenant como requisito fundamental em SaaS. O modelo pooled, com banco compartilhado, é viável, mas exige que a aplicação derive o tenant da identidade autenticada e aplique o escopo de forma sistemática.

Para o DaVez, a recomendação inicial é:

```text
Modelo pooled
- aplicação compartilhada
- banco compartilhado
- tenant_id em todas as tabelas de negócio
- store_id nas tabelas operacionais
- isolamento aplicado por middleware/repositório
- testes automáticos de acesso cruzado
```

Empresas que futuramente exigirem isolamento especial poderão usar modelo híbrido ou silo.

## 4.3 Senhas e sessões

A recomendação de `Argon2id`, cookies `Secure`, `HttpOnly`, `SameSite`, modo estrito e IDs somente em cookie está alinhada à documentação do PHP e à OWASP.

## 4.4 Horário

A documentação do MySQL distingue `DATETIME` de `TIMESTAMP`: `TIMESTAMP` converte entre o fuso da conexão e UTC, enquanto `DATETIME` não faz essa conversão automática. Por isso, a aplicação precisa definir uma estratégia única e testada.

Recomendação:

- instantes técnicos em UTC;
- `timezone` IANA por empresa, por exemplo `America/Sao_Paulo`;
- data operacional calculada no domínio;
- não depender de offset fixo `-03:00` como regra permanente;
- não depender do relógio do PC.

## 4.5 Limites e disponibilidade

A OWASP inclui consumo irrestrito de recursos entre os riscos de APIs. Paginação, limites de intervalo, rate limiting, timeout e jobs assíncronos para PDF são necessários para evitar abuso e indisponibilidade.

---

# 5. Arquitetura alvo

## 5.1 Visão de alto nível

```text
+--------------------------------------------------------------------+
|                         USUARIOS DAVEZ                             |
+----------------------+----------------------+----------------------+
| SUPER_ADMIN          | ADMIN_EMPRESA        | ENTREGADOR           |
| Plataforma global    | Empresa autorizada   | Operacao da fila     |
+----------+-----------+-----------+----------+-----------+----------+
           |                       |                      |
           +-----------------------+----------------------+
                                   |
                                   v
+--------------------------------------------------------------------+
|                       FRONTEND / PWA                               |
| Login | Chamada | DaVez | Ranking | Relatorios | Config | Suporte  |
+-----------------------------------+--------------------------------+
                                    | HTTPS
                                    v
+--------------------------------------------------------------------+
|                       BACKEND PHP / API                            |
| Auth | Tenant Context | Authorization | Validation | CSRF | Rate   |
+---------------+-------------------+-------------------+------------+
                |                   |                   |
                v                   v                   v
+-----------------------+ +-------------------+ +--------------------+
| Casos de uso          | | Jobs              | | Observabilidade    |
| Empresas e lojas      | | PDF               | | Logs tecnicos      |
| Usuarios e sessoes    | | Expiracao         | | Auditoria          |
| Fila e entregas       | | Trial/contrato    | | Metricas/alertas   |
+-----------+-----------+ +---------+---------+ +----------+---------+
            |                       |                      |
            +-----------------------+----------------------+
                                    |
                                    v
+--------------------------------------------------------------------+
|                           DADOS                                    |
| MySQL/MariaDB | Storage privado | Redis/fila em escala            |
+--------------------------------------------------------------------+
```

## 5.2 Organização incremental do código

Não realizar reescrita total. Extrair o `admin.php` gradualmente:

```text
public/
  index.php
  admin.php
  assets/

src/
  Http/
    Controllers/
    Middleware/
    Requests/
    Responses/
  Application/
    Tenants/
    Stores/
    Users/
    Drivers/
    Rankings/
    Reports/
    Contracts/
  Domain/
    Tenant/
    Store/
    User/
    Driver/
    OperationalCycle/
    Report/
  Infrastructure/
    Database/
    Logging/
    Pdf/
    Storage/
    Queue/
```

Regra de refatoração:

```text
Criar teste de caracterizacao
-> extrair uma responsabilidade
-> executar testes
-> validar regressao
-> commit pequeno
-> avancar
```

---

# 6. Navegação do sistema

## 6.1 SUPER_ADMIN

```text
PAINEL SUPER_ADMIN
|
+-- Visao geral
|   +-- Empresas ativas, pausadas e em teste
|   +-- Alertas de contrato
|   +-- Erros e disponibilidade
|
+-- Gerenciar contas
|   +-- Cadastrar empresa
|   +-- Listar e pesquisar empresas
|   +-- Abrir empresa
|   +-- Alterar login
|   +-- Redefinir senha
|   +-- Pausar/ativar
|   +-- Contrato e trial
|   +-- Auditoria
|
+-- Relatorios globais
+-- Logs tecnicos
+-- Configuracoes globais
+-- Suporte
```

## 6.2 ADMIN_EMPRESA

```text
PAINEL DA EMPRESA
|
+-- Chamada
+-- DaVez
+-- Ranking
+-- Relatorios
+-- Configuracoes da empresa
+-- Motoboys
+-- Suporte
    +-- Telefone
    +-- WhatsApp
    +-- E-mail
    +-- Horario de atendimento
```

Não visualiza:

- Gerenciar contas;
- outras empresas;
- logs globais;
- contratos de outras empresas;
- credenciais de `SUPER_ADMIN`;
- configurações globais.

## 6.3 ENTREGADOR

```text
ACESSO DO ENTREGADOR
|
+-- Inserir codigo / ler QR
+-- Fazer check-in
+-- Entrar na fila
+-- Ver sua posicao
+-- Recuperar acesso
+-- Sair da sessao
```

---

# 7. Ranking por data

## 7.1 Interface

```text
+---------------------------------------------------------------+
| RANKING DE ENTREGADORES                                       |
+---------------------------------------------------------------+
| Data: [ 01/02/2027 ] [Calendario]                             |
| Periodo: [Data inicial] [Data final]                          |
| [Hoje] [Ontem] [7 dias] [30 dias] [Este mes]                 |
| [Aplicar] [Limpar]                                            |
+---------------------------------------------------------------+
| # | Entregador | Entregas | Dias ativos | Media | Pontos     |
+---------------------------------------------------------------+
```

## 7.2 Regras

- aceitar data específica ou intervalo;
- validar no frontend e backend;
- impedir data final anterior à inicial;
- definir intervalo máximo configurável;
- aplicar fuso da empresa;
- aplicar `tenant_id` e `store_id` automaticamente;
- usar `driver_id`, nunca somente o nome;
- ordenar com desempate determinístico;
- permitir paginação se o número de entregadores crescer.

## 7.3 API

```http
GET /api/rankings?date_from=2027-02-01&date_to=2027-02-28&page=1&per_page=50
```

Resposta:

```json
{
  "date_from": "2027-02-01",
  "date_to": "2027-02-28",
  "page": 1,
  "per_page": 50,
  "total": 15,
  "items": [
    {
      "position": 1,
      "driver_id": 105,
      "driver_name": "Joao Silva",
      "deliveries": 48,
      "active_days": 6,
      "average_per_day": 8.0,
      "score": 510
    }
  ]
}
```

---

# 8. Relatórios, paginação e PDF

## 8.1 Lista profissional

```text
+--------------------------------------------------------------------------+
| RELATORIOS                                                               |
+--------------------------------------------------------------------------+
| [Data inicial] [Data final] [Status] [Pesquisar] [Limpar]                |
|                                      [Gerar Arquivo do Relatorio]         |
+--------------------------------------------------------------------------+
| ID | Criado em | Periodo | Responsavel | Status | Acoes                  |
+--------------------------------------------------------------------------+
| 301| 03/08/26  | 02-03/08| Fernando    | Pronto | Ver | PDF | Excluir   |
+--------------------------------------------------------------------------+
| Exibindo 1-15 de 238                                                     |
| [Anterior] [1] [2] [3] [...] [16] [Proxima]                              |
+--------------------------------------------------------------------------+
```

## 8.2 Paginação

- 15 registros por página;
- paginação no backend;
- ordenação por `created_at DESC, id DESC`;
- contagem total;
- filtros preservados na navegação;
- limite máximo de `per_page`;
- índice por tenant, loja e data.

API:

```http
GET /api/reports?page=1&per_page=15&date_from=2027-02-01&date_to=2027-02-28
```

```json
{
  "items": [],
  "page": 1,
  "per_page": 15,
  "total": 238,
  "total_pages": 16
}
```

## 8.3 PDF

Botão final:

```text
Gerar Arquivo do Relatorio
```

Fluxo:

```text
Usuario escolhe filtros
-> backend valida usuario, papel, tenant e loja
-> consulta dados autorizados
-> cria snapshot do relatorio
-> gera PDF
-> registra auditoria
-> salva arquivo em storage privado
-> fornece download temporario
```

Conteúdo:

- logo e nome da empresa;
- CNPJ;
- período;
- filtros;
- data/hora;
- responsável;
- resumo;
- lista de entregadores;
- entregas;
- ranking;
- totalizadores;
- identificador;
- número de páginas.

Para arquivos grandes:

```text
queued -> processing -> completed | failed
```

O PDF deve ser gerado por job assíncrono quando o processamento puder exceder o timeout do request.

---

# 9. Data, hora e fuso

## 9.1 Fonte oficial

```text
NTP / relogio do servidor
-> backend
-> persistencia em UTC
-> conversao pelo timezone da empresa
-> exibicao local
```

## 9.2 Regras

- `timezone` IANA por empresa;
- padrão inicial `America/Sao_Paulo`;
- captura de um único `now` por request;
- banco e aplicação com comportamento documentado;
- não usar o relógio do navegador para autorização ou expiração;
- não espalhar offsets fixos em vários arquivos.

---

# 10. Expiração dos códigos às 01:30

## 10.1 Regra funcional

```text
Criado no dia D
-> valido ate a virada operacional
-> expira no dia seguinte as 01:30 da loja
```

Exemplos:

```text
Criado: 01/02/2027 10:00
Expira: 02/02/2027 01:30

Criado: 01/02/2027 23:50
Expira: 02/02/2027 01:30
```

## 10.2 Alteração de domínio

Substituir a ideia de hora inteira por:

```text
cycle_start_hour = 1
cycle_start_minute = 30
```

ou um value object de horário operacional.

Não repetir `01:30` em endpoints diferentes. A regra deve estar centralizada em `OperationalCycle`.

## 10.3 Testes

- 01:29:59;
- 01:30:00;
- 01:30:01;
- mudança de dia;
- código criado próximo ao corte;
- servidor em UTC;
- empresa em `America/Sao_Paulo`;
- tentativa após expiração;
- uso concorrente;
- alteração de fuso da empresa;
- código de outra loja.

---

# 11. Multiempresa e multissessão

## 11.1 Modelo recomendado

```text
PLATAFORMA DAVEZ
|
+-- Tenant A
|   +-- Loja A1
|   |   +-- ADMIN_EMPRESA
|   |   +-- Entregadores
|   |   +-- Codigos
|   |   +-- Filas
|   |   +-- Ranking
|   |   +-- Relatorios
|   |   +-- Logs
|   |   +-- Configuracoes
|   +-- Loja A2
|
+-- Tenant B
|   +-- Loja B1
|
+-- Tenant C
    +-- Loja C1
```

## 11.2 Contexto de tenant

```text
Login / codigo
-> identidade autenticada
-> resolve tenant_id e store_id
-> middleware aplica autorizacao
-> repositories recebem contexto imutavel
-> consulta retorna somente dados autorizados
```

O frontend não escolhe livremente o `tenant_id`.

## 11.3 Política de sessões

### SUPER_ADMIN

- múltiplas sessões permitidas com MFA;
- tela de sessões ativas;
- revogação remota;
- alerta de novo dispositivo.

### ADMIN_EMPRESA

- uma ou mais contas por empresa, conforme contrato;
- sessões simultâneas configuráveis;
- revogação após pausa da empresa;
- todas as sessões associadas ao tenant e à loja.

### ENTREGADOR

Definir uma decisão de produto:

- opção A: uma sessão ativa por entregador;
- opção B: múltiplas sessões limitadas por dispositivo.

A implementação atual segue a opção A. A mudança para opção B exige revisão da constraint `uniq_public_session_active_device`.

---

# 12. Perfis de acesso - 3 tipos

## 12.1 SUPER_ADMIN

Acesso:

- todas as empresas e lojas;
- cadastro e alteração de empresas;
- relatórios globais;
- logs técnicos e auditoria;
- contratos e períodos de teste;
- pausa, ativação, arquivamento e exclusão;
- alteração de login e redefinição de senha;
- configurações globais;
- monitoramento e suporte.

Contas iniciais:

- `FernandoPeralta`;
- `RobertMoura`.

## 12.2 ADMIN_EMPRESA

Acesso somente à empresa autorizada:

- Chamada;
- fila DaVez;
- Ranking;
- Relatórios;
- PDF;
- configurações locais permitidas;
- entregadores;
- contatos do suporte.

Restrições:

- não acessa outras empresas;
- não acessa Gerenciar Contas;
- não acessa logs globais;
- não altera contrato;
- não pausa ou exclui a própria empresa, salvo regra futura expressa;
- não cria `SUPER_ADMIN`;
- não modifica `tenant_id` ou `store_id`.

## 12.3 ENTREGADOR

Acesso operacional:

- autenticação por código/QR;
- check-in;
- entrada na fila;
- consulta da própria posição;
- recuperação;
- logout.

Não acessa painel administrativo.

---

# 13. Cadastro e gerenciamento de empresas

## 13.1 Formulário

```text
+------------------------------------------------------------+
| CADASTRAR NOVA EMPRESA                                     |
+------------------------------------------------------------+
| Nome da empresa:   [________________________________]       |
| CNPJ:              [__.___.___/____-__]                     |
| Endereco:          [________________________________]       |
| Telefone:          [(__) _____-____]                        |
| Responsavel:       [________________________________]       |
| Login inicial:     [________________________________]       |
| Senha temporaria:  [________________________________]       |
|                                                            |
| [Validar cadastro]             [Cadastrar empresa]          |
+------------------------------------------------------------+
```

## 13.2 Fluxo transacional

```text
Validar entrada
-> validar CNPJ e duplicidade
-> validar login
-> iniciar transacao
-> criar tenant
-> criar empresa
-> criar loja
-> criar configuracoes
-> criar ADMIN_EMPRESA
-> criar trial ou contrato
-> registrar auditoria
-> commit
```

Falha em qualquer etapa:

```text
rollback completo
```

## 13.3 Validar cadastro

O botão deve verificar sem duplicar dados:

- campos obrigatórios;
- CNPJ;
- login disponível;
- e-mail e telefone;
- plano e limites;
- configurações obrigatórias;
- tenant criado corretamente;
- conta inicial;
- permissões;
- isolamento.

## 13.4 Lista de empresas

```text
+----------------------------------------------------------------+
| EMPRESAS CADASTRADAS                                           |
+----------------------------------------------------------------+
| [Pesquisar nome ou CNPJ____________________] [Pesquisar]        |
| [Status] [Plano] [Cidade] [Limpar]                              |
+----------------------------------------------------------------+
| Empresa A                                      ATIVA            |
| CNPJ | Responsavel | Telefone | Endereco                        |
|                                      [Gerenciar empresa]        |
+----------------------------------------------------------------+
| [Anterior] [1] [2] [3] [...] [Proxima]                          |
+----------------------------------------------------------------+
```

Pesquisar por:

- nome;
- CNPJ;
- responsável;
- telefone;
- cidade;
- status.

## 13.5 Página individual

```text
EMPRESA
|
+-- Visao geral
+-- Relatorios
+-- Logs de auditoria
+-- Configuracoes
+-- Contas ADMIN_EMPRESA
+-- Entregadores
+-- Contrato
+-- Periodo de teste
+-- Sessoes
+-- Area de risco
    +-- Pausar
    +-- Arquivar
    +-- Excluir
```

## 13.6 Alterar login e senha

- login único;
- reautenticação do `SUPER_ADMIN`;
- auditoria;
- redefinição por senha temporária ou link seguro;
- não mostrar senha atual;
- revogar sessões após redefinição;
- forçar troca no próximo acesso.

---

# 14. Pausa, ativação, contrato e teste

## 14.1 Pausar empresa

```text
SUPER_ADMIN solicita pausa
-> informa motivo
-> confirma
-> status PAUSED
-> revoga sessoes ADMIN_EMPRESA
-> bloqueia novos logins e mutacoes
-> registra auditoria
```

Mensagem:

```text
Sua conta esta temporariamente pausada.

Entre em contato com a equipe de suporte o mais rapido possivel
para regularizar o acesso e evitar interrupcoes no fluxo de trabalho.
```

O bloqueio deve ocorrer no backend em todas as requisições protegidas.

## 14.2 Ativar empresa

```text
Verificar contrato/trial
-> verificar pendencias
-> status ACTIVE
-> liberar novos logins
-> invalidar caches
-> registrar auditoria
```

Sessões antigas revogadas não devem ser reativadas.

## 14.3 Contrato

Campos:

- início;
- término;
- plano;
- limite de contas;
- limite de entregadores;
- valor;
- observações;
- responsável;
- documento/referência.

Preservar versões anteriores.

## 14.4 Período de teste

```text
Inicio
-> aviso 7 dias antes
-> aviso 3 dias antes
-> aviso 1 dia antes
-> vencimento
-> pausa automatica
```

A expiração deve ser verificada por job e também no login/request, evitando dependência exclusiva do cron.

---

# 15. Exclusão segura

Fluxo:

```text
ACTIVE
-> PAUSED
-> ARCHIVED
-> SOFT_DELETED
-> RETENCAO
-> EXCLUSAO DEFINITIVA
```

Exigir:

- `SUPER_ADMIN`;
- MFA;
- reautenticação;
- digitação do nome ou CNPJ;
- motivo;
- backup;
- verificação de dependências;
- auditoria;
- job assíncrono;
- relatório final de exclusão.

Campos:

```text
deleted_at
deleted_by
delete_reason
purge_scheduled_at
purged_at
```

---

# 16. Banco de dados e isolamento

## 16.1 Entidades

```text
tenants
stores
users
roles
user_roles
user_store_roles
drivers
driver_store_memberships
store_settings
admin_sessions
public_sessions
checkins
queue_entries
access_codes
delivery_events
reports
report_files
contracts
trial_periods
audit_logs
system_logs
notifications
```

## 16.2 Campos comuns

```text
id
tenant_id
store_id
status
created_at
updated_at
created_by
updated_by
deleted_at
version
```

## 16.3 Índices

```text
(tenant_id, store_id, created_at)
(tenant_id, store_id, status)
(tenant_id, store_id, operational_date)
(tenant_id, store_id, driver_id)
(tenant_id, cnpj)
(tenant_id, user_id)
(tenant_id, expires_at)
```

Validar com `EXPLAIN` e dados representativos.

## 16.4 Migração do banco atual

Ordem segura:

1. backup e restore testado;
2. inventário do schema real;
3. criar `tenants` e `stores`;
4. criar tenant/loja para os dados legados;
5. adicionar colunas nullable;
6. backfill controlado;
7. validar contagens e relações;
8. criar índices;
9. tornar campos obrigatórios quando seguro;
10. adicionar constraints;
11. atualizar endpoints;
12. testes de isolamento;
13. ativar funcionalidade por feature flag.

Não executar uma migration destrutiva diretamente em produção.

---

# 17. APIs sugeridas

```text
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/sessions
DELETE /api/auth/sessions/{id}

GET    /api/admin/companies
POST   /api/admin/companies
GET    /api/admin/companies/{id}
PATCH  /api/admin/companies/{id}
POST   /api/admin/companies/{id}/pause
POST   /api/admin/companies/{id}/activate
DELETE /api/admin/companies/{id}

GET    /api/admin/companies/{id}/users
POST   /api/admin/companies/{id}/users
PATCH  /api/admin/users/{id}/login
POST   /api/admin/users/{id}/reset-password

GET    /api/rankings
GET    /api/reports
POST   /api/reports
GET    /api/reports/{id}
GET    /api/reports/{id}/download

GET    /api/audit-logs
GET    /api/contracts
POST   /api/contracts/{companyId}/renew
POST   /api/trials/{companyId}
```

Todas as rotas devem:

- negar por padrão;
- validar papel;
- validar tenant e loja;
- validar ownership;
- limitar parâmetros;
- usar respostas de erro seguras;
- registrar ações sensíveis.

---

# 18. Logs e auditoria

## 18.1 Logs técnicos

- exceções;
- banco;
- latência;
- jobs;
- fila;
- PDF;
- infraestrutura;
- falhas de integração.

## 18.2 Auditoria

```text
id
tenant_id
store_id
actor_user_id
action
resource_type
resource_id
before_json
after_json
result
ip_hash
session_id
created_at
```

Não registrar diretamente:

- senhas;
- tokens;
- cookies;
- connection strings;
- chaves;
- documentos pessoais sem necessidade;
- coordenadas completas quando desnecessárias.

---

# 19. Robustez, disponibilidade e infraestrutura

## 19.1 Controles

- transações;
- locks;
- idempotência;
- timeout;
- retry com backoff;
- circuit breaker para integrações;
- paginação;
- rate limiting;
- cache;
- jobs;
- health checks;
- métricas;
- tracing;
- alertas;
- backup e restore;
- rollback;
- pool de conexões;
- queries otimizadas.

## 19.2 Polling

Exemplo de carga:

```text
1.000 dispositivos / 10 segundos = aproximadamente 100 requisicoes por segundo
```

Em alta escala:

- reduzir polling desnecessário;
- pausar em background;
- usar cache;
- considerar SSE/WebSocket quando justificado;
- centralizar rate limiting;
- medir p95 e p99.

## 19.3 Infraestrutura

Hospedagem compartilhada pode atender piloto pequeno, mas não deve ser usada para prometer a escala máxima sem teste.

Evolução possível:

- VPS ou cloud;
- PHP-FPM configurável;
- MySQL dedicado/gerenciado;
- Redis;
- fila de jobs;
- storage externo;
- monitoramento centralizado;
- CDN para assets;
- balanceamento quando necessário.

---

# 20. Estratégia de implementação por fases

## Fase 0 - Diagnóstico e proteção

- congelar escopo;
- backup;
- restore;
- inventário do banco real;
- testes de caracterização;
- mapa de endpoints;
- feature flags;
- rollback.

**Portão:** não avançar sem backup restaurável e testes básicos verdes.

## Fase 1 - Fundação multi-tenant

- tenants;
- stores;
- contexto de tenant;
- migração dos dados legados;
- escopo em repositories;
- testes de acesso cruzado.

**Portão:** Empresa A nunca acessa dados da Empresa B.

## Fase 2 - Usuários e três perfis

- usuários no banco;
- `SUPER_ADMIN`;
- `ADMIN_EMPRESA`;
- `ENTREGADOR`;
- sessões rastreáveis;
- troca de senha;
- MFA;
- revogação.

## Fase 3 - Operação por empresa

- settings por loja;
- entregadores;
- códigos;
- check-ins;
- filas;
- entregas;
- QR por loja.

## Fase 4 - Gerenciar contas

- cadastro;
- validação;
- listagem;
- busca;
- página individual;
- login e senha;
- sessões.

## Fase 5 - Ranking e relatórios

- filtro por data;
- `driver_id`;
- paginação;
- lista de 15;
- PDF;
- histórico.

## Fase 6 - Ciclo 01:30

- hora e minuto;
- migração da regra;
- códigos;
- sessões;
- fila;
- ranking;
- relatórios;
- testes de fronteira.

## Fase 7 - Contratos e trial

- contrato versionado;
- avisos;
- pausa automática;
- ativação;
- renovação.

## Fase 8 - Auditoria e observabilidade

- audit log;
- métricas;
- alertas;
- dashboard;
- retenção.

## Fase 9 - Performance

- índices;
- `EXPLAIN`;
- cache;
- Redis;
- jobs;
- carga;
- otimização.

## Fase 10 - Staging e produção

- staging;
- E2E;
- segurança;
- restore;
- rollback;
- deploy gradual;
- smoke test;
- monitoramento pós-deploy.

## Regra para não pular etapas

```text
Concluir fase
-> executar testes
-> corrigir falhas
-> documentar
-> validar rollback
-> aprovar
-> iniciar proxima fase
```

Se houver falha crítica:

```text
NAO AVANCAR
```

---

# 21. Testes obrigatórios

## 21.1 Unitários

- cálculo de 01:30;
- fuso;
- CNPJ;
- status de empresa;
- contrato;
- trial;
- autorização;
- score do ranking.

## 21.2 Integração

- cadastro transacional;
- tenant e loja;
- usuário;
- sessão;
- código;
- fila;
- relatório;
- PDF;
- pausa;
- ativação;
- auditoria.

## 21.3 E2E

- login `SUPER_ADMIN`;
- cadastro de empresa;
- login `ADMIN_EMPRESA`;
- ausência de Gerenciar Contas;
- fluxo do entregador;
- Ranking por data;
- relatório paginado;
- PDF;
- pausa e mensagem;
- ativação;
- renovação.

## 21.4 Segurança

- Empresa A tentando acessar Empresa B;
- manipulação de IDs;
- BOLA/IDOR;
- escalada de privilégio;
- brute force;
- CSRF;
- XSS;
- SQL injection;
- sessão revogada;
- conta pausada;
- código de outra loja;
- download de PDF de outro tenant;
- mass assignment;
- rate limit.

## 21.5 Carga

Cenários separados:

- 1.000 empresas cadastradas;
- 1.000 entregadores por empresa cadastrados;
- 1.000 dispositivos simultâneos;
- polling;
- ranking;
- relatórios;
- PDFs;
- expirações simultâneas;
- cadastro concorrente;
- pausa de empresa com muitas sessões.

Métricas:

- p50, p95 e p99;
- taxa de erro;
- throughput;
- CPU;
- memória;
- conexões;
- lock wait;
- tempo de PDF;
- tamanho de logs.

## 21.6 Recuperação

- backup;
- restore;
- migration interrompida;
- rollback;
- banco indisponível;
- storage indisponível;
- falha de PDF;
- job duplicado;
- perda de instância.

---

# 22. Critérios de aceite

A implementação será considerada concluída quando:

- existem apenas os três perfis definidos;
- cada empresa acessa somente seus dados;
- `SUPER_ADMIN` acessa todas as empresas;
- `ADMIN_EMPRESA` não visualiza Gerenciar Contas;
- entregador não acessa painel administrativo;
- códigos não funcionam em outra loja;
- Ranking filtra por data;
- Ranking usa `driver_id`;
- relatórios usam 15 por página;
- PDF é gerado e baixado com autorização;
- ciclo expira às 01:30;
- pausa bloqueia login e sessões;
- ativação funciona sem restaurar sessões antigas;
- trial pausa automaticamente;
- contratos preservam histórico;
- login e senha podem ser alterados com auditoria;
- logs são separados por tenant e loja;
- backup e restore foram testados;
- migrations foram testadas;
- CI e testes E2E estão verdes;
- testes de carga atingem metas definidas;
- nenhum segredo está no repositório;
- nenhuma funcionalidade anterior foi apagada sem aprovação.

---

# 23. Riscos e decisões pendentes

| Decisão | Situação | Ação necessária |
|---|---|---|
| Uma ou várias lojas por empresa | Não formalizada | Definir cardinalidade e permissões |
| Sessões simultâneas do entregador | Atual é uma | Escolher política final |
| Escala simultânea real | Não definida | Definir meta de usuários e RPS |
| Infraestrutura final | Hostinger compartilhada no guia atual | Validar piloto e plano de evolução |
| Retenção de logs e relatórios | Não definida | Política legal e operacional |
| Formato visual de data | Texto original ambíguo | Padronizar DD/MM/AAAA no Brasil |
| MFA | Recomendado para SUPER_ADMIN | Escolher TOTP/WebAuthn |
| Biblioteca de PDF | Não definida | PoC com carga e acentuação |
| CI do commit atual | Status não retornado | Verificar manualmente no GitHub Actions |
| Banco legado real | Não inspecionado | Executar preflight somente leitura |

---

# 24. Referências técnicas

## Repositório DaVez

- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/DATABASE_OPERATIONS.md`
- `docs/DEPLOYMENT_HOSTINGER.md`
- `SECURITY.md`
- `database/schema.sql`
- `src/Domain/OperationalCycle.php`
- `src/Security/Session.php`
- `src/Database/PublicIdentityStore.php`
- `tests/README.md`
- `.github/workflows/ci.yml`

## Fontes oficiais externas

1. OWASP Authorization Cheat Sheet  
   https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html

2. OWASP API Security Top 10 - 2023  
   https://owasp.org/API-Security/editions/2023/en/0x11-t10/

3. OWASP Password Storage Cheat Sheet  
   https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html

4. OWASP Logging Cheat Sheet  
   https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html

5. PHP - Securing Session INI Settings  
   https://www.php.net/manual/en/session.security.ini.php

6. PHP - session_set_cookie_params  
   https://www.php.net/manual/en/function.session-set-cookie-params.php

7. MySQL 8.4 - DATE, DATETIME and TIMESTAMP Types  
   https://dev.mysql.com/doc/refman/8.4/en/datetime.html

8. AWS - SaaS Tenant Isolation Strategies  
   https://docs.aws.amazon.com/whitepapers/latest/saas-tenant-isolation-strategies/saas-tenant-isolation-strategies.html

9. Microsoft - Tenancy Models for a Multitenant Solution  
   https://learn.microsoft.com/en-us/azure/architecture/guide/multitenant/considerations/tenancy-models

---

# Conclusão

O plano está tecnicamente adequado após as correções, mas **não está pronto para ser aplicado diretamente sobre o código atual como uma única alteração**.

A ordem correta é:

```text
Backup e diagnostico
-> multi-tenant
-> usuarios e 3 perfis
-> isolamento dos dados
-> migracao operacional
-> gerenciamento de empresas
-> ranking e relatorios
-> ciclo 01:30
-> contratos e trial
-> observabilidade
-> carga
-> staging
-> producao
```

A prioridade deve permanecer:

```text
Seguranca
-> integridade dos dados
-> isolamento entre empresas
-> recuperacao de falhas
-> testes
-> performance
-> experiencia do usuario
-> velocidade de entrega
```
