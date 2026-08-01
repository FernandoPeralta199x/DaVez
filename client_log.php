<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/log.php';

davez_install_safe_exception_handler();

// Endpoint best-effort: o celular do motoboy relata o código do erro que ele
// encontrou para cair na aba Suporte/Logs. Nunca recebe texto livre — apenas um
// código curto (slug) que o painel traduz em motivo e solução.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.html', true, 303);
    exit;
}

davez_require_http_method('POST');
davez_require_public_request_context();

try {
    davez_assert_allowed_input_keys(
        $_POST,
        ['code', 'context', 'status']
    );
    $logRate = davez_rate_limit_consume(
        'public-client-log',
        davez_rate_limit_request_subject(),
        30,
        60
    );
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'invalid_request',
        'Dados de log inválidos.',
        400
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'security_control_unavailable',
        'Serviço temporariamente indisponível.',
        503
    );
}

if (!$logRate['allowed']) {
    header('Retry-After: ' . $logRate['retry_after']);
    davez_send_error(
        'rate_limit_exceeded',
        'Muitos registros. Aguarde e tente novamente.',
        429
    );
}

// code: obrigatório, slug seguro.
$rawCode = isset($_POST['code']) && is_string($_POST['code'])
    ? strtolower(trim($_POST['code']))
    : '';

if (
    $rawCode === ''
    || preg_match('/\A[a-z0-9_]{1,40}\z/', $rawCode) !== 1
) {
    davez_send_error(
        'invalid_code',
        'Código de erro inválido.',
        400
    );
}

// context: opcional, slug curto. Fallback seguro se ausente ou malformado.
$context = 'app';
if (isset($_POST['context']) && is_string($_POST['context'])) {
    $candidateContext = strtolower(trim($_POST['context']));
    if (preg_match('/\A[a-z0-9_]{1,20}\z/', $candidateContext) === 1) {
        $context = $candidateContext;
    }
}

// status: opcional, código HTTP inteiro (0..599).
$status = null;
if (
    isset($_POST['status'])
    && is_string($_POST['status'])
    && ctype_digit($_POST['status'])
) {
    $parsedStatus = (int) $_POST['status'];
    if ($parsedStatus >= 0 && $parsedStatus <= 599) {
        $status = $parsedStatus;
    }
}

log_event('CLIENT_ERROR', [
    'client_code' => $rawCode,
    'client_context' => $context,
    'client_status' => $status,
]);

davez_send_json([
    'ok' => true,
]);
