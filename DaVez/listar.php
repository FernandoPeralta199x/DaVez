<?php
require_once __DIR__ . '/../src/Security/Bootstrap.php';
require_once __DIR__ . '/../src/Domain/OperationalCycle.php';
require_once __DIR__ . '/../src/Domain/OperationalContext.php';
davez_install_safe_exception_handler();
davez_require_http_method('GET');
include_once __DIR__ . "/../config.php";

date_default_timezone_set('America/Sao_Paulo');
$operationalContext = new \DaVez\Domain\OperationalContext(
  new \DaVez\Domain\OperationalCycle()
);
$dia = $operationalContext->date();

$stmt = $conn->prepare("
  SELECT id, client_id, nome, entered_at, status, last_action_at, ordem
  FROM fila_da_vez
  WHERE dia=?
  ORDER BY
    CASE WHEN status='na_fila' THEN 0 ELSE 1 END,
    ordem ASC,
    entered_at ASC
");

if (!$stmt) {
  davez_send_error(
    'queue_unavailable',
    'Fila temporariamente indisponível.',
    500
  );
}

$stmt->bind_param("s", $dia);
if (!$stmt->execute()) {
  $stmt->close();
  davez_send_error(
    'queue_unavailable',
    'Fila temporariamente indisponível.',
    500
  );
}
$res = $stmt->get_result();

$fila = [];
$daVez = null;

while($r = $res->fetch_assoc()){
  $fila[] = $r;
}

foreach($fila as $r){
  if (($r['status'] ?? '') === 'na_fila') {
    $daVez = $r;
    break;
  }
}

davez_send_json([
  "ok" => true,
  "dia" => $dia,
  "da_vez" => $daVez,
  "fila" => $fila
]);
