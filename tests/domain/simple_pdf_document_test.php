<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Infrastructure/Pdf/SimplePdfDocument.php';

use DaVez\Infrastructure\Pdf\SimplePdfDocument;

$pdf = new SimplePdfDocument('Teste DaVez');
$pdf->addTitle('Relatório de validação');
$pdf->addKeyValue('Ciclo', '01:30');
$pdf->addTable(
    ['#', 'Nome', 'Entregas'],
    [
        [1, 'João da Silva', 12],
        [2, 'Maria Souza', 8],
    ],
    [6, 40, 14]
);

for ($index = 0; $index < 120; $index++) {
    $pdf->addParagraph(
        'Linha de carga ' . $index . ' para validar paginação automática.'
    );
}

$output = $pdf->render();

if (!str_starts_with($output, '%PDF-1.4')) {
    fwrite(STDERR, "simple_pdf_document_test: FAIL - cabeçalho inválido" . PHP_EOL);
    exit(1);
}

if (substr_count($output, '/Type /Page ') < 2) {
    fwrite(STDERR, "simple_pdf_document_test: FAIL - paginação não ocorreu" . PHP_EOL);
    exit(1);
}

if (!str_ends_with($output, "%%EOF\n")) {
    fwrite(STDERR, "simple_pdf_document_test: FAIL - trailer inválido" . PHP_EOL);
    exit(1);
}

echo 'simple_pdf_document_test: OK' . PHP_EOL;
