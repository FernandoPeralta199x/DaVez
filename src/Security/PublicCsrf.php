<?php

declare(strict_types=1);

require_once __DIR__ . '/Session.php';
require_once dirname(__DIR__) . '/Http/JsonResponse.php';

const DAVEZ_PUBLIC_CONTEXT_COOKIE = 'davez_request_context';

/**
 * Cria um contexto de requisição host-only, HttpOnly e SameSite=Strict.
 *
 * A página pública chama session_info.php antes das mutações. Isso permite
 * proteger os formulários legados sem confiar em client_id ou expor um token ao
 * JavaScript.
 */
function davez_bootstrap_public_request_context(): void
{
    davez_start_secure_session(['same_site' => 'Strict']);

    $token = rtrim(
        strtr(base64_encode(random_bytes(32)), '+/', '-_'),
        '='
    );

    $_SESSION['davez_public_request_context'] = [
        'token' => $token,
        'issued_at' => time(),
    ];

    setcookie(DAVEZ_PUBLIC_CONTEXT_COOKIE, $token, [
        'expires' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => davez_is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function davez_request_has_cross_site_signal(): bool
{
    $fetchSite = strtolower(
        (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')
    );

    if (in_array($fetchSite, ['cross-site', 'none'], true)) {
        return true;
    }

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

    if ($origin === '') {
        return false;
    }

    if (strtolower($origin) === 'null') {
        return true;
    }

    $originParts = parse_url($origin);

    if (!is_array($originParts) || !isset($originParts['host'])) {
        return true;
    }

    $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $originHost = strtolower((string) $originParts['host']);
    $originPort = isset($originParts['port'])
        ? ':' . (int) $originParts['port']
        : '';
    $originScheme = strtolower((string) ($originParts['scheme'] ?? ''));
    $requestScheme = davez_is_https_request() ? 'https' : 'http';

    return $requestHost === ''
        || !hash_equals($requestHost, $originHost . $originPort)
        || !hash_equals($requestScheme, $originScheme);
}

function davez_public_request_context_valid(): bool
{
    davez_start_secure_session(['same_site' => 'Strict']);

    if (davez_request_has_cross_site_signal()) {
        return false;
    }

    $context = $_SESSION['davez_public_request_context'] ?? null;
    $cookieToken = $_COOKIE[DAVEZ_PUBLIC_CONTEXT_COOKIE] ?? null;

    if (
        !is_array($context)
        || !is_string($context['token'] ?? null)
        || !is_int($context['issued_at'] ?? null)
        || !is_string($cookieToken)
        || strlen($cookieToken) > 128
    ) {
        return false;
    }

    $maximumAge = davez_positive_environment_integer(
        'PUBLIC_REQUEST_CONTEXT_SECONDS',
        43200,
        300,
        86400
    );

    return time() - $context['issued_at'] <= $maximumAge
        && hash_equals($context['token'], $cookieToken);
}

function davez_require_public_request_context(): void
{
    if (!davez_public_request_context_valid()) {
        davez_send_error(
            'request_context_required',
            'Atualize a página antes de tentar novamente.',
            403
        );
    }
}
