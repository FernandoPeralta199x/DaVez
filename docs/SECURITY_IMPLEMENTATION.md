# Fundação incremental de segurança

## Estado

Esta fundação implementa a base dos achados F-004 a F-009 e a identidade
pública v2 aprovada. As rotas públicas ativas derivam identidade de uma sessão
opaca vinculada a `checkin_id`; `client_id` legado não autentica nem autoriza.
O lote ainda não foi ativado porque migrations e fluxos integrados não foram
validados em MySQL real e HTTPS.

Validado apenas por leitura estática e testes focados locais.

## Arquivos e APIs

### Bootstrap

```php
require_once __DIR__ . '/src/Security/Bootstrap.php';
```

O bootstrap carrega todos os helpers abaixo. Cada arquivo também pode ser
importado separadamente.

### Método e JSON

```php
davez_require_http_method('POST');
$input = davez_read_json_body(32768);
davez_send_json(['ok' => true]);
davez_send_error('invalid_request', 'Solicitação inválida.', 400);
```

- `davez_require_http_method()` encerra métodos fora da allowlist com HTTP 405.
- `davez_read_json_body()` limita o corpo antes do parse.
- respostas recebem `no-store` e `nosniff`;
- erros públicos não recebem exceção, SQL, stack trace ou valores de entrada.

### Sessão e autenticação administrativa

Configuração mínima, sempre fora do repositório:

```env
ADMIN_USER=
ADMIN_PASSWORD_HASH=
ADMIN_SESSION_IDLE_SECONDS=1800
ADMIN_SESSION_ABSOLUTE_SECONDS=28800
APP_SESSION_NAME=davez_session
```

`ADMIN_PASSWORD_HASH` deve ser gerado com `password_hash()` e nunca deve conter
a senha em texto puro.

```php
if (!davez_admin_authenticate($username, $password)) {
    davez_send_error(
        'invalid_credentials',
        'Credenciais inválidas.',
        401
    );
}

davez_require_admin();
$identity = davez_authenticated_admin_identity();
davez_admin_logout();
```

A autenticação usa `password_verify()`, rotaciona o ID da sessão e guarda apenas
o papel `admin`. A identidade não é obtida de `user_id`, `client_id`, `role` ou
outro campo do frontend.

Em HTTPS, o cookie é `Secure`, `HttpOnly` e `SameSite=Strict`. Em HTTP local,
`Secure` acompanha o transporte para manter o ambiente testável; staging e
produção devem ser HTTPS.

### Transporte atrás de proxy reverso

```env
APP_TRUSTED_PROXIES=10.0.0.8,10.0.1.0/24
```

Quando o TLS termina em um balanceador, `$_SERVER['HTTPS']` e `SERVER_PORT`
descrevem o salto interno em texto claro. Sem configuração o aplicativo
concluiria HTTP e a identidade pública responderia 426 a todos os
dispositivos.

- `APP_TRUSTED_PROXIES` aceita IPs e faixas CIDR, IPv4 ou IPv6;
- `X-Forwarded-Proto` só é lido quando `REMOTE_ADDR` pertence à lista;
- sem a variável nenhum cabeçalho encaminhado é considerado;
- um valor malformado interrompe a requisição em vez de assumir HTTP.

Nunca inclua faixas amplas: qualquer cliente dentro delas pode declarar HTTPS
e obter cookies `Secure` em transporte inseguro.

O painel não usa mais HTTP Basic nem compara senha em texto puro. `admin.php`
exibe formulário de login, limita tentativas, rotaciona a sessão e oferece
logout por POST com CSRF.

### CSRF

```php
$csrfToken = davez_csrf_token();

davez_require_http_method('POST');
davez_require_admin();
davez_require_csrf();
```

O cliente deve enviar o token em `X-CSRF-Token` ou no campo `_csrf`. O token não
deve ser aceito por query string. Login bem-sucedido rotaciona sessão e CSRF.

`session_info.php` cria um contexto de requisição host-only, `HttpOnly` e
`SameSite=Strict`. `checkin.php`, `recover.php`, `public_logout.php` e
`DaVez/entrar.php` exigem esse contexto e rejeitam sinais `cross-site`.
`relogin.php` encerra o fluxo legado com HTTP 410 sem consultar dados.

### Validação e identidade

```php
davez_assert_allowed_input_keys($input, ['nome', 'latitude', 'longitude']);
davez_assert_no_untrusted_identity($input);

$name = davez_input_string($input, 'nome', 2, 80);
$latitude = davez_input_float($input, 'latitude', -90, 90);
$longitude = davez_input_float($input, 'longitude', -180, 180);
```

Os endpoints devem definir uma allowlist própria. IDs e permissões do frontend
nunca podem ser fonte de autenticação ou autorização.

### Rate limiting local

Configuração obrigatória:

```env
APP_RATE_LIMIT_DIR=
APP_RATE_LIMIT_SECRET=
```

- o diretório deve usar caminho absoluto fora do web root; se ainda não existir,
  poderá ser criado com permissão restrita;
- o segredo deve ter pelo menos 32 caracteres aleatórios;
- bucket e sujeito formam apenas um nome HMAC, nunca são gravados em claro;
- o arquivo é atualizado sob lock exclusivo;
- `X-Forwarded-For` não é confiado automaticamente.

```php
$rate = davez_rate_limit_consume(
    'admin-login',
    davez_rate_limit_request_subject(),
    5,
    300
);

if (!$rate['allowed']) {
    header('Retry-After: ' . $rate['retry_after']);
    davez_send_error(
        'rate_limit_exceeded',
        'Muitas tentativas. Tente novamente mais tarde.',
        429
    );
}
```

Essa implementação é proporcional ao MVP local. Para múltiplas instâncias,
substituir por storage centralizado e atômico.

## Integração aplicada

- `admin.php`: login/logout por sessão, CSRF, allowlists e rate limiting;
- `admin.php`: emissão única de ticket de check-in ou recovery vinculado;
- `checkin.php`: ticket individual, sessão pública, contexto e rate limiting;
- `recover.php`: recovery administrativo, revogação anterior e nova sessão;
- `public_logout.php`: logout idempotente com revogação server-side;
- `relogin.php`: POST legado encerrado com HTTP 410;
- `session_info.php`: GET estritamente de leitura da identidade pública;
- `DaVez/entrar.php`: identidade derivada da sessão e fila por `checkin_id`;
- `DaVez/listar.php`: visão pública mínima `next`/`me`/contadores;
- `DaVez/listar_admin.php`: listagem completa somente para administrador;
- `DaVez/reordenar.php`: admin, POST, CSRF, limites e rate limiting;
- `DaVez/sair.php`: admin, POST, CSRF, limites e rate limiting.

As ações `toggle_chamada` e `limpar` não aceitam mais GET. O JavaScript do
painel envia POST JSON e `X-CSRF-Token` em todas as mutações.

## Limites desta etapa

- migrations aditivas `005..008` foram criadas, mas não executadas;
- não há integração ou concorrência validada em MySQL real;
- nenhuma credencial real foi lida ou gravada;
- nenhum segredo deve ser adicionado a este documento.
- o rate limiter em arquivo é local à instância;
- cookies precisam de validação em navegador HTTPS com proxy real;
- backup, restore, rollback, E2E público/admin e piloto operacional continuam
  obrigatórios antes do corte.
