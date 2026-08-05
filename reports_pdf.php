<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Infrastructure/Pdf/SimplePdfDocument.php';
require_once __DIR__ . '/src/Application/Reports/ReportListQuery.php';
require_once __DIR__ . '/src/Database/bootstrap.php';

davez_install_safe_exception_handler();
davez_require_http_method('GET');
davez_require_admin();

try {
    $rate = davez_rate_limit_consume(
        'admin-reports-index-pdf',
        davez_rate_limit_request_subject(),
        10,
        60
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'security_control_unavailable',
        'Controle de segurança temporariamente indisponível.',
        503
    );
}

if (!$rate['allowed']) {
    header('Retry-After: ' . $rate['retry_after']);
    davez_send_error(
        'rate_limit_exceeded',
        'Muitos arquivos solicitados. Aguarde e tente novamente.',
        429
    );
}

/** @return string */
function reports_pdf_date(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if ($value === '') {
        return '';
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException('Data inválida.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Data inválida.');
    }
    return $value;
}

try {
    davez_assert_allowed_input_keys($_GET, ['date_from', 'date_to']);
    $dateFrom = reports_pdf_date($_GET, 'date_from');
    $dateTo = reports_pdf_date($_GET, 'date_to');
    \DaVez\Application\Reports\ReportListQuery::assertDateFilter(
        $dateFrom,
        $dateTo,
        3660
    );
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'invalid_report_filter',
        $exception->getMessage(),
        400
    );
}

include __DIR__ . '/config.php';

$cycle = \DaVez\Domain\OperationalCycle::fromEnvironment();
date_default_timezone_set($cycle->timezone()->getName());
davez_configure_operational_database_timezone($conn, $cycle);
$context = new \DaVez\Domain\OperationalContext($cycle);

$reportQuery = new \DaVez\Application\Reports\ReportListQuery($conn);
try {
    $total = $reportQuery->count($dateFrom, $dateTo);
    $reportItems = $reportQuery->fetchForExport($dateFrom, $dateTo, 1000);
} catch (RuntimeException $exception) {
    davez_send_error('reports_unavailable', 'Relatórios indisponíveis.', 500);
}

$rows = [];
foreach ($reportItems as $row) {
    $rows[] = [
        '#' . (string) $row['id'],
        substr((string) $row['created_at'], 0, 16),
        substr((string) $row['periodo_inicio'], 0, 16),
        substr((string) $row['periodo_fim'], 0, 16),
        (string) $row['total_checkins'],
        (string) $row['motoboys_unicos'],
        (string) $row['total_fechados'],
    ];
}

$label = $dateFrom === ''
    ? 'Todos os relatórios'
    : $dateFrom . ' a ' . $dateTo;

$pdf = new \DaVez\Infrastructure\Pdf\SimplePdfDocument(
    'Índice de relatórios DaVez'
);
$pdf->addTitle('Índice de Relatórios DaVez');
$pdf->addParagraph(
    'Listagem administrativa dos relatórios operacionais persistidos. '
    . 'Cada relatório completo pode ser baixado individualmente no painel.'
);
$pdf->addHeading('Resumo');
$pdf->addKeyValue('Filtro', $label);
$pdf->addKeyValue('Total encontrado', (string) $total);
$pdf->addKeyValue(
    'Gerado em',
    $context->reference()->format('d/m/Y H:i:s T')
);
$pdf->addHeading('Relatórios');

if ($rows === []) {
    $pdf->addParagraph('Nenhum relatório foi encontrado para o filtro selecionado.');
} else {
    $pdf->addTable(
        ['ID', 'Criado', 'Início', 'Fim', 'Check-ins', 'Únicos', 'Fechados'],
        $rows,
        [7, 18, 18, 18, 10, 8, 10]
    );
}

if ((int) $total > count($rows)) {
    $pdf->addParagraph(
        'A exportação foi limitada aos 1.000 relatórios mais recentes. '
        . 'Use o filtro de data para reduzir o intervalo.'
    );
}

$output = $pdf->render();
$suffix = $dateFrom === '' ? 'todos' : $dateFrom . '-a-' . $dateTo;
$filename = 'davez-relatorios-' . $suffix . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($output));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
echo $output;
