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
  davez_assert_allowed_input_keys($_POST, ['id', '_csrf']);
  davez_assert_no_untrusted_identity($_POST);
  $exitRate = davez_rate_limit_consume(
    'admin-queue-exit',
    davez_rate_limit_request_subject(),
    60,
    60
  );
} catch (InvalidArgumentException $exception) {
  davez_send_error('invalid_request', 'Solicitação inválida.', 400);
} catch (RuntimeException $exception) {
  davez_send_error(
    'security_control_unavailable',
    'Controle de segurança temporariamente indisponível.',
    503
  );
}

if (!$exitRate['allowed']) {
  header('Retry-After: ' . $exitRate['retry_after']);
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

try {
  $id = davez_input_integer($_POST, 'id', 1, PHP_INT_MAX);
} catch (InvalidArgumentException $exception) {
  davez_send_error('invalid_id', 'ID inválido.', 400);
}

$dia = $operationalContext->date();
$lockedTransactions = davez_locked_transaction_runner($conn);

try {
  $lockedTransactions->run(
    'fila_da_vez:' . $dia,
    static function () use ($conn, $id, $dia): void {
      // Captura a linha antes do despacho para registrar a entrega.
      $lookup = $conn->prepare(
        "SELECT nome, checkin_id, entered_at
         FROM fila_da_vez
         WHERE id=?
           AND dia=?
           AND status='na_fila'
         LIMIT 1
         FOR UPDATE"
      );

      if (!$lookup) {
        throw new RuntimeException(
          'Fila indisponível para atualização.'
        );
      }

      $lookup->bind_param("is", $id, $dia);

      if (!$lookup->execute()) {
        $lookup->close();
        throw new RuntimeException(
          'Fila indisponível para atualização.'
        );
      }

      $dispatchedName = null;
      $dispatchedCheckinId = null;
      $dispatchedEnteredAt = null;
      $lookup->bind_result(
        $dispatchedName,
        $dispatchedCheckinId,
        $dispatchedEnteredAt
      );

      if (!$lookup->fetch()) {
        $lookup->close();
        throw new DomainException('queue_item_not_found');
      }
      $lookup->close();

      $statement = $conn->prepare(
        "UPDATE fila_da_vez
         SET status='em_entrega', last_action_at=NOW()
         WHERE id=?
           AND dia=?
           AND status='na_fila'
         LIMIT 1"
      );

      if (!$statement) {
        throw new RuntimeException(
          'Fila indisponível para atualização.'
        );
      }

      $statement->bind_param("is", $id, $dia);

      if (!$statement->execute()) {
        $statement->close();
        throw new RuntimeException(
          'Fila indisponível para atualização.'
        );
      }

      if ($statement->affected_rows <= 0) {
        $statement->close();
        throw new DomainException('queue_item_not_found');
      }

      $statement->close();

      // Registra a entrega num log durável (sobrevive ao fechamento do ciclo)
      // que alimenta o ranking. queue_wait_seconds mede o tempo na fila.
      $deliveryLog = $conn->prepare(
        "INSERT INTO delivery_events
           (operational_date, checkin_id, nome, dispatched_at,
            queue_wait_seconds)
         VALUES (?, ?, ?, NOW(),
            GREATEST(0, TIMESTAMPDIFF(SECOND, ?, NOW())))"
      );

      if (!$deliveryLog) {
        throw new RuntimeException(
          'Registro de entrega indisponível.'
        );
      }

      $deliveryLog->bind_param(
        "siss",
        $dia,
        $dispatchedCheckinId,
        $dispatchedName,
        $dispatchedEnteredAt
      );

      if (!$deliveryLog->execute()) {
        $deliveryLog->close();
        throw new RuntimeException(
          'Registro de entrega indisponível.'
        );
      }
      $deliveryLog->close();

      $remaining = $conn->prepare(
        "SELECT id
         FROM fila_da_vez
         WHERE dia=?
           AND status='na_fila'
         ORDER BY ordem ASC, id ASC
         FOR UPDATE"
      );

      if (!$remaining) {
        throw new RuntimeException(
          'Fila indisponível para compactação.'
        );
      }

      $remaining->bind_param("s", $dia);

      if (!$remaining->execute()) {
        $remaining->close();
        throw new RuntimeException(
          'Fila indisponível para compactação.'
        );
      }

      $remainingResult = $remaining->get_result();
      $remainingIds = [];

      while ($row = $remainingResult->fetch_assoc()) {
        $remainingIds[] = (int) $row['id'];
      }
      $remaining->close();

      $reorder = $conn->prepare(
        "UPDATE fila_da_vez
         SET ordem=?
         WHERE id=?
           AND dia=?
           AND status='na_fila'"
      );

      if (!$reorder) {
        throw new RuntimeException(
          'Fila indisponível para compactação.'
        );
      }

      foreach (
        \DaVez\Domain\QueueReorder::positions($remainingIds)
        as $remainingId => $position
      ) {
        $reorder->bind_param(
          "iis",
          $position,
          $remainingId,
          $dia
        );

        if (!$reorder->execute()) {
          $reorder->close();
          throw new RuntimeException(
            'Fila indisponível para compactação.'
          );
        }
      }
      $reorder->close();

      $verify = $conn->prepare(
        "SELECT ordem
         FROM fila_da_vez
         WHERE dia=?
           AND status='na_fila'
         ORDER BY ordem ASC, id ASC
         FOR UPDATE"
      );

      if (!$verify) {
        throw new RuntimeException(
          'Fila indisponível para verificação.'
        );
      }

      $verify->bind_param("s", $dia);

      if (!$verify->execute()) {
        $verify->close();
        throw new RuntimeException(
          'Fila indisponível para verificação.'
        );
      }

      $verifiedResult = $verify->get_result();
      $expectedPosition = 1;

      while ($row = $verifiedResult->fetch_assoc()) {
        if ((int) $row['ordem'] !== $expectedPosition) {
          $verify->close();
          throw new RuntimeException(
            'A sequência final da fila é inválida.'
          );
        }

        $expectedPosition++;
      }
      $verify->close();
    }
  );
} catch (DomainException $exception) {
  davez_send_error(
    'queue_item_not_found',
    'Registro não encontrado no ciclo operacional atual.',
    404
  );
} catch (\DaVez\Database\LockUnavailable $exception) {
  header('Retry-After: 2');
  davez_send_error(
    'queue_busy',
    'Fila ocupada. Aguarde e tente novamente.',
    503
  );
} catch (Throwable $exception) {
  davez_send_error(
    'queue_update_failed',
    'Não foi possível atualizar a fila.',
    500
  );
}

davez_send_json([
  "ok"=>true,
  "dia"=>$dia
]);
