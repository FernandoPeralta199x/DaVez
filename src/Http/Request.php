<?php

declare(strict_types=1);

require_once __DIR__ . '/JsonResponse.php';

function davez_request_method(): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    return preg_match('/\A[A-Z]{3,16}\z/', $method) === 1 ? $method : 'GET';
}

/**
 * @param string|list<string> $allowedMethods
 */
function davez_http_method_allowed(
    string $method,
    string|array $allowedMethods
): bool {
    $allowed = is_array($allowedMethods) ? $allowedMethods : [$allowedMethods];
    $normalized = array_map(
        static fn (string $item): string => strtoupper(trim($item)),
        $allowed
    );

    return in_array(strtoupper($method), $normalized, true);
}

/**
 * @param string|list<string> $allowedMethods
 */
function davez_require_http_method(string|array $allowedMethods): void
{
    $allowed = is_array($allowedMethods) ? $allowedMethods : [$allowedMethods];
    $normalized = array_values(array_unique(array_filter(array_map(
        static fn (string $item): string => strtoupper(trim($item)),
        $allowed
    ))));

    if ($normalized === []) {
        throw new InvalidArgumentException('Ao menos um método HTTP deve ser permitido.');
    }

    if (!davez_http_method_allowed(davez_request_method(), $normalized)) {
        header('Allow: ' . implode(', ', $normalized));
        davez_send_error(
            'method_not_allowed',
            'Método HTTP não permitido.',
            405
        );
    }
}

/**
 * Decodifica um corpo JSON já lido, aplicando limite antes do parse.
 *
 * @return array<string, mixed>
 */
function davez_decode_json_body(string $rawBody, int $maxBytes = 32768): array
{
    if ($maxBytes < 1 || $maxBytes > 1048576) {
        throw new InvalidArgumentException('Limite de corpo JSON inválido.');
    }

    if (strlen($rawBody) > $maxBytes) {
        throw new LengthException('Corpo da requisição excede o limite permitido.');
    }

    if (trim($rawBody) === '') {
        return [];
    }

    try {
        $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new InvalidArgumentException('Corpo JSON inválido.');
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new InvalidArgumentException('O corpo JSON deve ser um objeto.');
    }

    return $decoded;
}

/**
 * Lê o corpo da requisição sem permitir payloads ilimitados.
 *
 * @return array<string, mixed>
 */
function davez_read_json_body(int $maxBytes = 32768): array
{
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;

    if (
        $contentLength !== null
        && ctype_digit((string) $contentLength)
        && (int) $contentLength > $maxBytes
    ) {
        davez_send_error(
            'payload_too_large',
            'Corpo da requisição excede o limite permitido.',
            413
        );
    }

    $rawBody = file_get_contents('php://input', false, null, 0, $maxBytes + 1);

    if ($rawBody === false) {
        davez_send_error(
            'invalid_request',
            'Não foi possível ler a solicitação.',
            400
        );
    }

    try {
        return davez_decode_json_body($rawBody, $maxBytes);
    } catch (LengthException $exception) {
        davez_send_error(
            'payload_too_large',
            'Corpo da requisição excede o limite permitido.',
            413
        );
    } catch (InvalidArgumentException $exception) {
        davez_send_error(
            'invalid_json',
            'Corpo JSON inválido.',
            400
        );
    }
}
