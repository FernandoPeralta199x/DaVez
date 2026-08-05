<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/src/Application/Reports/ReportListQuery.php';
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "Não foi possível ler ReportListQuery.php.\n");
    exit(1);
}

$assertions = [
    'paginação parametrizada' => str_contains($source, 'LIMIT ? OFFSET ?'),
    'binding de filtros e paginação' => str_contains($source, "'ssssii'"),
    'ordenação determinística' => str_contains($source, 'ORDER BY created_at DESC, id DESC'),
    'limite de página' => str_contains($source, '$perPage > 50'),
    'limite de exportação' => str_contains($source, '$limit > 5000'),
    'filtro inicial indexável' => str_contains($source, "periodo_inicio >= CONCAT(?, ' 00:00:00')"),
    'filtro final semiaberto' => str_contains($source, "periodo_inicio < DATE_ADD(CONCAT(?, ' 00:00:00'), INTERVAL 1 DAY)"),
    'sem função DATE na coluna' => preg_match('/DATE\s*\(\s*periodo_inicio\s*\)/i', $source) !== 1,
];

foreach ($assertions as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Falha na política ReportListQuery: {$label}.\n");
        exit(1);
    }
}

require_once $path;

use DaVez\Application\Reports\ReportListQuery;

ReportListQuery::assertDateFilter('', '', 3660);
ReportListQuery::assertDateFilter('2026-08-01', '2026-08-31', 3660);

$invalidRanges = [
    ['2026-08-01', '', 3660],
    ['2026-08-31', '2026-08-01', 3660],
    ['2026-02-30', '2026-03-01', 3660],
    ['2020-01-01', '2030-01-01', 30],
];

foreach ($invalidRanges as [$from, $to, $maximumDays]) {
    try {
        ReportListQuery::assertDateFilter($from, $to, $maximumDays);
        fwrite(STDERR, "Intervalo inválido foi aceito: {$from}..{$to}.\n");
        exit(1);
    } catch (InvalidArgumentException $exception) {
        // Esperado.
    }
}

fwrite(STDOUT, "report_list_query_policy_test: OK\n");
