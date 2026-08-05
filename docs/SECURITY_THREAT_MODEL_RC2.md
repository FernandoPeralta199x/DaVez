# Modelo de ameacas do repositorio - DaVez RC2

## Escopo

Este modelo cobre todo o repositorio DaVez RC2: aplicacao publica/PWA, painel administrativo, endpoints PHP, banco MySQL/Percona, storage privado, geracao de PDF, service worker, scripts de build e validacao, migrations e documentacao operacional.

## Superficies de produto e runtime

- Pagina publica `index.html` e PWA instalada no dispositivo do entregador.
- Endpoints publicos de check-in, recuperacao, sessao, fila, logout e telemetria controlada.
- Painel administrativo `admin.php` e endpoints administrativos JSON.
- Endpoints de download de PDF autenticados.
- Banco MySQL/Percona com tabelas de configuracao, check-ins, fila, sessoes, codigos, eventos e relatorios.
- Storage privado de logs e rate limiting fora do webroot.
- Nginx/Apache/PHP-FPM, proxy reverso e configuracao de HTTPS.
- Pipeline de build e artefatos de release.

## Ativos e privilegios relevantes

- Credenciais administrativas e hashes de senha.
- Cookies de sessao administrativa e publica.
- Chave HMAC dos codigos publicos.
- Dados operacionais: nomes, posicoes, entregas, horarios, relatorios e coordenadas de configuracao.
- Integridade da ordem da fila e do ciclo operacional.
- Isolamento entre area publica e administrativa.
- Disponibilidade dos endpoints de polling e das operacoes de fila.
- Integridade e confidencialidade de PDFs e logs.
- Configuracao do banco e segredos externos ao repositorio.

## Atores e capacidades

- Visitante nao autenticado com controle total de parametros HTTP, cabecalhos, cookies proprios e frequencia de requisicoes.
- Entregador autenticado com controle do dispositivo, localizacao enviada pelo navegador e chamadas aos endpoints publicos.
- Operador administrativo autenticado, sem acesso aos logs privados.
- Administrador proprietario autenticado, com acesso a configuracoes, relatorios e logs.
- Atacante de rede sem TLS, proxy mal configurado ou host compartilhado comprometido.
- Usuario local do servidor com acesso parcial ao filesystem.
- Dependencia de frontend comprometida ou artefato de deploy adulterado.

## Fronteiras de confianca

1. Navegador publico -> Nginx/PHP.
2. Navegador administrativo -> sessao PHP -> autorizacao administrativa.
3. PHP -> MySQL/Percona.
4. PHP -> storage privado de logs/rate limit.
5. Proxy/CDN -> Nginx/PHP por cabecalhos encaminhados.
6. Codigo-fonte -> processo de build -> artefato de deploy.
7. Service worker/cache local -> versao atual do backend.
8. Dados do navegador, inclusive GPS e relogio local -> regras oficiais do backend.

## Entradas controladas por atacante

- Todos os parametros GET/POST/JSON e campos de formulario.
- Cookies e IDs de sessao enviados pelo cliente.
- `Origin`, `Host`, `Sec-Fetch-Site`, `X-Forwarded-Proto` quando o proxy nao e confiavel.
- Coordenadas GPS e nome informado no check-in.
- Codigo individual digitado ou lido por QR.
- IDs de relatorios, itens da fila e paginas/filtros.
- Volume e cadencia de polling, login, recuperacao, PDF e logs de cliente.
- Conteudo de arquivos colocados indevidamente no webroot por processo operacional.

## Invariantes de seguranca

- Negar acesso por padrao e validar permissao em toda requisicao protegida.
- Nenhuma rota administrativa deve responder dados sem sessao valida.
- Operadores nao podem acessar logs reservados ao administrador.
- Mutacoes administrativas exigem CSRF e metodo HTTP correto.
- Sessao publica e codigo individual nao podem ser substituidos por nome, IP, user-agent ou `client_id` fornecido pelo cliente.
- Codigo bruto e token de sessao nunca sao persistidos; somente hashes/HMAC.
- Uma identidade publica nao pode manter duas sessoes ativas quando a politica vigente exige sessao unica.
- Alteracoes de fila e consumo/ativacao de codigo devem ser atomicos e idempotentes.
- Datas oficiais e expiracoes usam o relogio do servidor e o timezone operacional, nunca o relogio do navegador.
- Relatorios e PDFs exigem autenticacao, validacao de ID/filtro, rate limit e resposta `no-store`.
- Logs nao podem conter senhas, tokens, cookies, connection strings, coordenadas completas ou payloads livres.
- Segredos, dumps, backups, relatorios e arquivos de runtime nao podem entrar no artefato publico.
- Cabecalhos de proxy so sao confiados para IPs/CIDRs explicitamente configurados.
- Service worker nao pode manter frontend antigo indefinidamente apos mudanca de contrato de API.

## Falhas de maior impacto

- Bypass de autenticacao/autorizacao ou escalada de operador para administrador.
- IDOR em relatorios/PDFs ou futura separacao por empresa.
- Reutilizacao, previsao ou vazamento de codigo/sessao.
- Corrida que duplica ordem, despacho, sessao ou consumo de codigo.
- SQL injection, XSS persistente/refletido ou injecao em logs/PDF.
- Cache de resposta autenticada ou mistura de versoes PWA/backend.
- Exposicao de `config.php`, `.env`, SQL, logs, backups ou codigo interno pelo webserver.
- Ausencia de rate limiting ou indisponibilidade do proprio rate limiter.
- Operacao destrutiva parcial sem transacao/rollback.
- Deadlock nao tratado causando indisponibilidade repetida.
- Permissoes de filesystem excessivas para segredos e storage privado.
- Dependencia de horario do cliente ou timezone divergente.
- Falha de isolamento quando a arquitetura multiempresa for ativada.

## Assuncoes explicitas

- Producao usa HTTPS valido e proxy configurado corretamente.
- MySQL/Percona usa InnoDB e constraints do schema canonico.
- `config.php`, `davez-env.php`, logs e rate-limit ficam fora do controle de versao.
- O usuario de banco de runtime nao possui DDL.
- O geofence e um controle operacional, nao uma prova antifraude forte.
- Multiempresa completa ainda nao esta ativa neste snapshot; qualquer ativacao futura exige `tenant_id`/`store_id` e testes de acesso cruzado.

Repository: FernandoPeralta199x/DaVez (working copy DaVez-Tech-UX-RC2)
Version: snapshot-09023e73c23112c77236917a52de534ab9a2b60b1b403116e754982f2d6abf6c
