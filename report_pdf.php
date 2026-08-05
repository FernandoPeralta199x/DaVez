<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/DeliveryRanking.php';
require_once __DIR__ . '/src/Infrastructure/Pdf/SimplePdfDocument.php';

davez_install_safe_exception_handler();
davez_require_http_method('GET');
davez_require_admin();

try {
    $pdfRate = davez_rate_limit_consume(
        'admin-report-pdf',
        davez_rate_limit_request_subject(),
        20,
        60
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'security_control_unavailable',
        'Controle de segurança temporariamente indisponível.',
        503
    );
}

if (!$pdfRate['allowed']) {
    header('Retry-After: ' . $pdfRate['retry_after']);
    davez_send_error(
        'rate_limit_exceeded',
        'Muitos relatórios solicitados. Aguarde e tente novamente.',
        429
    );
}

try {
    davez_assert_allowed_input_keys($_GET, ['id']);
    $reportId = davez_input_integer($_GET, 'id', 1, PHP_INT_MAX);
} catch (InvalidArgumentException $exception) {
    davez_send_error('invalid_report', 'Relatório inválido.', 400);
}

include __DIR__ . '/config.php';

$report = $conn->prepare(
    'SELECT id, periodo_inicio, periodo_fim, total_checkins,
            motoboys_unicos, total_fechados, payload_json, created_at
     FROM reports
     WHERE id=?
     LIMIT 1'
);

if (!$report) {
    davez_send_error('report_unavailable', 'Relatório indisponível.', 500);
}

$report->bind_param('i', $reportId);
if (!$report->execute()) {
    $report->close();
    davez_send_error('report_unavailable', 'Relatório indisponível.', 500);
}

$result = $report->get_result();
$row = $result->fetch_assoc();
$report->close();

if (!$row) {
    davez_send_error('report_not_found', 'Relatório não encontrado.', 404);
}

$payload = json_decode((string) ($row['payload_json'] ?? ''), true);
$items = is_array($payload) && is_array($payload['items'] ?? null)
    ? $payload['items']
    : [];

$operationalDate = substr((string) $row['periodo_inicio'], 0, 10);
$rankingRows = [];
$ranking = $conn->prepare(
    'SELECT nome, COUNT(*) AS entregas,
            COUNT(DISTINCT operational_date) AS dias_ativos,
            ROUND(AVG(queue_wait_seconds)) AS espera_media_seg
     FROM delivery_events
     WHERE operational_date=?
     GROUP BY nome
     ORDER BY entregas DESC, nome ASC
     LIMIT 500'
);

if ($ranking) {
    $ranking->bind_param('s', $operationalDate);
    if ($ranking->execute()) {
        $rankingResult = $ranking->get_result();
        $position = 1;
        while ($rankingRow = $rankingResult->fetch_assoc()) {
            $deliveries = (int) $rankingRow['entregas'];
            $activeDays = (int) $rankingRow['dias_ativos'];
            $rankingRows[] = [
                $position,
                (string) $rankingRow['nome'],
                $deliveries,
                $activeDays,
                \DaVez\Domain\DeliveryRanking::score($deliveries, $activeDays),
            ];
            $position++;
        }
    }
    $ranking->close();
}

$pdf = new \DaVez\Infrastructure\Pdf\SimplePdfDocument(
    'Relatório DaVez #' . $reportId
);
$pdf->addTitle('Relatório Operacional DaVez');
$pdf->addParagraph(
    'Documento gerado pelo painel administrativo com os dados persistidos '
    . 'no snapshot do ciclo operacional.'
);
$pdf->addHeading('Resumo');
$pdf->addKeyValue('Identificador', '#' . $reportId);
$pdf->addKeyValue('Período', (string) $row['periodo_inicio'] . ' a ' . (string) $row['periodo_fim']);
$pdf->addKeyValue('Gerado em', (string) $row['created_at']);
$pdf->addKeyValue('Total de check-ins', (string) $row['total_checkins']);
$pdf->addKeyValue('Entregadores únicos', (string) $row['motoboys_unicos']);
$pdf->addKeyValue('Finalizados', (string) $row['total_fechados']);

$pdf->addHeading('Check-ins do ciclo');
$checkinRows = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $checkinRows[] = [
        (string) ($item['ordem'] ?? '-'),
        (string) ($item['nome'] ?? ''),
        (string) ($item['data_hora'] ?? ''),
        (int) ($item['is_closed'] ?? 0) === 1 ? 'Finalizado' : 'Aberto',
    ];
}
if ($checkinRows === []) {
    $pdf->addParagraph('Nenhum item foi persistido no snapshot deste relatório.');
} else {
    $pdf->addTable(
        ['Ordem', 'Entregador', 'Check-in', 'Estado'],
        $checkinRows,
        [10, 36, 25, 15]
    );
}

$pdf->addHeading('Ranking do período');
if ($rankingRows === []) {
    $pdf->addParagraph('Nenhuma entrega foi registrada para este ciclo.');
} else {
    $pdf->addTable(
        ['#', 'Entregador', 'Entregas', 'Dias', 'Pontos'],
        $rankingRows,
        [6, 40, 12, 9, 12]
    );
}

$output = $pdf->render();
$filename = sprintf('davez-relatorio-%d.pdf', $reportId);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($output));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
echo $output;
