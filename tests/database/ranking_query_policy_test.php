<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = (string) file_get_contents(
    $root . '/src/Application/Ranking/RankingQuery.php'
);

function ranking_query_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'ranking_query_policy_test: FAIL - ' . $message . PHP_EOL);
        exit(1);
    }
}

ranking_query_assert(
    str_contains($source, 'LIMIT ? OFFSET ?'),
    'A paginação deve ocorrer no banco com parâmetros vinculados.'
);
ranking_query_assert(
    str_contains($source, "bind_param('ssii'"),
    'Datas, limite e offset devem ser vinculados.'
);
ranking_query_assert(
    str_contains($source, 'COUNT(DISTINCT nome)'),
    'A contagem total deve ser executada separadamente.'
);
ranking_query_assert(
    str_contains($source, 'AND nome IN ('),
    'Série e período anterior devem limitar-se à página atual.'
);
ranking_query_assert(
    str_contains($source, 'array_fill(0, count($names), \'?\')'),
    'A consulta dinâmica deve usar placeholders.'
);
ranking_query_assert(
    !preg_match('/WHERE[^;]*\\$names/s', $source),
    'Nomes não podem ser interpolados diretamente na consulta.'
);
ranking_query_assert(
    str_contains($source, 'if ($perPage < 1 || $perPage > 500)'),
    'O tamanho máximo da página deve ser limitado.'
);

echo 'ranking_query_policy_test: OK' . PHP_EOL;
