# Fundação incremental de segurança

## Estado

Esta fundação implementa a base dos achados F-004 a F-009 e já está integrada
às rotas administrativas, de check-in, re-login e fila. A integração preserva
o `client_id` público como compatibilidade temporária; ele não deve evoluir
para fonte de autenticação ou autorização.

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

As mutações públicas legadas não recebiam um campo CSRF. Para preservar o
frontend existente, `session_info.php` cria um contexto de requisição
host-only, `HttpOnly` e `SameSite=Strict`. `checkin.php`, `relogin.php` e
`DaVez/entrar.php` exigem esse contexto e rejeitam sinais `cross-site`. O
contexto não usa `client_id`.

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
- `checkin.php`: POST, contexto público, limites e rate limiting;
- `relogin.php`: POST, contexto público, limites e rate limiting;
- `session_info.php`: GET, resposta segura e bootstrap do contexto público;
- `DaVez/entrar.php`: POST, contexto público, limites e rate limiting;
- `DaVez/listar.php`: GET e respostas sem detalhes internos;
- `DaVez/reordenar.php`: admin, POST, CSRF, limites e rate limiting;
- `DaVez/sair.php`: admin, POST, CSRF, limites e rate limiting.

As ações `toggle_chamada` e `limpar` não aceitam mais GET. O JavaScript do
painel envia POST JSON e `X-CSRF-Token` em todas as mutações.

## Limites desta etapa

- banco, migrations e concorrência da fila não foram alterados;
- não há integração com MySQL real nesta etapa;
- nenhuma credencial real foi lida ou gravada;
- nenhum segredo deve ser adicionado a este documento.
- a listagem pública ainda inclui `client_id` para compatibilidade com o
  frontend atual; a substituição por identidade de sessão é uma etapa própria;
- o rate limiter em arquivo é local à instância;
- `session_info.php` mantém a rotação de token existente durante GET para não
  alterar a regra do ciclo nesta etapa; separar leitura e rotação continua
  recomendado.
