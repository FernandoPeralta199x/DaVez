<?php

declare(strict_types=1);

function fail_release_contract(string $message): void
{
    fwrite(STDERR, "release_allowlist_contract_test: FAIL - {$message}" . PHP_EOL);
    exit(1);
}

function assert_release_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fail_release_contract($message);
    }
}

/**
 * @return array<int, string>
 */
function read_powershell_literal_array(string $script, string $variable): array
{
    $pattern = '/\$' . preg_quote($variable, '/') . '\s*=\s*@\((.*?)\)/s';
    if (preg_match($pattern, $script, $blockMatch) !== 1) {
        fail_release_contract("A allowlist \${$variable} não foi encontrada.");
    }

    preg_match_all('/\'([^\']+)\'/', $blockMatch[1], $valueMatches);
    return $valueMatches[1] ?? [];
}

$root = dirname(__DIR__, 2);
$buildPath = $root . '/scripts/build-release.ps1';
$gitignorePath = $root . '/.gitignore';

assert_release_contract(is_file($buildPath), 'scripts/build-release.ps1 não foi encontrado.');
assert_release_contract(is_file($gitignorePath), '.gitignore não foi encontrado.');

$build = (string) file_get_contents($buildPath);
$gitignore = (string) file_get_contents($gitignorePath);
$releaseFiles = read_powershell_literal_array($build, 'releaseFiles');
$releaseDirectories = read_powershell_literal_array($build, 'releaseDirectories');

assert_release_contract($releaseFiles !== [], 'A allowlist de arquivos está vazia.');
assert_release_contract($releaseDirectories !== [], 'A allowlist de diretórios está vazia.');

foreach (array_merge($releaseFiles, $releaseDirectories) as $allowedPath) {
    assert_release_contract(
        preg_match('/[*?\[\]]/', $allowedPath) !== 1,
        "A allowlist não pode conter glob: {$allowedPath}"
    );
    assert_release_contract(
        !str_starts_with(str_replace('\\', '/', $allowedPath), '/'),
        "A allowlist deve usar caminhos relativos: {$allowedPath}"
    );
    assert_release_contract(
        strpos(str_replace('\\', '/', $allowedPath), '../') === false,
        "A allowlist não pode escapar da raiz: {$allowedPath}"
    );
}

$forbiddenFileEntries = ['config.php', '.env'];
foreach ($forbiddenFileEntries as $forbiddenEntry) {
    assert_release_contract(
        !in_array($forbiddenEntry, $releaseFiles, true),
        "O arquivo proibido {$forbiddenEntry} está na allowlist."
    );
}
assert_release_contract(
    in_array('.env.example', $releaseFiles, true),
    'O template seguro .env.example deve acompanhar o release.'
);

$forbiddenDirectoryEntries = [
    '.git',
    '.private',
    'artifacts',
    'coverage',
    'logs',
    'node_modules',
    'reports',
    'tests',
    'vendor',
];
$forbiddenNestedDirectoryEntries = [
    '.git',
    '.private',
    'artifacts',
    'coverage',
    'node_modules',
    'vendor',
];
foreach ($forbiddenDirectoryEntries as $forbiddenEntry) {
    assert_release_contract(
        !in_array($forbiddenEntry, $releaseDirectories, true),
        "O diretório proibido {$forbiddenEntry} está na allowlist."
    );
}

$overwriteCheckPosition = strpos(
    $build,
    '(Test-Path -LiteralPath $stageRoot) -or (Test-Path -LiteralPath $archivePath)'
);
$stageCreationPosition = strpos(
    $build,
    'New-Item -ItemType Directory -Path $stageRoot -Force:$false'
);
assert_release_contract(
    $overwriteCheckPosition !== false
    && $stageCreationPosition !== false
    && $overwriteCheckPosition < $stageCreationPosition,
    'O script deve recusar stage/ZIP existentes antes de criar qualquer arquivo.'
);

$requiredStageGuards = [
    '$forbiddenRootDirectoryNames',
    '$forbiddenDirectoryNames',
    "'logs'",
    "'reports'",
    "'tests'",
    "'config.php'",
    '$isEnvironmentFile',
    '$isAllowedEnvExample',
    "'.bak'",
    "'.dump'",
    "'.log'",
    "'.zip'",
    "'*.sql.gz'",
];
foreach ($requiredStageGuards as $guard) {
    assert_release_contract(
        strpos($build, $guard) !== false,
        "A inspeção do stage não contém a proteção {$guard}."
    );
}

foreach ($releaseDirectories as $relativeDirectory) {
    $sourceDirectory = $root . '/' . str_replace('\\', '/', $relativeDirectory);
    assert_release_contract(
        is_dir($sourceDirectory),
        "O diretório permitido não existe: {$relativeDirectory}"
    );

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $entry) {
        $relativeEntry = str_replace(
            '\\',
            '/',
            substr($entry->getPathname(), strlen($sourceDirectory) + 1)
        );
        $segments = explode('/', strtolower($relativeEntry));

        foreach ($forbiddenNestedDirectoryEntries as $forbiddenSegment) {
            assert_release_contract(
                !in_array(strtolower($forbiddenSegment), $segments, true),
                "{$relativeDirectory} contém o caminho proibido {$relativeEntry}."
            );
        }

        if ($entry->isFile()) {
            $name = strtolower($entry->getFilename());
            $isRootEnvExample = strtolower($relativeDirectory . '/' . $relativeEntry) === '.env.example';
            assert_release_contract(
                $name !== 'config.php',
                "{$relativeDirectory} contém config.php."
            );
            assert_release_contract(
                !str_starts_with($name, '.env') || $isRootEnvExample,
                "{$relativeDirectory} contém arquivo de ambiente: {$relativeEntry}."
            );
            assert_release_contract(
                !preg_match('/\.(?:bak|dump|log|zip|sql\.gz)$/i', $name),
                "{$relativeDirectory} contém backup ou dado de runtime: {$relativeEntry}."
            );
        }
    }
}

$normalizedIgnoreLines = array_map(
    'trim',
    preg_split('/\R/', $gitignore) ?: []
);
assert_release_contract(
    in_array('/artifacts/', $normalizedIgnoreLines, true),
    '.gitignore deve ignorar /artifacts/.'
);

foreach (['/config.php', '/.env', '/logs/*', '/reports/*'] as $requiredIgnore) {
    assert_release_contract(
        in_array($requiredIgnore, $normalizedIgnoreLines, true),
        ".gitignore não contém a proteção {$requiredIgnore}."
    );
}

echo 'release_allowlist_contract_test: OK' . PHP_EOL;
