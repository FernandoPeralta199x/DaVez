<?php

declare(strict_types=1);

/**
 * Impede o frontend de selecionar identidades ou escopos de autorização.
 *
 * @param array<string, mixed> $input
 * @param list<string> $forbiddenFields
 */
function davez_assert_no_untrusted_identity(
    array $input,
    array $forbiddenFields = [
        'user_id',
        'client_id',
        'admin_id',
        'tenant_id',
        'organization_id',
        'role',
        'permission',
    ]
): void {
    foreach ($forbiddenFields as $field) {
        if (array_key_exists($field, $input)) {
            throw new InvalidArgumentException(
                'A identidade deve ser derivada da sessão autenticada.'
            );
        }
    }
}

/**
 * @param array<string, mixed> $input
 * @param list<string> $allowedFields
 */
function davez_assert_allowed_input_keys(
    array $input,
    array $allowedFields
): void {
    $unexpected = array_diff(array_keys($input), $allowedFields);

    if ($unexpected !== []) {
        throw new InvalidArgumentException(
            'A solicitação contém campos não permitidos.'
        );
    }
}

function davez_utf8_length(string $value): int
{
    $matches = preg_match_all('/./us', $value, $characters);

    if ($matches === false) {
        throw new InvalidArgumentException('Texto com codificação inválida.');
    }

    return $matches;
}

/**
 * @param array<string, mixed> $input
 */
function davez_input_string(
    array $input,
    string $field,
    int $minimumLength,
    int $maximumLength,
    bool $required = true
): ?string {
    if ($minimumLength < 0 || $maximumLength < $minimumLength) {
        throw new InvalidArgumentException('Limites de texto inválidos.');
    }

    if (!array_key_exists($field, $input) || $input[$field] === null) {
        if ($required) {
            throw new InvalidArgumentException(
                sprintf('O campo %s é obrigatório.', $field)
            );
        }

        return null;
    }

    if (!is_string($input[$field])) {
        throw new InvalidArgumentException(
            sprintf('O campo %s deve ser texto.', $field)
        );
    }

    $value = trim($input[$field]);
    $length = davez_utf8_length($value);

    if ($length < $minimumLength || $length > $maximumLength) {
        throw new InvalidArgumentException(sprintf(
            'O campo %s deve ter entre %d e %d caracteres.',
            $field,
            $minimumLength,
            $maximumLength
        ));
    }

    return $value;
}

/**
 * @param array<string, mixed> $input
 */
function davez_input_integer(
    array $input,
    string $field,
    int $minimum,
    int $maximum
): int {
    $value = $input[$field] ?? null;
    $validated = filter_var($value, FILTER_VALIDATE_INT);

    if (
        $validated === false
        || $validated < $minimum
        || $validated > $maximum
    ) {
        throw new InvalidArgumentException(
            sprintf('O campo %s está fora do intervalo permitido.', $field)
        );
    }

    return $validated;
}

/**
 * @param array<string, mixed> $input
 */
function davez_input_float(
    array $input,
    string $field,
    float $minimum,
    float $maximum
): float {
    $value = $input[$field] ?? null;

    if (
        !is_int($value)
        && !is_float($value)
        && !(is_string($value) && is_numeric($value))
    ) {
        throw new InvalidArgumentException(
            sprintf('O campo %s deve ser numérico.', $field)
        );
    }

    $validated = (float) $value;

    if (
        !is_finite($validated)
        || $validated < $minimum
        || $validated > $maximum
    ) {
        throw new InvalidArgumentException(
            sprintf('O campo %s está fora do intervalo permitido.', $field)
        );
    }

    return $validated;
}
