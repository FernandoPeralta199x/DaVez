<?php

declare(strict_types=1);

function fail_ci_contract(string $message): void
{
    fwrite(STDERR, "ci_read_only_contract_test: FAIL - {$message}" . PHP_EOL);
    exit(1);
}

function assert_ci_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fail_ci_contract($message);
    }
}

$root = dirname(__DIR__, 2);
$workflowPath = $root . '/.github/workflows/ci.yml';
$validationPath = $root . '/scripts/validate.ps1';

assert_ci_contract(is_file($workflowPath), 'O workflow de CI não foi encontrado.');
assert_ci_contract(is_file($validationPath), 'scripts/validate.ps1 não foi encontrado.');

$workflow = (string) file_get_contents($workflowPath);
$validation = (string) file_get_contents($validationPath);

assert_ci_contract(
    preg_match('/^permissions:\R\s+contents:\s+read\s*$/mi', $workflow) === 1,
    'A CI deve declarar somente contents: read.'
);
assert_ci_contract(
    preg_match('/^\s+[a-z-]+:\s+write\s*$/mi', $workflow) !== 1,
    'A CI não pode solicitar permissões de escrita.'
);

preg_match_all('/^\s+run:\s*(.+?)\s*$/mi', $workflow, $runMatches);
$runCommands = array_map(
    static function (string $command): string {
        return trim($command, " \t\n\r\0\x0B\"'");
    },
    $runMatches[1] ?? []
);
assert_ci_contract(
    $runCommands === ['./scripts/validate.ps1'],
    'O único comando shell da CI deve ser ./scripts/validate.ps1.'
);
assert_ci_contract(
    stripos($workflow, 'build-release.ps1') === false,
    'A CI de validação não pode criar artefatos de release.'
);
assert_ci_contract(
    !preg_match('/\b(deploy|publish|release|git\s+push)\b/i', implode("\n", $runCommands)),
    'A CI de validação não pode publicar ou fazer deploy.'
);

assert_ci_contract(
    preg_match('/php-version:\s*["\']8\.5["\']/i', $workflow) === 1,
    'A linha do runtime PHP deve estar pinada em 8.5.'
);
assert_ci_contract(
    preg_match('/node-version:\s*["\']24["\']/i', $workflow) === 1,
    'A linha do runtime Node.js deve estar pinada em 24.'
);

preg_match_all('/^\s+uses:\s*(\S+)\s*$/mi', $workflow, $usesMatches);
assert_ci_contract(
    count($usesMatches[1] ?? []) >= 3,
    'As actions esperadas não foram encontradas.'
);
foreach ($usesMatches[1] as $actionReference) {
    assert_ci_contract(
        preg_match('/@(?:v\d+|[a-f0-9]{40})$/i', $actionReference) === 1,
        "A action {$actionReference} usa uma referência flutuante."
    );
}

$forbiddenMutationCommands = [
    'Add-Content',
    'Copy-Item',
    'Move-Item',
    'New-Item',
    'Out-File',
    'Remove-Item',
    'Rename-Item',
    'Set-Content',
    'Start-Process',
];
foreach ($forbiddenMutationCommands as $command) {
    assert_ci_contract(
        stripos($validation, $command) === false,
        "validate.ps1 não pode usar o comando mutável {$command}."
    );
}

$forbiddenGitOperations = [' add ', ' commit ', ' push ', ' pull ', ' merge ', ' rebase ', ' reset ', ' clean '];
foreach ($forbiddenGitOperations as $operation) {
    assert_ci_contract(
        stripos($validation, $operation) === false,
        'validate.ps1 contém uma operação Git mutável: ' . trim($operation)
    );
}
assert_ci_contract(
    stripos($validation, 'diff --check') !== false,
    'A única verificação Git esperada, diff --check, não foi encontrada.'
);

assert_ci_contract(
    preg_match('/Get-ChildItem.+tests.+-Filter\s+[\'"]\*\.php[\'"]/is', $validation) === 1,
    'validate.ps1 deve descobrir os testes PHP.'
);
assert_ci_contract(
    preg_match('/Get-ChildItem.+tests.+-Filter\s+[\'"]\*\.js[\'"]/is', $validation) === 1,
    'validate.ps1 deve descobrir os testes JavaScript.'
);
assert_ci_contract(
    stripos($validation, "'.git', '.private', 'artifacts'") !== false,
    'validate.ps1 deve excluir diretórios privados e artefatos.'
);
assert_ci_contract(
    stripos($validation, "'config.php'") !== false,
    'validate.ps1 não pode ler ou executar o config.php real.'
);

echo 'ci_read_only_contract_test: OK' . PHP_EOL;
