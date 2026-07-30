<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Security/Bootstrap.php';

function security_foundation_fail(string $message): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function security_foundation_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        security_foundation_fail($message);
    }
}

$sessionDirectory = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'davez-session-'
    . bin2hex(random_bytes(8));

if (!mkdir($sessionDirectory, 0700, true)) {
    security_foundation_fail(
        'Não foi possível criar storage temporário de sessão.'
    );
}

session_save_path($sessionDirectory);
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';

$password = 'Senha-sintetica-forte-123!';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

security_foundation_assert(
    is_string($passwordHash),
    'Não foi possível criar o hash sintético.'
);
putenv('ADMIN_USER=operador');
putenv('ADMIN_PASSWORD_HASH=' . $passwordHash);

security_foundation_assert(
    davez_admin_authenticate('operador', $password),
    'Credenciais válidas não foram autenticadas.'
);
security_foundation_assert(
    !davez_admin_authenticate('operador', 'senha-incorreta'),
    'Senha inválida foi aceita.'
);
security_foundation_assert(
    davez_authenticated_admin_identity() === ['role' => 'admin'],
    'A identidade não foi derivada da sessão.'
);

$cookie = session_get_cookie_params();
security_foundation_assert(
    $cookie['secure'] === true
    && $cookie['httponly'] === true
    && ($cookie['samesite'] ?? null) === 'Strict',
    'O cookie de sessão não possui as proteções obrigatórias.'
);

$csrfToken = davez_csrf_token();
security_foundation_assert(
    strlen($csrfToken) >= 40,
    'O token CSRF não possui entropia suficiente.'
);
security_foundation_assert(
    davez_csrf_validate($csrfToken),
    'O token CSRF válido foi rejeitado.'
);
security_foundation_assert(
    !davez_csrf_validate('token-invalido'),
    'Um token CSRF inválido foi aceito.'
);

davez_bootstrap_public_request_context();
$publicContext = $_SESSION['davez_public_request_context'] ?? null;
security_foundation_assert(
    is_array($publicContext)
    && is_string($publicContext['token'] ?? null),
    'O contexto público não foi criado.'
);

$_COOKIE[DAVEZ_PUBLIC_CONTEXT_COOKIE] = $publicContext['token'];
$_SERVER['HTTP_HOST'] = 'app.test';
$_SERVER['HTTP_ORIGIN'] = 'https://app.test';
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
security_foundation_assert(
    davez_public_request_context_valid(),
    'O contexto público same-origin foi rejeitado.'
);

davez_bootstrap_public_request_context();
security_foundation_assert(
    ($_SESSION['davez_public_request_context']['token'] ?? null)
        === $publicContext['token'],
    'O contexto público válido foi rotacionado durante o polling.'
);

$_COOKIE[DAVEZ_PUBLIC_CONTEXT_COOKIE] = 'token-de-outro-dispositivo';
davez_bootstrap_public_request_context();
security_foundation_assert(
    ($_SESSION['davez_public_request_context']['token'] ?? null)
        !== $publicContext['token'],
    'Um contexto público divergente não foi renegociado.'
);
$_COOKIE[DAVEZ_PUBLIC_CONTEXT_COOKIE]
    = $_SESSION['davez_public_request_context']['token'];

$_SERVER['HTTP_ORIGIN'] = 'https://attacker.test';
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
security_foundation_assert(
    !davez_public_request_context_valid(),
    'Uma mutação pública cross-site foi aceita.'
);
unset(
    $_SERVER['HTTP_ORIGIN'],
    $_SERVER['HTTP_SEC_FETCH_SITE'],
    $_SERVER['HTTP_HOST'],
    $_COOKIE[DAVEZ_PUBLIC_CONTEXT_COOKIE]
);

$originalHttps = $_SERVER['HTTPS'] ?? null;
$originalPort = $_SERVER['SERVER_PORT'] ?? null;
unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
$_SERVER['REMOTE_ADDR'] = '10.0.1.9';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

security_foundation_assert(
    !davez_is_https_request(),
    'X-Forwarded-Proto foi aceito sem proxy declarado.'
);

putenv('APP_TRUSTED_PROXIES=10.0.1.0/24');
security_foundation_assert(
    davez_is_https_request(),
    'X-Forwarded-Proto de um proxy confiável foi ignorado.'
);

$_SERVER['REMOTE_ADDR'] = '10.0.2.9';
security_foundation_assert(
    !davez_is_https_request(),
    'Um endereço fora da faixa confiável declarou HTTPS.'
);

$_SERVER['REMOTE_ADDR'] = '10.0.1.9';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http, https';
security_foundation_assert(
    !davez_is_https_request(),
    'O protocolo original em texto claro foi sobreposto pela cadeia de proxies.'
);

putenv('APP_TRUSTED_PROXIES=nao-e-um-endereco');
try {
    davez_is_https_request();
    security_foundation_fail(
        'Uma lista de proxies malformada foi aceita silenciosamente.'
    );
} catch (RuntimeException $exception) {
    // Comportamento esperado: falha explícita em vez de HTTP presumido.
}

putenv('APP_TRUSTED_PROXIES');
unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR']);

if ($originalHttps !== null) {
    $_SERVER['HTTPS'] = $originalHttps;
}

if ($originalPort !== null) {
    $_SERVER['SERVER_PORT'] = $originalPort;
}

try {
    davez_assert_no_untrusted_identity([
        'nome' => 'Pessoa de teste',
        'client_id' => 'nao-confiavel',
    ]);
    security_foundation_fail(
        'Um identificador fornecido pelo cliente foi aceito.'
    );
} catch (InvalidArgumentException $exception) {
    security_foundation_assert(
        !str_contains($exception->getMessage(), 'nao-confiavel'),
        'O valor sensível apareceu na mensagem de validação.'
    );
}

security_foundation_assert(
    davez_input_string(
        ['nome' => '  Pessoa de teste  '],
        'nome',
        2,
        80
    ) === 'Pessoa de teste',
    'A validação de texto não normalizou o valor.'
);
security_foundation_assert(
    davez_input_integer(['ordem' => '7'], 'ordem', 1, 1000) === 7,
    'A validação de inteiro rejeitou um valor válido.'
);
security_foundation_assert(
    davez_input_float(['latitude' => '-23.55'], 'latitude', -90, 90)
        === -23.55,
    'A validação numérica rejeitou uma coordenada válida.'
);

security_foundation_assert(
    davez_http_method_allowed('POST', ['POST', 'DELETE']),
    'A allowlist de métodos rejeitou POST.'
);
security_foundation_assert(
    !davez_http_method_allowed('GET', ['POST', 'DELETE']),
    'A allowlist de métodos aceitou GET indevidamente.'
);

$decoded = davez_decode_json_body('{"nome":"Teste"}', 128);
security_foundation_assert(
    ($decoded['nome'] ?? null) === 'Teste',
    'O decoder JSON não retornou o objeto esperado.'
);

try {
    davez_decode_json_body(str_repeat('x', 129), 128);
    security_foundation_fail('Um payload acima do limite foi aceito.');
} catch (LengthException $exception) {
    // Comportamento esperado.
}

$publicError = davez_public_error(
    'invalid_request',
    'Solicitação inválida.'
);
security_foundation_assert(
    !isset($publicError['trace'])
    && !isset($publicError['exception'])
    && ($publicError['error']['code'] ?? null) === 'invalid_request',
    'O envelope público de erro não está sanitizado.'
);

putenv('ADMIN_SESSION_IDLE_SECONDS=60');
$_SESSION['davez_admin_auth']['last_activity'] = time() - 61;
security_foundation_assert(
    !davez_admin_session_is_authenticated(),
    'Uma sessão administrativa expirada permaneceu válida.'
);

davez_admin_logout();

putenv('ADMIN_USER');
putenv('ADMIN_PASSWORD_HASH');
putenv('ADMIN_SESSION_IDLE_SECONDS');

foreach (glob($sessionDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($sessionDirectory);

fwrite(STDOUT, 'security_foundation_test: OK' . PHP_EOL);
