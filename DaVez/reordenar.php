<?php
require_once __DIR__ . '/../src/Security/Bootstrap.php';
require_once __DIR__ . '/../src/Domain/OperationalCycle.php';
require_once __DIR__ . '/../src/Domain/OperationalContext.php';
require_once __DIR__ . '/../src/Domain/QueueStateChanged.php';
require_once __DIR__ . '/../src/Domain/QueueReorder.php';
require_once __DIR__ . '/../src/Database/bootstrap.php';
davez_install_safe_exception_handler();
davez_require_http_method('POST');
davez_require_admin();
davez_require_csrf();

try {
  $reorderRate = davez_rate_limit_consume(
    'admin-queue-reorder',
    davez_rate_limit_request_subject(),
    30,
    60
  );
} catch (RuntimeException $exception) {
  davez_send_error(
    'security_control_unavailable',
    'Controle de segurança temporariamente indisponível.',
    503
  );
}

if (!$reorderRate['allowed']) {
  header('Retry-After: ' . $reorderRate['retry_after']);
  davez_send_error(
    'rate_limit_exceeded',
    'Muitas alterações. Aguarde e tente novamente.',
    429
  );
}

include_once __DIR__ . "/../config.php";

date_default_timezone_set('America/Sao_Paulo');
$operationalContext = new \DaVez\Domain\OperationalContext(
  new \DaVez\Domain\OperationalCycle()
);

function json_out($data, $code = 200){
  davez_send_json($data, $code);
}

try {
  $data = davez_read_json_body(16384);
  davez_assert_allowed_input_keys($data, ['ordem', '_csrf']);
  davez_assert_no_untrusted_identity($data);
} catch (InvalidArgumentException $exception) {
  json_out(['ok' => false, 'err' => 'Payload inválido'], 400);
}

try {
  $ordemValidada = \DaVez\Domain\QueueReorder::normalize(
    $data['ordem'] ?? null,
    500
  );
} catch (InvalidArgumentException $exception) {
  json_out(['ok' => false, 'err' => 'Lista de ordem inválida'], 400);
}

$dia = $operationalContext->date();
$lockedTransactions = davez_locked_transaction_runner($conn);

try {
  $lockedTransactions->run(
    'fila_da_vez:' . $dia,
    static function () use ($conn, $dia, $ordemValidada): void {
      $current = $conn->prepare(
        "SELECT id
         FROM fila_da_vez
         WHERE dia=?
           AND status='na_fila'
         ORDER BY id
         FOR UPDATE"
      );

      if (!$current) {
        throw new RuntimeException('Fila indisponível para leitura.');
      }

      $current->bind_param("s", $dia);

      if (!$current->execute()) {
        $current->close();
        throw new RuntimeException('Fila indisponível para leitura.');
      }

      $currentResult = $current->get_result();
      $currentIds = [];

      while ($row = $currentResult->fetch_assoc()) {
        $currentIds[] = (int) $row['id'];
      }
      $current->close();

      \DaVez\Domain\QueueReorder::assertExactSet(
        $ordemValidada,
        $currentIds
      );

      $update = $conn->prepare(
        "UPDATE fila_da_vez
         SET ordem=?
         WHERE id=?
           AND dia=?
           AND status='na_fila'"
      );

      if (!$update) {
        throw new RuntimeException('Fila indisponível para atualização.');
      }

      foreach (
        \DaVez\Domain\QueueReorder::positions($ordemValidada)
        as $id => $position
      ) {
        $update->bind_param("iis", $position, $id, $dia);

        if (!$update->execute()) {
          $update->close();
          throw new RuntimeException('Falha ao atualizar a fila.');
        }
      }
      $update->close();

      $verify = $conn->prepare(
        "SELECT id, ordem
         FROM fila_da_vez
         WHERE dia=?
           AND status='na_fila'
         ORDER BY ordem ASC, id ASC
         FOR UPDATE"
      );

      if (!$verify) {
        throw new RuntimeException('Fila indisponível para verificação.');
      }

      $verify->bind_param("s", $dia);

      if (!$verify->execute()) {
        $verify->close();
        throw new RuntimeException('Fila indisponível para verificação.');
      }

      $verifiedResult = $verify->get_result();
      $verifiedIds = [];
      $expectedPosition = 1;

      while ($row = $verifiedResult->fetch_assoc()) {
        if ((int) $row['ordem'] !== $expectedPosition) {
          $verify->close();
          throw new RuntimeException('A sequência final da fila é inválida.');
        }

        $verifiedIds[] = (int) $row['id'];
        $expectedPosition++;
      }
      $verify->close();

      if ($verifiedIds !== $ordemValidada) {
        throw new RuntimeException('A ordem final diverge da solicitação.');
      }
    }
  );

  json_out([
    'ok' => true,
    'dia' => $dia
  ]);
} catch (\DaVez\Domain\QueueStateChanged $exception) {
  json_out([
    'ok' => false,
    'err' => 'A fila mudou. Atualize a página e tente novamente.'
  ], 409);
} catch (\DaVez\Database\LockUnavailable $exception) {
  header('Retry-After: 2');
  json_out([
    'ok' => false,
    'err' => 'Fila ocupada. Aguarde e tente novamente.'
  ], 503);
} catch (Throwable $exception) {
  json_out([
    'ok' => false,
    'err' => 'Falha ao atualizar ordem da fila'
  ], 500);
}
