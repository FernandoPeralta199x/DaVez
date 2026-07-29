<?php

declare(strict_types=1);

/**
 * Monta um envelope de erro público sem expor exceções ou detalhes internos.
 *
 * @return array{ok: false, error: array{code: string, message: string}}
 */
function davez_public_error(string $code, string $message): array
{
    $safeCode = preg_match('/\A[a-z0-9_]{1,64}\z/', $code) === 1
        ? $code
        : 'request_failed';

    $safeMessage = trim($message);

    if ($safeMessage === '' || strlen($safeMessage) > 240) {
        $safeMessage = 'Não foi possível processar a solicitação.';
    }

    return [
        'ok' => false,
        'error' => [
            'code' => $safeCode,
            'message' => $safeMessage,
        ],
    ];
}

/**
 * Envia JSON com headers seguros e encerra a requisição.
 *
 * @param array<string, mixed> $payload
 */
function davez_send_json(array $payload, int $status = 200): never
{
    $safeStatus = $status >= 100 && $status <= 599 ? $status : 500;

    http_response_code($safeStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    try {
        $encoded = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (JsonException $exception) {
        http_response_code(500);
        $encoded = '{"ok":false,"error":{"code":"response_encoding_failed","message":"Não foi possível processar a resposta."}}';
    }

    echo $encoded;
    exit;
}

function davez_send_error(
    string $code,
    string $message,
    int $status = 400
): never {
    davez_send_json(davez_public_error($code, $message), $status);
}

/**
 * Garante que exceções não tratadas nunca retornem detalhes internos.
 */
function davez_install_safe_exception_handler(): void
{
    ini_set('display_errors', '0');

    set_exception_handler(static function (Throwable $exception): never {
        error_log('[UNHANDLED_APPLICATION_ERROR]');
        davez_send_error(
            'internal_error',
            'Não foi possível processar a solicitação.',
            500
        );
    });
}
