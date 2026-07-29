<?php

declare(strict_types=1);

function fail_operations_docs_contract(string $message): void
{
    fwrite(STDERR, "operations_docs_contract_test: FAIL - {$message}" . PHP_EOL);
    exit(1);
}

function assert_operations_docs_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fail_operations_docs_contract($message);
    }
}

function read_operations_document(string $path): string
{
    if (!is_file($path)) {
        fail_operations_docs_contract('Documento ausente: ' . basename($path));
    }

    $content = (string) file_get_contents($path);
    if (trim($content) === '') {
        fail_operations_docs_contract('Documento vazio: ' . basename($path));
    }

    return $content;
}

$root = dirname(__DIR__, 2);
$deployment = read_operations_document($root . '/docs/DEPLOYMENT.md');
$backupRestore = read_operations_document($root . '/docs/BACKUP_RESTORE.md');
$operations = read_operations_document($root . '/docs/OPERATIONS.md');

$deploymentRequirements = [
    'Backup concluído',
    'restauração previamente testada',
    '## Rollback',
    'Secrets fornecidos por mecanismo externo',
    'código, repositório e artefato',
    'secret manager',
    'usuário do banco sem privilégios DDL',
    '.\\scripts\\validate.ps1',
];
foreach ($deploymentRequirements as $requirement) {
    assert_operations_docs_contract(
        stripos($deployment, $requirement) !== false,
        "DEPLOYMENT.md não exige: {$requirement}"
    );
}

$backupRequirements = [
    'backups devem ser criptografados',
    'fora do webroot',
    'mecanismo seguro',
    'Não coloque senha',
    'histórico do terminal',
    'um backup não é considerado válido até a restauração ser testada',
    'O arquivo produzido não pode ser copiado para `X:\\Help`',
    'backup extraordinário antes de migration',
];
foreach ($backupRequirements as $requirement) {
    assert_operations_docs_contract(
        stripos($backupRestore, $requirement) !== false,
        "BACKUP_RESTORE.md não exige: {$requirement}"
    );
}

$operationsRequirements = [
    'sucesso e duração de backups',
    'backup ausente ou restauração de teste vencida',
    'nunca registrar senha, token, cookie',
    'Validar rollback e recuperação',
];
foreach ($operationsRequirements as $requirement) {
    assert_operations_docs_contract(
        stripos($operations, $requirement) !== false,
        "OPERATIONS.md não exige: {$requirement}"
    );
}

echo 'operations_docs_contract_test: OK' . PHP_EOL;
