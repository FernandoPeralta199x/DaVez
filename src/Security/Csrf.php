<?php

declare(strict_types=1);

require_once __DIR__ . '/Session.php';
require_once dirname(__DIR__) . '/Http/JsonResponse.php';

function davez_csrf_token(): string
{
    davez_start_secure_session();

    if (
        !isset($_SESSION['davez_csrf_token'])
        || !is_string($_SESSION['davez_csrf_token'])
    ) {
        $_SESSION['davez_csrf_token'] = rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '='
        );
    }

    return $_SESSION['davez_csrf_token'];
}

function davez_csrf_validate(?string $providedToken): bool
{
    davez_start_secure_session();

    $expectedToken = $_SESSION['davez_csrf_token'] ?? null;

    return is_string($providedToken)
        && is_string($expectedToken)
        && strlen($providedToken) <= 128
        && hash_equals($expectedToken, $providedToken);
}

function davez_csrf_token_from_request(): ?string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($headerToken) && $headerToken !== '') {
        return $headerToken;
    }

    $postToken = $_POST['_csrf'] ?? null;

    return is_string($postToken) ? $postToken : null;
}

function davez_require_csrf(?string $providedToken = null): void
{
    $token = $providedToken ?? davez_csrf_token_from_request();

    if (!davez_csrf_validate($token)) {
        davez_send_error(
            'csrf_validation_failed',
            'Token de segurança inválido ou expirado.',
            403
        );
    }
}
