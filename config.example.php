<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$requiredEnvironmentVariables = [
    'DB_HOST',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD',
    'ADMIN_USER',
    'ADMIN_PASSWORD',
];

foreach ($requiredEnvironmentVariables as $variableName) {
    $value = getenv($variableName);

    if ($value === false || trim($value) === '') {
        throw new RuntimeException(
            sprintf('Variável de ambiente obrigatória ausente: %s', $variableName)
        );
    }
}

$db_host = getenv('DB_HOST');
$db_name = getenv('DB_NAME');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASSWORD');

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    error_log('[DATABASE_CONNECTION_ERROR]');
    throw new RuntimeException('Não foi possível conectar ao banco de dados.');
}

define('ADMIN_USER', getenv('ADMIN_USER'));
define('ADMIN_PASS', getenv('ADMIN_PASSWORD'));

function require_admin(): void
{
    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $providedPassword = $_SERVER['PHP_AUTH_PW'] ?? '';

    $validUser = hash_equals((string) ADMIN_USER, (string) $providedUser);
    $validPassword = hash_equals((string) ADMIN_PASS, (string) $providedPassword);

    if (!$validUser || !$validPassword) {
        header('WWW-Authenticate: Basic realm="DaVez Administração"');
        http_response_code(401);
        exit('Autenticação necessária.');
    }
}
