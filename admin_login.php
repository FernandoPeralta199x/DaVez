<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Config/FeatureFlags.php';
require_once __DIR__ . '/src/Security/OpaqueToken.php';
require_once __DIR__ . '/src/Security/AdminSession.php';
require_once __DIR__ . '/src/Security/AdminAuthenticator.php';
require_once __DIR__ . '/src/Database/UserStore.php';
require_once __DIR__ . '/src/Database/bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';

use DaVez\Config\FeatureFlags;
use DaVez\Database\UserStore;
use DaVez\Security\AdminAuthenticator;
use DaVez\Security\AdminAuthResult;
use DaVez\Security\OpaqueToken;

davez_install_safe_exception_handler();

// Enquanto a flag estiver desligada, o endpoint não existe: o admin atual
// (por variável de ambiente) continua funcionando sem qualquer alteração.
if (!FeatureFlags::enabled('admin_users_db')) {
    davez_send_error('not_found', 'Recurso indisponível.', 404);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: admin.php', true, 303);
    exit;
}

davez_require_http_method('POST');

// CSRF por origem: recusa requisições cross-site.
if (davez_request_has_cross_site_signal()) {
    davez_send_error('request_context_required', 'Origem inválida.', 403);
}

try {
    davez_assert_allowed_input_keys($_POST, ['login', 'password']);
    $loginRate = davez_rate_limit_consume(
        'admin-login',
        davez_rate_limit_request_subject(),
        10,
        60
    );
} catch (InvalidArgumentException $exception) {
    davez_send_error('invalid_request', 'Dados de login inválidos.', 400);
} catch (RuntimeException $exception) {
    davez_send_error('security_control_unavailable', 'Serviço temporariamente indisponível.', 503);
}

if (!$loginRate['allowed']) {
    header('Retry-After: ' . $loginRate['retry_after']);
    davez_send_error('rate_limit_exceeded', 'Muitas tentativas. Aguarde e tente novamente.', 429);
}

try {
    $login = davez_input_string($_POST, 'login', 1, 120);
    $password = davez_input_string($_POST, 'password', 1, 256);
} catch (InvalidArgumentException $exception) {
    davez_send_error('invalid_credentials', 'Login ou senha inválidos.', 401);
}

include __DIR__ . '/config.php';
require_once __DIR__ . '/log.php';

$cycle = \DaVez\Domain\OperationalCycle::fromEnvironment();
date_default_timezone_set($cycle->timezone()->getName());
davez_configure_operational_database_timezone($conn, $cycle);
$now = new DateTimeImmutable('now', $cycle->timezone());

$store = new UserStore($conn);
$authenticator = new AdminAuthenticator($store);

try {
    $result = $authenticator->authenticate($login, $password, $now);
} catch (Throwable $exception) {
    log_event('ADMIN_LOGIN_FAILED');
    davez_send_error('login_failed', 'Não foi possível autenticar.', 500);
}

if (!$result->isSuccess()) {
    switch ($result->status()) {
        case AdminAuthResult::LOCKED:
            davez_send_error('account_locked', 'Conta temporariamente bloqueada por tentativas. Aguarde e tente novamente.', 423);
            // no break: davez_send_error encerra a execução.
        case AdminAuthResult::SUSPENDED:
            davez_send_error('account_suspended', 'Conta suspensa. Contate o suporte.', 403);
        default:
            davez_send_error('invalid_credentials', 'Login ou senha inválidos.', 401);
    }
}

// Sucesso: cria a sessão administrativa (só o hash vai ao banco).
$context = $result->context();
$token = OpaqueToken::generate();
$expiresAt = $now->add(new DateInterval('PT12H'));
$ipHash = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
    ? hash('sha256', $_SERVER['REMOTE_ADDR'], true)
    : null;

try {
    $store->createSession(
        (int) $result->userId(),
        $context->tenantId(),
        $context->role(),
        OpaqueToken::hash($token),
        $now,
        $expiresAt,
        $ipHash
    );
    davez_set_admin_cookie($token, $expiresAt);
} catch (Throwable $exception) {
    log_event('ADMIN_SESSION_CREATE_FAILED');
    davez_send_error('login_failed', 'Não foi possível iniciar a sessão.', 500);
}

davez_send_json([
    'ok' => true,
    'role' => $context->role(),
    'must_change_password' => $result->mustChangePassword(),
]);
