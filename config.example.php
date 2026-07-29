<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$requiredEnvironmentVariables = [
    'DB_HOST',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD',
    'ADMIN_USER',
    'ADMIN_PASSWORD_HASH',
    'APP_RATE_LIMIT_DIR',
    'APP_RATE_LIMIT_SECRET',
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

// A autenticação administrativa é feita por src/Security/AdminAuth.php.
// ADMIN_PASSWORD_HASH deve ser criado com password_hash(); nunca use senha
// administrativa em texto puro na configuração.
