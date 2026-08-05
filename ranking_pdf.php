<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Security/Bootstrap.php';
require_once __DIR__ . '/src/Domain/OperationalCycle.php';
require_once __DIR__ . '/src/Domain/OperationalContext.php';
require_once __DIR__ . '/src/Domain/DeliveryRanking.php';
require_once __DIR__ . '/src/Application/Ranking/RankingQuery.php';
require_once __DIR__ . '/src/Infrastructure/Pdf/SimplePdfDocument.php';
require_once __DIR__ . '/src/Database/bootstrap.php';

davez_install_safe_exception_handler();
davez_require_http_method('GET');
davez_require_admin();

try {
    $rate = davez_rate_limit_consume(
        'admin-ranking-pdf',
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

try {
    davez_assert_allowed_input_keys(
        $_GET,
        ['periodo', 'date_from', 'date_to']
    );

    $period = is_string($_GET['periodo'] ?? null)
        ? trim($_GET['periodo'])
        : 'dia';
    $dateFrom = is_string($_GET['date_from'] ?? null)
        ? trim($_GET['date_from'])
        : '';
    $dateTo = is_string($_GET['date_to'] ?? null)
        ? trim($_GET['date_to'])
        : '';
} catch (InvalidArgumentException $exception) {
    davez_send_error('invalid_ranking_filter', 'Filtro de ranking inválido.', 400);
}

include __DIR__ . '/config.php';

$cycle = \DaVez\Domain\OperationalCycle::fromEnvironment();
date_default_timezone_set($cycle->timezone()->getName());
davez_configure_operational_database_timezone($conn, $cycle);
$context = new \DaVez\Domain\OperationalContext($cycle);
$operationalDate = $context->date();

try {
    if ($dateFrom !== '' || $dateTo !== '') {
        if ($dateFrom === '' || $dateTo === '') {
            throw new InvalidArgumentException(
                'Informe data inicial e final para gerar o ranking.'
            );
        }
        $period = 'custom';
        $bounds = \DaVez\Domain\DeliveryRanking::customBounds(
            $dateFrom,
            $dateTo,
            366
        );
        $previous = \DaVez\Domain\DeliveryRanking::previousCustomBounds(
            $dateFrom,
            $dateTo,
            366
        );
    } else {
        $bounds = \DaVez\Domain\DeliveryRanking::periodBounds(
            $period,
            $operationalDate
        );
        $previous = \DaVez\Domain\DeliveryRanking::previousBounds(
            $period,
            $operationalDate
        );
    }

    $query = new \DaVez\Application\Ranking\RankingQuery($conn);
    $result = $query->fetchPage($bounds, $previous, 1, 500);
} catch (InvalidArgumentException $exception) {
    davez_send_error(
        'invalid_ranking_filter',
        $exception->getMessage(),
        400
    );
} catch (RuntimeException $exception) {
    davez_send_error(
        'ranking_unavailable',
        'Ranking temporariamente indisponível.',
        500
    );
}

function ranking_pdf_wait(?int $seconds): string
{
    if ($seconds === null) {
        return '-';
    }
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;
    return $remaining === 0
        ? $minutes . 'min'
        : sprintf('%dmin %02ds', $minutes, $remaining);
}

$rows = [];
foreach ($result['ranking'] as $entry) {
    $rows[] = [
        (string) $entry['position'],
        (string) $entry['nome'],
        (string) $entry['entregas'],
        (string) $entry['dias_ativos'],
        number_format((float) $entry['media_dia'], 1, ',', '.'),
        ranking_pdf_wait($entry['espera_media_seg']),
        (string) $entry['pontuacao'],
    ];
}

$pdf = new \DaVez\Infrastructure\Pdf\SimplePdfDocument(
    'Ranking DaVez ' . $bounds['start'] . ' a ' . $bounds['end']
);
$pdf->addTitle('Ranking Operacional DaVez');
$pdf->addParagraph(
    'Classificação calculada a partir dos despachos registrados no período. '
    . 'A pontuação considera entregas e dias ativos.'
);
$pdf->addHeading('Resumo');
$pdf->addKeyValue('Período', $bounds['start'] . ' a ' . $bounds['end']);
$pdf->addKeyValue('Entregadores classificados', (string) $result['total']);
$pdf->addKeyValue(
    'Gerado em',
    $context->reference()->format('d/m/Y H:i:s T')
);
$pdf->addKeyValue('Ciclo operacional', $cycle->startTimeLabel());
$pdf->addHeading('Classificação');

if ($rows === []) {
    $pdf->addParagraph('Nenhuma entrega foi registrada no período selecionado.');
} else {
    $pdf->addTable(
        ['#', 'Entregador', 'Entregas', 'Dias', 'Média', 'Espera', 'Pontos'],
        $rows,
        [5, 30, 10, 7, 8, 13, 9]
    );
}

if ($result['total'] > count($rows)) {
    $pdf->addParagraph(
        'O arquivo foi limitado aos primeiros 500 entregadores. '
        . 'Refine o intervalo para obter a listagem completa.'
    );
}

$output = $pdf->render();
$filename = sprintf(
    'davez-ranking-%s-a-%s.pdf',
    $bounds['start'],
    $bounds['end']
);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($output));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
echo $output;
