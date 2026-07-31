<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Domain/DeliveryRanking.php';

use DaVez\Domain\DeliveryRanking;

function delivery_ranking_fail(string $message): never
{
    fwrite(STDERR, 'delivery_ranking_test: FAIL - ' . $message . PHP_EOL);
    exit(1);
}

function delivery_ranking_assert(bool $condition, string $message): void
{
    if (!$condition) {
        delivery_ranking_fail($message);
    }
}

// Limites do período, inclusivos e encerrando na data de referência.
$dia = DeliveryRanking::periodBounds('dia', '2026-07-31');
delivery_ranking_assert(
    $dia === ['start' => '2026-07-31', 'end' => '2026-07-31', 'days' => 1],
    'O período "dia" deve cobrir apenas a data de referência.'
);

$semana = DeliveryRanking::periodBounds('semana', '2026-07-31');
delivery_ranking_assert(
    $semana === ['start' => '2026-07-25', 'end' => '2026-07-31', 'days' => 7],
    'O período "semana" deve cobrir sete dias até a referência.'
);

$mes = DeliveryRanking::periodBounds('mes', '2026-07-31');
delivery_ranking_assert(
    $mes === ['start' => '2026-07-02', 'end' => '2026-07-31', 'days' => 30],
    'O período "mês" deve cobrir trinta dias até a referência.'
);

// A janela anterior tem o mesmo tamanho e termina um dia antes do início atual.
$anterior = DeliveryRanking::previousBounds('semana', '2026-07-31');
delivery_ranking_assert(
    $anterior === ['start' => '2026-07-18', 'end' => '2026-07-24', 'days' => 7],
    'A janela anterior deve ser contígua e de mesmo tamanho.'
);

// Período e data inválidos são recusados.
$rejeitou = false;
try {
    DeliveryRanking::periodBounds('ano', '2026-07-31');
} catch (InvalidArgumentException $exception) {
    $rejeitou = true;
}
delivery_ranking_assert($rejeitou, 'Período inválido deveria ser recusado.');

$rejeitouData = false;
try {
    DeliveryRanking::periodBounds('dia', '31-07-2026');
} catch (InvalidArgumentException $exception) {
    $rejeitouData = true;
}
delivery_ranking_assert($rejeitouData, 'Data malformada deveria ser recusada.');

// Pontuação transparente.
delivery_ranking_assert(
    DeliveryRanking::score(0, 0) === 0,
    'Sem atividade a pontuação deve ser zero.'
);
delivery_ranking_assert(
    DeliveryRanking::score(4, 3) === 55,
    'A pontuação deve somar entregas e dias ativos com os pesos definidos.'
);

// Evolução percentual.
delivery_ranking_assert(
    DeliveryRanking::evolutionPercent(15, 10) === 50,
    'A evolução deve refletir o crescimento percentual.'
);
delivery_ranking_assert(
    DeliveryRanking::evolutionPercent(5, 10) === -50,
    'A evolução deve refletir a queda percentual.'
);
delivery_ranking_assert(
    DeliveryRanking::evolutionPercent(7, 0) === null,
    'Sem base anterior, a evolução não deve inventar crescimento.'
);

fwrite(STDOUT, 'delivery_ranking_test: OK' . PHP_EOL);
