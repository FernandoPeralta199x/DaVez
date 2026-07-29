<?php

declare(strict_types=1);

function davez_normalize_path_for_comparison(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $normalized = rtrim($normalized, '/');

    return DIRECTORY_SEPARATOR === '\\'
        ? strtolower($normalized)
        : $normalized;
}

function davez_path_is_within(string $candidate, string $parent): bool
{
    $candidate = davez_normalize_path_for_comparison($candidate);
    $parent = davez_normalize_path_for_comparison($parent);

    return $candidate === $parent
        || str_starts_with($candidate, $parent . '/');
}

function davez_path_is_absolute(string $path): bool
{
    return str_starts_with($path, '/')
        || str_starts_with($path, '\\\\')
        || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function davez_rate_limit_directory(?string $configuredDirectory = null): string
{
    $directory = $configuredDirectory
        ?? (getenv('APP_RATE_LIMIT_DIR') ?: '');

    if ($directory === '') {
        throw new RuntimeException(
            'APP_RATE_LIMIT_DIR deve apontar para storage privado.'
        );
    }

    if (!davez_path_is_absolute($directory)) {
        throw new RuntimeException(
            'APP_RATE_LIMIT_DIR deve ser um caminho absoluto.'
        );
    }

    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException(
            'Não foi possível criar o diretório do rate limiter.'
        );
    }

    if (is_link($directory)) {
        throw new RuntimeException(
            'O diretório do rate limiter não pode ser um link simbólico.'
        );
    }

    $realDirectory = realpath($directory);

    if ($realDirectory === false || !is_writable($realDirectory)) {
        throw new RuntimeException(
            'O diretório do rate limiter não está disponível para escrita.'
        );
    }

    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $realDocumentRoot = is_string($documentRoot) && $documentRoot !== ''
        ? realpath($documentRoot)
        : false;

    if (
        $realDocumentRoot !== false
        && davez_path_is_within($realDirectory, $realDocumentRoot)
    ) {
        throw new RuntimeException(
            'O rate limiter deve ficar fora do web root.'
        );
    }

    @chmod($realDirectory, 0700);

    return $realDirectory;
}

function davez_rate_limit_secret(?string $configuredSecret = null): string
{
    $secret = $configuredSecret
        ?? (getenv('APP_RATE_LIMIT_SECRET') ?: '');

    if (strlen($secret) < 32) {
        throw new RuntimeException(
            'APP_RATE_LIMIT_SECRET deve ter ao menos 32 caracteres.'
        );
    }

    return $secret;
}

/**
 * Deriva o sujeito de uma informação fornecida pelo servidor.
 *
 * X-Forwarded-For não é usado porque só é confiável com proxy explicitamente
 * configurado.
 */
function davez_rate_limit_request_subject(): string
{
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        return 'network:unknown';
    }

    return 'network:' . $remoteAddress;
}

/**
 * Rate limiter de janela fixa com lock exclusivo e identificador em HMAC.
 *
 * @return array{
 *   allowed: bool,
 *   limit: int,
 *   remaining: int,
 *   retry_after: int
 * }
 */
function davez_rate_limit_consume(
    string $bucket,
    string $subject,
    int $limit,
    int $windowSeconds,
    ?string $storageDirectory = null,
    ?string $secret = null,
    ?int $now = null
): array {
    if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $bucket) !== 1) {
        throw new InvalidArgumentException('Bucket de rate limit inválido.');
    }

    if ($subject === '' || strlen($subject) > 512) {
        throw new InvalidArgumentException('Sujeito de rate limit inválido.');
    }

    if ($limit < 1 || $limit > 10000) {
        throw new InvalidArgumentException('Limite de requisições inválido.');
    }

    if ($windowSeconds < 1 || $windowSeconds > 604800) {
        throw new InvalidArgumentException('Janela de rate limit inválida.');
    }

    $directory = davez_rate_limit_directory($storageDirectory);
    $hmacSecret = davez_rate_limit_secret($secret);
    $timestamp = $now ?? time();
    $key = hash_hmac(
        'sha256',
        $bucket . "\0" . $subject,
        $hmacSecret
    );
    $stateFile = $directory . DIRECTORY_SEPARATOR . $key . '.json';
    $handle = fopen($stateFile, 'c+b');

    if ($handle === false) {
        throw new RuntimeException(
            'Não foi possível abrir o estado do rate limiter.'
        );
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(
                'Não foi possível bloquear o estado do rate limiter.'
            );
        }

        rewind($handle);
        $rawState = stream_get_contents($handle);
        $state = is_string($rawState) && $rawState !== ''
            ? json_decode($rawState, true)
            : null;
        $windowStart = is_array($state)
            ? filter_var($state['window_start'] ?? null, FILTER_VALIDATE_INT)
            : false;
        $count = is_array($state)
            ? filter_var($state['count'] ?? null, FILTER_VALIDATE_INT)
            : false;

        if (
            $windowStart === false
            || $count === false
            || $count < 0
            || $timestamp < $windowStart
            || $timestamp - $windowStart >= $windowSeconds
        ) {
            $windowStart = $timestamp;
            $count = 0;
        }

        $allowed = $count < $limit;

        if ($allowed) {
            $count++;
        }

        $encodedState = json_encode(
            [
                'window_start' => $windowStart,
                'count' => $count,
            ],
            JSON_THROW_ON_ERROR
        );

        rewind($handle);

        if (!ftruncate($handle, 0)) {
            throw new RuntimeException(
                'Não foi possível atualizar o rate limiter.'
            );
        }

        if (fwrite($handle, $encodedState) === false || !fflush($handle)) {
            throw new RuntimeException(
                'Não foi possível persistir o rate limiter.'
            );
        }

        @chmod($stateFile, 0600);
        flock($handle, LOCK_UN);

        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'remaining' => max(0, $limit - $count),
            'retry_after' => $allowed
                ? 0
                : max(1, $windowSeconds - ($timestamp - $windowStart)),
        ];
    } finally {
        fclose($handle);
    }
}
