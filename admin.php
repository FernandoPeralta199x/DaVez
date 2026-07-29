<?php
session_start();
include "config.php";
require_admin();

date_default_timezone_set('America/Sao_Paulo');

function json_out($data, $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

/* =========================================================
   HELPERS DE PERÍODO OPERACIONAL
   Dia operacional = 06:00 -> 05:59:59 do dia seguinte
========================================================= */
function get_operational_bounds(?DateTime $ref = null){
  $tz = new DateTimeZone('America/Sao_Paulo');
  $now = $ref ? clone $ref : new DateTime('now', $tz);

  $start = clone $now;
  $start->setTime(6, 0, 0);

  // Antes das 06:00 ainda conta como dia operacional anterior
  if ((int)$now->format('H') < 6) {
    $start->modify('-1 day');
  }

  $end = clone $start;
  $end->modify('+1 day');

  return [$start, $end];
}

function get_current_operational_date(){
  [$start, $end] = get_operational_bounds();
  return $start->format('Y-m-d');
}

/* =========================================================
   HELPERS DE TOKEN
   Token gira a cada 3 dias operacionais, às 06:00
   token_data guarda a data base do ciclo (YYYY-MM-DD)
========================================================= */
function generate_token_code(){
  return strtoupper(substr(md5(uniqid('', true)), 0, 6));
}

function get_token_cycle_info(array $settings){
  $tz = new DateTimeZone('America/Sao_Paulo');
  $opDate = get_current_operational_date();

  $tokenData = trim((string)($settings['token_data'] ?? ''));
  $token = trim((string)($settings['token'] ?? ''));

  if ($tokenData === '') {
    $tokenData = $opDate;
  }

  $cycleStart = DateTime::createFromFormat('Y-m-d H:i:s', $tokenData . ' 06:00:00', $tz);
  if (!$cycleStart) {
    $cycleStart = new DateTime($opDate . ' 06:00:00', $tz);
  }

  $now = new DateTime('now', $tz);

  $needsRotate = ($token === '');

  $cycleEnd = clone $cycleStart;
  $cycleEnd->modify('+3 days');

  if ($now >= $cycleEnd) {
    $needsRotate = true;
  }

  return [
    'needs_rotate' => $needsRotate,
    'cycle_start' => $cycleStart,
    'cycle_end' => $cycleEnd,
    'base_date' => $cycleStart->format('Y-m-d')
  ];
}

function ensure_token_cycle($conn){
  $s = $conn->query("
    SELECT token, token_data, chamada_aberta, chamada_inicio, chamada_fim, lat_base, lng_base, raio
    FROM settings
    WHERE id=1
    LIMIT 1
  ")->fetch_assoc();

  if (!$s) {
    throw new Exception("Configuração settings(id=1) não encontrada.");
  }

  $info = get_token_cycle_info($s);

  if ($info['needs_rotate']) {
    $novoToken = generate_token_code();
    $novaBaseData = get_current_operational_date();

    $stmt = $conn->prepare("UPDATE settings SET token=?, token_data=? WHERE id=1");
    $stmt->bind_param("ss", $novoToken, $novaBaseData);
    $stmt->execute();

    $s['token'] = $novoToken;
    $s['token_data'] = $novaBaseData;

    $info = get_token_cycle_info($s);
  }

  $s['token_cycle_start'] = $info['cycle_start']->format('Y-m-d H:i:s');
  $s['token_cycle_end']   = $info['cycle_end']->format('Y-m-d H:i:s');
  $s['operational_date']  = get_current_operational_date();

  return $s;
}

/* ===== garante token sem mexer na lista ===== */
$s = ensure_token_cycle($conn);

/* ===== helpers métricas ===== */
function has_column($conn, $table, $col){
  $table = preg_replace('/[^a-zA-Z0-9_]/','', $table);
  $col   = preg_replace('/[^a-zA-Z0-9_]/','', $col);
  $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
  return $r && $r->num_rows > 0;
}

function metrics_hoje($conn){
  $has_is_closed = has_column($conn, 'checkins', 'is_closed');
  $has_closed_at = has_column($conn, 'checkins', 'closed_at');
  [$opStart, $opEnd] = get_operational_bounds();

  $inicio = $opStart->format('Y-m-d H:i:s');
  $fim    = $opEnd->format('Y-m-d H:i:s');

  $sql = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN ".($has_is_closed ? "COALESCE(is_closed,0)=0" : "1=1")." THEN 1 ELSE 0 END) AS abertos,
      SUM(CASE WHEN ".($has_is_closed ? "COALESCE(is_closed,0)=1" : "0=1")." THEN 1 ELSE 0 END) AS fechados,
      MAX(data_hora) AS ultimo
    FROM checkins
    WHERE data_hora >= ?
      AND data_hora < ?
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $inicio, $fim);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  $tempo_medio = null;
  if ($has_is_closed && $has_closed_at) {
    $sqlAvg = "
      SELECT AVG(TIMESTAMPDIFF(MINUTE, data_hora, closed_at)) AS tempo_medio_min
      FROM checkins
      WHERE data_hora >= ?
        AND data_hora < ?
        AND COALESCE(is_closed,0)=1
        AND closed_at IS NOT NULL
    ";
    $stmtAvg = $conn->prepare($sqlAvg);
    $stmtAvg->bind_param("ss", $inicio, $fim);
    $stmtAvg->execute();
    $a = $stmtAvg->get_result()->fetch_assoc();
    if ($a && $a['tempo_medio_min'] !== null) $tempo_medio = (int)round($a['tempo_medio_min']);
  }

  return [
    'total' => (int)($row['total'] ?? 0),
    'abertos' => (int)($row['abertos'] ?? 0),
    'fechados' => (int)($row['fechados'] ?? 0),
    'ultimo' => $row['ultimo'] ?? null,
    'tempo_medio_min' => $tempo_medio,
    'operational_start' => $inicio,
    'operational_end' => $fim
  ];
}

/* ===== Relatórios no banco ===== */
function gerar_e_salvar_relatorio($conn){
  $st = $conn->query("SELECT chamada_inicio, chamada_fim, chamada_aberta FROM settings WHERE id=1")->fetch_assoc();
  $inicio = $st['chamada_inicio'] ?? null;
  $fim = $st['chamada_fim'] ?? null;

  if (empty($inicio)) {
    return [null, "Sem período registrado (lista nunca foi aberta neste ciclo)."];
  }

  if (empty($fim) || $fim === '0000-00-00 00:00:00') {
    $fim = date('Y-m-d H:i:s');
  }

  $stmt = $conn->prepare("
    SELECT id, nome, data_hora, COALESCE(ordem, 999999) AS ordem,
           COALESCE(is_closed,0) AS is_closed,
           closed_at
    FROM checkins
    WHERE data_hora BETWEEN ? AND ?
    ORDER BY ordem ASC, data_hora ASC
  ");
  $stmt->bind_param("ss", $inicio, $fim);
  $stmt->execute();
  $res = $stmt->get_result();

  $items = [];
  $unique = [];
  $fechados = 0;

  while ($r = $res->fetch_assoc()){
    $items[] = $r;
    $key = mb_strtolower(trim($r['nome'] ?? ''));
    if ($key !== '') $unique[$key] = true;
    if (intval($r['is_closed']) === 1) $fechados++;
  }

  $total = count($items);
  $unicos = count($unique);

  $payload = [
    'periodo_inicio' => $inicio,
    'periodo_fim' => $fim,
    'total_checkins' => $total,
    'motoboys_unicos' => $unicos,
    'total_fechados' => $fechados,
    'items' => $items
  ];

  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $stmt2 = $conn->prepare("
    INSERT INTO reports (periodo_inicio, periodo_fim, total_checkins, motoboys_unicos, total_fechados, payload_json)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  $stmt2->bind_param("ssiiis", $inicio, $fim, $total, $unicos, $fechados, $json);
  $stmt2->execute();

  if ($stmt2->affected_rows <= 0) {
    return [null, "Falha ao salvar relatório no banco."];
  }

  return [$conn->insert_id, null];
}

/* ===== actions ===== */
$action = $_GET['action'] ?? '';

if ($action === "toggle_chamada") {
  $st = $conn->query("SELECT chamada_aberta FROM settings WHERE id=1")->fetch_assoc();
  $aberta = intval($st['chamada_aberta'] ?? 0);

  if ($aberta === 1) {
    $conn->query("UPDATE settings SET chamada_aberta=0, chamada_fim=NOW() WHERE id=1");
  } else {
    $conn->query("UPDATE settings SET chamada_aberta=1, chamada_inicio=NOW(), chamada_fim=NULL WHERE id=1");
  }
  exit;
}

if ($action === "limpar") {
  [$report_id, $erro] = gerar_e_salvar_relatorio($conn);

  [$opStart, $opEnd] = get_operational_bounds();
  $inicio = $opStart->format('Y-m-d H:i:s');
  $fim    = $opEnd->format('Y-m-d H:i:s');

  $stmtDel = $conn->prepare("DELETE FROM checkins WHERE data_hora >= ? AND data_hora < ?");
  $stmtDel->bind_param("ss", $inicio, $fim);
  $stmtDel->execute();

  $conn->query("UPDATE settings SET chamada_aberta=0, chamada_fim=NOW() WHERE id=1");

  json_out([
    'sucesso' => $erro ? false : true,
    'report_id' => $report_id,
    'erro' => $erro
  ]);
}

if ($action === "dados") {
  $s2 = ensure_token_cycle($conn);
  json_out($s2 ?: []);
}

if ($action === "metrics") {
  $s2 = $conn->query("SELECT chamada_aberta FROM settings WHERE id=1")->fetch_assoc();
  $m = metrics_hoje($conn);
  $m['chamada_aberta'] = (int)($s2['chamada_aberta'] ?? 0);
  json_out($m);
}

if ($action === "lista") {
  [$opStart, $opEnd] = get_operational_bounds();
  $inicio = $opStart->format('Y-m-d H:i:s');
  $fim    = $opEnd->format('Y-m-d H:i:s');

  $sql = "SELECT id, nome, data_hora, COALESCE(ordem, 999999) AS ordem,
                 COALESCE(is_closed,0) AS is_closed
          FROM checkins
          WHERE data_hora >= ?
            AND data_hora < ?
          ORDER BY
            CASE WHEN COALESCE(is_closed,0)=0 THEN 0 ELSE 1 END ASC,
            ordem ASC,
            data_hora ASC
          LIMIT 500";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $inicio, $fim);
  $stmt->execute();
  $r = $stmt->get_result();

  $lista = [];
  while ($l = $r->fetch_assoc()) {
    if (!isset($l['is_closed'])) $l['is_closed'] = 0;
    $lista[] = $l;
  }
  json_out($lista);
}

/* ===== Relatórios: listar e ver ===== */
if ($action === "listar_relatorios") {
  $r = $conn->query("
    SELECT id, periodo_inicio, periodo_fim, total_checkins, motoboys_unicos, total_fechados, created_at
    FROM reports
    ORDER BY id DESC
    LIMIT 200
  ");
  $out = [];
  if ($r) {
    while ($row = $r->fetch_assoc()) $out[] = $row;
  }
  json_out($out);
}

if ($action === "ver_relatorio") {
  $id = intval($_GET['id'] ?? 0);
  if ($id <= 0) json_out(['erro'=>'ID inválido'], 400);

  $stmt = $conn->prepare("SELECT * FROM reports WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();

  if (!$row) json_out(['erro'=>'Relatório não encontrado'], 404);

  $payload = json_decode($row['payload_json'] ?? '[]', true);

  json_out([
    'meta' => [
      'id' => $row['id'],
      'periodo_inicio' => $row['periodo_inicio'],
      'periodo_fim' => $row['periodo_fim'],
      'total_checkins' => $row['total_checkins'],
      'motoboys_unicos' => $row['motoboys_unicos'],
      'total_fechados' => $row['total_fechados'],
      'created_at' => $row['created_at'],
    ],
    'payload' => $payload
  ]);
}

/* ===== POST JSON ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
  $input = json_decode(file_get_contents('php://input'), true);
  if (!is_array($input)) json_out(['sucesso'=>false, 'erro'=>'Payload inválido'], 400);

  $acao = $input['acao'] ?? '';

  /* ===== validar senha para liberar inserção manual ===== */
  if ($acao === 'verify_manual_pass') {
    $senha = trim((string)($input['senha'] ?? ''));
    if ($senha === '') json_out(['sucesso'=>false,'erro'=>'Senha obrigatória'], 400);

    if (!hash_equals((string)ADMIN_PASS, $senha)) {
      json_out(['sucesso'=>false,'erro'=>'Senha incorreta'], 401);
    }

    $_SESSION['manual_ok_until'] = time() + 300;
    json_out(['sucesso'=>true]);
  }

  if ($acao === 'toggle_close') {
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) json_out(['sucesso'=>false, 'erro'=>'ID inválido'], 400);

    [$opStart, $opEnd] = get_operational_bounds();
    $inicio = $opStart->format('Y-m-d H:i:s');
    $fim    = $opEnd->format('Y-m-d H:i:s');
    $dia    = $opStart->format('Y-m-d');

    $has_client_id = has_column($conn, 'checkins', 'client_id');

    // 1) Busca dados do motoboy antes de alternar
    if ($has_client_id) {
      $stmtSel = $conn->prepare("
        SELECT id, nome, client_id, COALESCE(is_closed,0) AS is_closed
        FROM checkins
        WHERE id=?
          AND data_hora >= ?
          AND data_hora < ?
        LIMIT 1
      ");
      if (!$stmtSel) {
        json_out(['sucesso'=>false,'erro'=>'Prepare falhou ao buscar check-in'], 500);
      }
      $stmtSel->bind_param("iss", $id, $inicio, $fim);
    } else {
      $stmtSel = $conn->prepare("
        SELECT id, nome, COALESCE(is_closed,0) AS is_closed
        FROM checkins
        WHERE id=?
          AND data_hora >= ?
          AND data_hora < ?
        LIMIT 1
      ");
      if (!$stmtSel) {
        json_out(['sucesso'=>false,'erro'=>'Prepare falhou ao buscar check-in'], 500);
      }
      $stmtSel->bind_param("iss", $id, $inicio, $fim);
    }

    $stmtSel->execute();
    $resSel = $stmtSel->get_result();
    $checkin = $resSel ? $resSel->fetch_assoc() : null;
    $stmtSel->close();

    if (!$checkin) {
      json_out(['sucesso'=>false, 'erro'=>'Check-in não encontrado no ciclo atual'], 404);
    }

    $nome = trim((string)($checkin['nome'] ?? ''));
    $client_id = trim((string)($checkin['client_id'] ?? ''));
    $estava_fechado = intval($checkin['is_closed'] ?? 0);

    // 2) Descobre a próxima ordem do fim da fila
    $proxOrdem = 999999;
    $stmtMax = $conn->prepare("
      SELECT COALESCE(MAX(ordem), 0) + 1 AS prox
      FROM checkins
      WHERE data_hora >= ?
        AND data_hora < ?
    ");
    if ($stmtMax) {
      $stmtMax->bind_param("ss", $inicio, $fim);
      $stmtMax->execute();
      $resMax = $stmtMax->get_result();
      $rowMax = $resMax ? $resMax->fetch_assoc() : null;
      if ($rowMax && isset($rowMax['prox'])) {
        $proxOrdem = (int)$rowMax['prox'];
      }
      $stmtMax->close();
    }

    // 3) Alterna fechado / reaberto
    // Se fechar: vai pro fim da fila
    // Se reabrir: continua no fim
    if ($estava_fechado === 0) {
      $sql = "UPDATE checkins
              SET
                is_closed = 1,
                closed_at = NOW(),
                ordem = ?
              WHERE id=?
                AND data_hora >= ?
                AND data_hora < ?
              LIMIT 1";
      $stmt = $conn->prepare($sql);
      if (!$stmt) {
        json_out(['sucesso'=>false,'erro'=>"Prepare falhou. Confere se existe is_closed/closed_at no banco."], 400);
      }
      $stmt->bind_param("iiss", $proxOrdem, $id, $inicio, $fim);
    } else {
      $sql = "UPDATE checkins
              SET
                is_closed = 0,
                closed_at = NULL,
                ordem = ?
              WHERE id=?
                AND data_hora >= ?
                AND data_hora < ?
              LIMIT 1";
      $stmt = $conn->prepare($sql);
      if (!$stmt) {
        json_out(['sucesso'=>false,'erro'=>"Prepare falhou. Confere se existe is_closed/closed_at no banco."], 400);
      }
      $stmt->bind_param("iiss", $proxOrdem, $id, $inicio, $fim);
    }

    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
      json_out(['sucesso'=>false, 'erro'=>'Nada atualizado (ID não é do ciclo atual ou não existe)'], 404);
    }
    $stmt->close();

    // 4) Lê o estado novo
    $r = $conn->query("SELECT COALESCE(is_closed,0) AS is_closed, COALESCE(ordem,999999) AS ordem FROM checkins WHERE id=" . intval($id) . " LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    $is_closed_novo = intval($row['is_closed'] ?? 0);

    // 5) Se acabou de fechar, remove da fila_da_vez
    $removidos_da_vez = 0;

    if ($is_closed_novo === 1) {
      $tb = $conn->query("SHOW TABLES LIKE 'fila_da_vez'");
      if ($tb && $tb->num_rows > 0) {
        if ($client_id !== '') {
          $stmtDel = $conn->prepare("
            DELETE FROM fila_da_vez
            WHERE dia=?
              AND (client_id=? OR LOWER(TRIM(nome)) = LOWER(TRIM(?)))
          ");
          if ($stmtDel) {
            $stmtDel->bind_param("sss", $dia, $client_id, $nome);
            $stmtDel->execute();
            $removidos_da_vez = (int)$stmtDel->affected_rows;
            $stmtDel->close();
          }
        } else {
          $stmtDel = $conn->prepare("
            DELETE FROM fila_da_vez
            WHERE dia=?
              AND LOWER(TRIM(nome)) = LOWER(TRIM(?))
          ");
          if ($stmtDel) {
            $stmtDel->bind_param("ss", $dia, $nome);
            $stmtDel->execute();
            $removidos_da_vez = (int)$stmtDel->affected_rows;
            $stmtDel->close();
          }
        }
      }
    }

    json_out([
      'sucesso' => true,
      'is_closed' => $is_closed_novo,
      'removidos_da_vez' => $removidos_da_vez
    ]);
  }

  if ($acao === 'apagar_relatorio') {
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) json_out(['sucesso'=>false, 'erro'=>'ID inválido'], 400);

    $stmt = $conn->prepare("DELETE FROM reports WHERE id=? LIMIT 1");
    if (!$stmt) {
      json_out(['sucesso'=>false, 'erro'=>'Prepare falhou'], 500);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
      json_out(['sucesso'=>false, 'erro'=>'Relatório não encontrado'], 404);
    }

    json_out(['sucesso'=>true]);
  }

  if ($acao === 'atualizar_ordem' && isset($input['ordem']) && is_array($input['ordem'])) {
    $ordem = $input['ordem'];
    [$opStart, $opEnd] = get_operational_bounds();
    $inicio = $opStart->format('Y-m-d H:i:s');
    $fim    = $opEnd->format('Y-m-d H:i:s');

    $conn->begin_transaction();
    try {
      $pos = 1;
      $stmt = $conn->prepare(
        "UPDATE checkins
         SET ordem=?
         WHERE id=?
           AND data_hora >= ?
           AND data_hora < ?"
      );

      foreach($ordem as $id){
        $id = intval($id);
        $stmt->bind_param("iiss", $pos, $id, $inicio, $fim);
        $stmt->execute();
        $pos++;
      }

      $conn->commit();
      json_out(['sucesso'=>true]);
    } catch(Exception $e){
      $conn->rollback();
      json_out(['sucesso'=>false, 'erro'=>'Falha ao atualizar ordem'], 500);
    }
  }

  if ($acao === 'add_manual') {
    $ok = false;
    if (isset($_SESSION['manual_ok_until']) && (int)$_SESSION['manual_ok_until'] >= time()) {
      $ok = true;
    } else {
      $senha = trim((string)($input['senha'] ?? ''));
      if ($senha !== '' && hash_equals((string)ADMIN_PASS, $senha)) {
        $_SESSION['manual_ok_until'] = time() + 300;
        $ok = true;
      }
    }

    if (!$ok) json_out(['sucesso'=>false,'erro'=>'Senha necessária para inserir manualmente'], 401);

    $nome = trim((string)($input['nome'] ?? ''));
    $obs  = trim((string)($input['obs'] ?? ''));

    if ($nome === '') json_out(['sucesso'=>false,'erro'=>'Nome é obrigatório'], 400);
    if (mb_strlen($nome) > 80) json_out(['sucesso'=>false,'erro'=>'Nome muito longo'], 400);
    if (mb_strlen($obs) > 120) $obs = mb_substr($obs, 0, 120);

    $has_obs = has_column($conn, 'checkins', 'obs');

    if ($has_obs) {
      $stmt = $conn->prepare("INSERT INTO checkins (nome, data_hora, ordem, is_closed, closed_at, obs) VALUES (?, NOW(), NULL, 0, NULL, ?)");
      if (!$stmt) json_out(['sucesso'=>false,'erro'=>'Prepare falhou no INSERT'], 500);
      $stmt->bind_param("ss", $nome, $obs);
    } else {
      $stmt = $conn->prepare("INSERT INTO checkins (nome, data_hora, ordem, is_closed, closed_at) VALUES (?, NOW(), NULL, 0, NULL)");
      if (!$stmt) {
        $stmt2 = $conn->prepare("INSERT INTO checkins (nome, data_hora, ordem) VALUES (?, NOW(), NULL)");
        if (!$stmt2) json_out(['sucesso'=>false,'erro'=>'Prepare falhou no INSERT (schema diferente)'], 500);
        $stmt2->bind_param("s", $nome);
        $stmt2->execute();
        if ($stmt2->affected_rows <= 0) json_out(['sucesso'=>false,'erro'=>'Falha ao inserir'], 500);
        json_out(['sucesso'=>true, 'id'=>$conn->insert_id]);
      }
      $stmt->bind_param("s", $nome);
    }

    $stmt->execute();
    if ($stmt->affected_rows <= 0) json_out(['sucesso'=>false,'erro'=>'Falha ao inserir'], 500);

    json_out(['sucesso'=>true, 'id'=>$conn->insert_id]);
  }

  json_out(['sucesso'=>false,'erro'=>'Ação JSON desconhecida'], 400);
}

/* ===== POST FORM: salvar config ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = trim($_POST['token'] ?? '');
  $lat   = floatval($_POST['lat'] ?? 0);
  $lng   = floatval($_POST['lng'] ?? 0);
  $raio  = intval($_POST['raio'] ?? 0);

  $stmt = $conn->prepare("UPDATE settings SET token=?, lat_base=?, lng_base=?, raio=? WHERE id=1");
  $stmt->bind_param("sddi", $token, $lat, $lng, $raio);
  $stmt->execute();
}

/* ===== HTML ===== */
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Admin - Chamada Motoboys</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>

 .titulo-admin{
  display:flex;
  align-items:center;
  gap:10px;
}

.titulo-admin img{
  width:100px;
  height:auto;
}
.system-signature{
position: fixed;
bottom: 8px;
right: 12px;
font-size: 11px;
color: #666;
opacity: 0.6;
font-family: monospace;
pointer-events: none;
}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#eef1f5}
.container{max-width:1200px;margin:auto;padding:30px}
h2{margin-bottom:20px}
.token-box{background:#000;color:#00ff88;padding:25px;border-radius:16px;text-align:center;margin-bottom:16px}
.token-box span{display:block;font-size:48px;font-weight:bold;letter-spacing:6px}
.token-box small{display:block;margin-top:10px;font-size:16px;color:#ccc}
.tabs{display:flex;gap:10px;margin-bottom:25px;flex-wrap:wrap}
.tab{padding:12px 20px;border-radius:10px;background:#cfd8dc;cursor:pointer;font-weight:bold}
.tab.active{background:#1e88e5;color:#fff}
.card{background:#fff;padding:22px;border-radius:14px;box-shadow:0 8px 20px rgba(0,0,0,.08);margin-bottom:20px}
.actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
button{padding:12px 18px;border:none;border-radius:10px;cursor:pointer;font-weight:bold}
.btn-toggle{background:#1e88e5;color:#fff}
.btn-clear{background:#ef6c00;color:#fff}
.save{background:#1e88e5;color:#fff;width:100%}
input{width:100%;padding:12px;margin-bottom:10px;border-radius:8px;border:1px solid #ccc}
#lista{background:#fdfdfd;padding:15px;border-radius:10px;max-height:350px;overflow-y:auto;font-size:14px;border:2px solid #ccc;list-style:none}
#lista li{padding:10px;margin-bottom:5px;background:#fff;border:1px solid #ddd;border-radius:4px;cursor:grab}
.section{display:none}
.section.active{display:block}
small.mini{color:#666}

#lista li{display:flex;align-items:center;justify-content:space-between;gap:10px}
#lista .left{display:flex;align-items:center;gap:10px;min-width:0}
#lista .nome{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:520px}
#lista .nome.fechado{color:#c62828;font-weight:800}
#lista .badge{font-size:11px;padding:3px 8px;border-radius:999px;font-weight:700}
#lista .badge.aberto{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9}
#lista .badge.fechado{background:#ffebee;color:#c62828;border:1px solid #ffcdd2}
#lista .mini-btn{padding:8px 10px;border-radius:10px;font-size:12px}
#lista .mini-btn.fechar{background:#c62828;color:#fff}
#lista .mini-btn.reabrir{background:#2e7d32;color:#fff}

#lista li.anim-moving{
  position: relative;
  z-index: 2;
  transition:
    transform .45s cubic-bezier(.2,.8,.2,1),
    opacity .25s ease,
    box-shadow .25s ease,
    background-color .25s ease;
  box-shadow: 0 14px 28px rgba(0,0,0,.16);
}
#lista li.anim-fade{
  opacity: .92;
}

.status-pill{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800}
.status-open{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9}
.status-closed{background:#ffebee;color:#c62828;border:1px solid #ffcdd2}

.dash{
  display:grid;
  grid-template-columns: repeat(5, minmax(160px, 1fr));
  gap:10px;
  margin-bottom: 12px;
}
.dash .mcard{
  background:#fff;
  border-radius:14px;
  box-shadow:0 8px 20px rgba(0,0,0,.08);
  padding:14px 16px;
}
.dash .k{font-size:12px;color:#607080;font-weight:800;text-transform:uppercase;letter-spacing:.4px}
.dash .v{font-size:22px;font-weight:900;margin-top:6px;color:#0f172a}
.dash .sub{margin-top:6px;font-size:12px;color:#607080}
.badge2{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900}
.badge2.ok{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9}
.badge2.no{background:#ffebee;color:#c62828;border:1px solid #ffcdd2}

.row{display:flex;gap:10px;flex-wrap:wrap}
.row > *{flex:1;min-width:220px}
.btn-add{background:#2e7d32;color:#fff}

.toast{
  position:fixed;right:18px;bottom:18px;
  background:#111;color:#fff;padding:12px 14px;border-radius:12px;
  box-shadow:0 10px 30px rgba(0,0,0,.25);
  opacity:0;transform:translateY(8px);transition:.18s;
  max-width: 360px;z-index:9999;font-size:13px;
}
.toast.show{opacity:1;transform:translateY(0)}

.hidden{display:none !important}
.btn-secondary{background:#455a64;color:#fff}
</style>
</head>
<body>

<div class="container">
<h2 class="titulo-admin">
  <img src="img/logo.png" alt="Logo">
  Painel Administrativo
</h2>

<div class="tabs">
  <div class="tab active" onclick="abrirAba('chamada', event)">📋 Chamada / Lista</div>
  <div class="tab" onclick="abrirAba('davez', event)">🚚 Da vez</div>
  <div class="tab" onclick="abrirAba('relatorio', event)">📄 Relatórios</div>
  <div class="tab" onclick="abrirAba('config', event)">⚙️ Configurações</div>
</div>

<div id="chamada" class="section active">
  <div class="token-box">
    <span id="tokenDisplay">----</span>
    <small>Atualiza automaticamente. Próxima troca em <b id="contador">--:--:--</b></small>
  </div>

  <div class="dash" id="dash">
    <div class="mcard">
      <div class="k">Chamada</div>
      <div class="v"><span class="badge2 no">...</span></div>
      <div class="sub">Status atual</div>
    </div>
    <div class="mcard">
      <div class="k">Total ciclo</div>
      <div class="v" id="mTotal">0</div>
      <div class="sub">Check-ins</div>
    </div>
    <div class="mcard">
      <div class="k">Na fila</div>
      <div class="v" id="mAbertos">0</div>
      <div class="sub">Abertos</div>
    </div>
    <div class="mcard">
      <div class="k">Finalizados</div>
      <div class="v" id="mFechados">0</div>
      <div class="sub">Fechados</div>
    </div>
    <div class="mcard">
      <div class="k">Último</div>
      <div class="v" id="mUltimo">--:--</div>
      <div class="sub" id="mTempoMedio">Tempo médio: --</div>
    </div>
  </div>

  <div class="card">
    <div class="actions">
      <button class="btn-toggle" id="btnToggle" onclick="toggleChamada()">Carregando...</button>
      <span id="statusLista" class="status-pill status-closed">Lista fechada</span>
      <button class="btn-secondary" id="btnShowManual" onclick="toggleManualBox()">+ Adicionar manualmente</button>
      <button class="btn-clear" onclick="limpar()">Limpar lista do ciclo (salva relatório)</button>
    </div>
    <p><small class="mini">Atualização automática a cada 12s. O ciclo operacional vira às 06:00.</small></p>
  </div>

  <div class="card hidden" id="manualBox">
    <h3>Adicionar motoboy manualmente</h3>
    <p><small class="mini">Pra quando o celular do cara decide sabotar a própria função na cadeia alimentar.</small></p>
    <div class="row">
      <div>
        <label><b>Nome</b></label>
        <input id="manualNome" placeholder="Ex: João Motoboy">
      </div>
      <div>
        <label><b>Observação (opcional)</b></label>
        <input id="manualObs" placeholder="Ex: sem bateria / atrasado / etc.">
      </div>
      <div style="min-width:180px;flex:0;">
        <label style="opacity:0;">.</label>
        <button class="btn-add" style="width:100%;padding:12px 18px;border:none;border-radius:10px;cursor:pointer;font-weight:bold" onclick="addManual()">Adicionar</button>
      </div>
    </div>
    <small class="mini">Entra como <b>ABERTO</b> com horário atual.</small>
  </div>

  <div class="card">
    <h3>Ordem de chegada</h3>
    <ul id="lista"></ul>
  </div>
</div>

<div id="davez" class="section">
  <div class="card">
    <h3>Fila "Da vez" (entregas)</h3>
    <p><small class="mini">Fila dinâmica. Quem está <b>NA FILA</b> é elegível pra sair. Quem está <b>EM ENTREGA</b> não entra na vez.</small></p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
      <div id="dvDaVezBox" style="flex:1;min-width:240px;">
        <span class="badge2 no">CARREGANDO</span>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn-secondary" onclick="carregarDaVez(true)">Atualizar</button>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>Na fila</h3>
    <p><small class="mini">Você pode arrastar os nomes para reorganizar a ordem da DA VEZ.</small></p>
    <div id="dvFila" style="display:flex;flex-direction:column;gap:8px;"></div>
  </div>

  <div class="card">
    <h3>Em entrega</h3>
    <div id="dvEntrega" style="display:flex;flex-direction:column;gap:8px;"></div>
  </div>
</div>

<div id="relatorio" class="section">
  <div class="card">
    <h3>Relatórios salvos</h3>
    <p><small class="mini">Clique em “Ver” para abrir o relatório aqui mesmo.</small></p>
    <div id="lastReportBox">Carregando...</div>
  </div>
</div>

<div id="config" class="section">
  <div class="card">
    <h3>Configurações da Lista</h3>

    <label><b>🔐 Token atual</b></label>
    <small class="mini">Código que os motoboys precisam digitar para entrar na lista. Agora ele gira a cada 3 dias, às 06:00.</small>
    <input id="token" placeholder="Ex: A1B2C3">

    <label><b>📍 Latitude Base</b></label>
    <small class="mini">Coordenada exata do ponto onde o motoboy deve estar para validar o check-in.</small>
    <input id="lat" placeholder="Ex: -23.550520">

    <label><b>📍 Longitude Base</b></label>
    <small class="mini">Coordenada exata do ponto onde o motoboy deve estar para validar o check-in.</small>
    <input id="lng" placeholder="Ex: -46.633308">

    <label><b>📏 Raio Permitido (em metros)</b></label>
    <small class="mini">Distância máxima do ponto base para permitir entrada na lista.</small>
    <input id="raio" placeholder="Ex: 100">

    <button class="save" onclick="salvar()">Salvar Configurações</button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
<script>
let carregando = false;
let pausado = false;
let manualPassCache = null;
const DV_LIST_URL = "DaVez/listar.php?v=1";
const DV_SAIR_URL = "DaVez/sair.php?v=1";
const DV_REORDER_URL = "DaVez/reordenar.php?v=1";

let dvCarregando = false;
let dvLast = 0;
let dvSortable = null;
let dvPausado = false;

let tokenCycleEndAt = null;

function abrirAba(id, ev){
  document.querySelectorAll('.section').forEach(s=>s.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));

  const sec = document.getElementById(id);
  if (sec) sec.classList.add('active');

  if (ev && ev.target) {
    ev.target.classList.add('active');
  } else {
    const tabs = document.querySelectorAll('.tab');
    tabs.forEach(t=>{
      const oc = (t.getAttribute('onclick') || '');
      if (oc.includes(`abrirAba('${id}'`) || oc.includes(`abrirAba("${id}"`)) {
        t.classList.add('active');
      }
    });
  }

  if (id === 'davez') carregarDaVez(true);
}

function escapeHtml(str){
  return String(str)
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}

function showToast(msg, ok=true){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.background = ok ? '#0f172a' : '#7f1d1d';
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'), 2200);
}

async function toggleChamada(){
  await fetch("admin.php?action=toggle_chamada");
  await carregar();
}

async function limpar(){
  if(!confirm("Tem certeza? Isso apaga a lista do ciclo atual e salva um relatório no banco.")) return;

  const r = await fetch("admin.php?action=limpar").then(x=>x.json());

  if (!r.sucesso) {
    alert(r.erro || "Falha ao salvar relatório.");
  } else {
    await carregarRelatorios();
    showToast("Relatório salvo e lista limpa.", true);
  }

  await carregar();
}

function salvar(){
  let f = new FormData();
  f.append("token", token.value);
  f.append("lat", lat.value);
  f.append("lng", lng.value);
  f.append("raio", raio.value);
  fetch("admin.php", { method:"POST", body:f }).then(()=>{
    showToast("Configurações salvas.", true);
    carregar();
  });
}

async function toggleClose(ev, id){
  ev.preventDefault();
  ev.stopPropagation();

  const li = ev.target.closest('li');

  try{
    const resp = await fetch('admin.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({acao:'toggle_close', id:id})
    }).then(r=>r.json());

    if (!resp.sucesso) {
      showToast(resp.erro || "Falha ao atualizar", false);
      await carregar();
      return;
    }

    if (Number(resp.is_closed || 0) === 1 && li) {
      const listaEl = document.getElementById('lista');
      const itemsAntes = Array.from(listaEl.children);
      const firstRects = new Map(itemsAntes.map(el => [el.dataset.id, el.getBoundingClientRect()]));

      li.classList.add('anim-moving');
      li.classList.add('anim-fade');

      listaEl.appendChild(li);

      const itemsDepois = Array.from(listaEl.children);
      itemsDepois.forEach(el => {
        const oldRect = firstRects.get(el.dataset.id);
        const newRect = el.getBoundingClientRect();
        if (!oldRect) return;

        const dx = oldRect.left - newRect.left;
        const dy = oldRect.top - newRect.top;

        if (dx || dy) {
          el.style.transform = `translate(${dx}px, ${dy}px)`;
        }
      });

      requestAnimationFrame(() => {
        itemsDepois.forEach(el => {
          el.style.transform = '';
        });
      });

      showToast(
        resp.removidos_da_vez > 0
          ? "Fechado e removido da fila da vez."
          : "Fechado e enviado para o fim da fila.",
        true
      );

      setTimeout(async () => {
        li.classList.remove('anim-moving');
        li.classList.remove('anim-fade');
        await carregar();
        await carregarDaVez(true);
      }, 520);

      return;
    }

    if (Number(resp.is_closed || 0) === 0) {
      showToast("Motoboy reaberto.", true);
    }

    await carregar();
    await carregarDaVez(true);

  } catch(e){
    showToast("Falha ao atualizar", false);
    await carregar();
  }
}

async function toggleManualBox(){
  const box = document.getElementById('manualBox');
  const btn = document.getElementById('btnShowManual');
  const isHidden = box.classList.contains('hidden');

  if (isHidden){
    const senha = prompt("Digite a senha do admin para liberar inserção manual:");
    if (senha === null) return;

    const resp = await fetch('admin.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({acao:'verify_manual_pass', senha})
    }).then(r=>r.json()).catch(()=>({sucesso:false, erro:"Falha de rede"}));

    if (!resp.sucesso){
      showToast(resp.erro || "Senha incorreta.", false);
      return;
    }

    manualPassCache = senha;

    box.classList.remove('hidden');
    btn.textContent = "Fechar manual";
    setTimeout(()=>{ if (window.manualNome) manualNome.focus(); }, 50);
    return;
  }

  box.classList.add('hidden');
  btn.textContent = "+ Adicionar manualmente";
}

async function addManual(){
  const nome = (manualNome.value || '').trim();
  const obs  = (manualObs.value || '').trim();

  if (!nome){
    showToast("Digite o nome.", false);
    return;
  }

  const payload = {acao:'add_manual', nome, obs};
  if (manualPassCache) payload.senha = manualPassCache;

  const resp = await fetch('admin.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  }).then(r=>r.json()).catch(()=>({sucesso:false, erro:"Falha de rede"}));

  if (!resp.sucesso){
    if (String(resp.erro || '').toLowerCase().includes('senha')) {
      manualPassCache = null;
    }
    showToast(resp.erro || "Não foi possível adicionar.", false);
    return;
  }

  manualNome.value = '';
  manualObs.value = '';
  showToast("Adicionado na lista.", true);

  toggleManualBox();
  carregar();
}

function atualizarStatusChamada(dados){
  const aberta = Number(dados.chamada_aberta || 0) === 1;
  const btn = document.getElementById('btnToggle');
  const pill = document.getElementById('statusLista');

  btn.textContent = aberta ? "Fechar lista" : "Abrir lista";
  pill.textContent = aberta ? "Lista aberta" : "Lista fechada";
  pill.className = "status-pill " + (aberta ? "status-open" : "status-closed");
}

async function carregarMetrics(){
  try{
    const m = await fetch("admin.php?action=metrics").then(r=>r.json());

    const dash = document.getElementById('dash');
    const badge = dash.querySelector('.badge2');
    const aberta = Number(m.chamada_aberta || 0) === 1;
    badge.className = 'badge2 ' + (aberta ? 'ok' : 'no');
    badge.textContent = aberta ? 'ABERTA' : 'FECHADA';

    document.getElementById('mTotal').textContent = Number(m.total || 0);
    document.getElementById('mAbertos').textContent = Number(m.abertos || 0);
    document.getElementById('mFechados').textContent = Number(m.fechados || 0);

    let ultimo = '--:--';
    if (m.ultimo){
      const d = new Date(m.ultimo.replace(' ', 'T'));
      if (!isNaN(d)) ultimo = d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    }
    document.getElementById('mUltimo').textContent = ultimo;

    const tm = (m.tempo_medio_min === null || typeof m.tempo_medio_min === 'undefined')
      ? '--'
      : (Number(m.tempo_medio_min) + ' min');
    document.getElementById('mTempoMedio').textContent = 'Tempo médio: ' + tm;
  } catch(e){}
}

async function carregar(){
  if (carregando || pausado) return;
  carregando = true;
  try{
    const d = await fetch("admin.php?action=dados").then(r=>r.json());
    token.value = d.token || '';
    tokenDisplay.innerText = d.token || '----';
    lat.value = d.lat_base || '';
    lng.value = d.lng_base || '';
    raio.value = d.raio || '';

    tokenCycleEndAt = d.token_cycle_end ? new Date(d.token_cycle_end.replace(' ', 'T')) : null;

    atualizarStatusChamada(d);
    carregarMetrics();

    const t = await fetch("admin.php?action=lista").then(r=>r.json());
    lista.innerHTML = t.map(m=>{
      const dh = new Date(String(m.data_hora || '').replace(' ', 'T'));
      const ds = isNaN(dh) ? '' : dh.toLocaleString();
      const fechado = Number(m.is_closed || 0) === 1;

      return `<li data-id="${m.id}">
        <div class="left">
          <span class="ordem">${m.ordem}º</span>
          <span class="nome ${fechado ? 'fechado' : ''}">${escapeHtml(m.nome || '')}</span>
          <span class="badge ${fechado ? 'fechado' : 'aberto'}">${fechado ? 'FECHADO' : 'ABERTO'}</span>
          <small class="mini">${escapeHtml(ds)}</small>
        </div>
        <button class="mini-btn ${fechado ? 'reabrir' : 'fechar'}" onclick="toggleClose(event, ${m.id})">${fechado ? 'Reabrir' : 'Fechar'}</button>
      </li>`;
    }).join('');
  } finally {
    carregando = false;
  }
}

function fmtHora(dt){
  try{
    const d = new Date(String(dt || '').replace(' ', 'T'));
    if (!isNaN(d)) return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
  }catch(e){}
  return '';
}

async function sairParaEntrega(id){
  if (!id) return;
  try{
    const fd = new FormData();
    fd.append('id', id);

    const r = await fetch(DV_SAIR_URL, {
      method:'POST',
      body: fd
    }).then(x=>x.json()).catch(()=>null);

    if (!r || !r.ok){
      showToast((r && (r.err || r.erro)) ? (r.err || r.erro) : "Falha ao marcar saída", false);
    } else {
      showToast("Marcado como EM ENTREGA.", true);
    }
  } finally {
    carregarDaVez(true);
  }
}

function initDaVezSortable(){
  const el = document.getElementById('dvFila');
  if (!el) return;

  if (dvSortable) {
    dvSortable.destroy();
    dvSortable = null;
  }

  dvSortable = Sortable.create(el, {
    animation: 150,
    handle: '.dv-drag',
    filter: 'button',
    preventOnFilter: false,

    onStart(){
      dvPausado = true;
    },

    async onEnd(){
      try{
        const ordem = [];
        document.querySelectorAll('#dvFila .dv-item').forEach(item => {
          const id = parseInt(item.dataset.id || '0', 10);
          if (id > 0) ordem.push(id);
        });

        const resp = await fetch(DV_REORDER_URL, {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ordem})
        }).then(r => r.json()).catch(() => null);

        if (!resp || !resp.ok) {
          showToast((resp && (resp.err || resp.erro)) ? (resp.err || resp.erro) : 'Falha ao reordenar fila da vez', false);
        } else {
          showToast('Ordem da fila DA VEZ atualizada.', true);
        }

      } finally {
        dvPausado = false;
        carregarDaVez(true);
      }
    }
  });
}

async function carregarDaVez(force=false){
  if (dvCarregando || dvPausado) return;

  const now = Date.now();
  if (!force && now - dvLast < 3000) return;
  dvLast = now;

  dvCarregando = true;
  try{
    const data = await fetch(DV_LIST_URL).then(r=>r.json()).catch(()=>null);

    if (!data || !data.ok){
      document.getElementById('dvDaVezBox').innerHTML =
        `<span class="badge2 no">ERRO</span> <small class="mini">${
          escapeHtml((data && (data.err || data.erro)) ? (data.err || data.erro) : 'Falha ao carregar')
        }</small>`;

      document.getElementById('dvFila').innerHTML = `<small class="mini">Erro ao carregar fila.</small>`;
      document.getElementById('dvEntrega').innerHTML = `<small class="mini">Erro ao carregar entregas.</small>`;
      return;
    }

    const da = data.da_vez;
    if (da && da.nome){
      document.getElementById('dvDaVezBox').innerHTML =
        `<span class="badge2 ok">DA VEZ</span> <b>${escapeHtml(da.nome)}</b> <small class="mini">(${escapeHtml(da.client_id || '')})</small>`;
    } else {
      document.getElementById('dvDaVezBox').innerHTML =
        `<span class="badge2 no">SEM FILA</span> <small class="mini">Ninguém disponível agora.</small>`;
    }

    const fila = Array.isArray(data.fila) ? data.fila : [];
    const naFila = fila.filter(x => String(x.status || '') === 'na_fila');
    const emEnt = fila.filter(x => String(x.status || '') === 'em_entrega');

    const filaBox = document.getElementById('dvFila');
    const entBox  = document.getElementById('dvEntrega');

    if (naFila.length === 0){
      filaBox.innerHTML = `<small class="mini">Fila vazia.</small>`;
      if (dvSortable) {
        dvSortable.destroy();
        dvSortable = null;
      }
    } else {
      let html = '';

      naFila.forEach((x, i) => {
        const isDaVez = (i === 0);
        const cid = String(x.client_id || '');
        const rowId = parseInt(x.id || '0', 10);
        const ordemTxt = (typeof x.ordem !== 'undefined' && x.ordem !== null) ? x.ordem : '-';

        html += `
          <div class="dv-item"
               data-id="${rowId}"
               style="padding:10px;border:1px solid #ddd;border-radius:12px;background:#fff;display:flex;justify-content:space-between;gap:10px;align-items:center;">

            <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1;">
              <div class="dv-drag"
                   title="Arrastar"
                   style="cursor:grab;font-size:20px;line-height:1;user-select:none;color:#607080;padding:4px 6px;">☰</div>

              <div style="min-width:0;flex:1;">
                <b>${isDaVez ? '🥇 ' : ''}${escapeHtml(x.nome || '')}</b>
                <div class="mini">
                  Posição: ${i + 1}
                  | Ordem: ${escapeHtml(String(ordemTxt))}
                  | Entrou: ${escapeHtml(fmtHora(x.entered_at))}
                  | CID: ${escapeHtml(cid)}
                </div>
              </div>
            </div>

            <div style="display:flex;gap:8px;flex-shrink:0;">
              ${isDaVez ? `<button class="mini-btn" style="background:#ef6c00;color:#fff;" onclick="sairParaEntrega(${rowId})">Saiu p/ entrega</button>` : ''}
            </div>
          </div>
        `;
      });

      filaBox.innerHTML = html;
      initDaVezSortable();
    }

    if (emEnt.length === 0){
      entBox.innerHTML = `<small class="mini">Ninguém em entrega.</small>`;
    } else {
      entBox.innerHTML = emEnt.map((x) => {
        return `
          <div style="padding:10px;border:1px solid #ddd;border-radius:12px;background:#fff;display:flex;justify-content:space-between;gap:10px;align-items:center;">
            <div style="min-width:0;">
              <b>${escapeHtml(x.nome || '')}</b>
              <div class="mini">Saiu: ${escapeHtml(fmtHora(x.last_action_at || x.entered_at))} | CID: ${escapeHtml(x.client_id || '')}</div>
            </div>
          </div>
        `;
      }).join('');
    }

  } finally {
    dvCarregando = false;
  }
}

setInterval(()=>{
  const sec = document.getElementById('davez');
  if (sec && sec.classList.contains('active')) carregarDaVez(false);
}, 8000);

async function carregarRelatorios(){
  const lista = await fetch("admin.php?action=listar_relatorios").then(r=>r.json());
  const box = document.getElementById('lastReportBox');

  if (!Array.isArray(lista) || lista.length === 0) {
    box.innerText = "Nenhum relatório salvo ainda.";
    return;
  }

  box.innerHTML = `
    <div style="display:flex;flex-direction:column;gap:8px;">
      ${lista.map(r => `
        <div style="padding:10px;border:1px solid #ddd;border-radius:10px;background:#fff;display:flex;justify-content:space-between;gap:10px;align-items:center;">
          <div style="min-width:0;">
            <b>#${r.id}</b> | ${escapeHtml(r.periodo_inicio)} → ${escapeHtml(r.periodo_fim)}
            <div class="mini">Total: ${r.total_checkins} | Únicos: ${r.motoboys_unicos} | Fechados: ${r.total_fechados}</div>
          </div>
          <div style="display:flex;gap:8px;flex-shrink:0;">
            <button class="mini-btn" style="background:#1e88e5;color:#fff;" onclick="abrirRelatorio(${r.id})">Ver</button>
            <button class="mini-btn" style="background:#e53935;color:#fff;" onclick="apagarRelatorio(${r.id})">Apagar</button>
          </div>
        </div>
      `).join('')}
    </div>
  `;
}

async function abrirRelatorio(id){
  const data = await fetch("admin.php?action=ver_relatorio&id=" + id).then(r=>r.json());
  if (data.erro) { alert(data.erro); return; }

  const meta = data.meta;
  const items = (data.payload && data.payload.items) ? data.payload.items : [];

  const box = document.getElementById('lastReportBox');
  box.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;">
      <div>
        <b>Relatório #${meta.id}</b>
        <div class="mini">${escapeHtml(meta.periodo_inicio)} → ${escapeHtml(meta.periodo_fim)}</div>
        <div class="mini">Total: ${meta.total_checkins} | Únicos: ${meta.motoboys_unicos} | Fechados: ${meta.total_fechados}</div>
      </div>
      <button class="mini-btn" style="background:#555;color:#fff;" onclick="carregarRelatorios()">Voltar</button>
    </div>

    <div style="max-height:360px;overflow:auto;border:1px solid #ddd;border-radius:12px;background:#fff;">
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="position:sticky;top:0;background:#f6f7fb;">
            <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Ordem</th>
            <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Nome</th>
            <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Check-in</th>
            <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Status</th>
            <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Fechado em</th>
          </tr>
        </thead>
        <tbody>
          ${items.map(it => {
            const fechado = Number(it.is_closed || 0) === 1;
            return `
              <tr>
                <td style="padding:10px;border-bottom:1px solid #f0f0f0;">${escapeHtml(it.ordem ?? '')}º</td>
                <td style="padding:10px;border-bottom:1px solid #f0f0f0;${fechado ? 'color:#c62828;font-weight:700;' : ''}">${escapeHtml(it.nome ?? '')}</td>
                <td style="padding:10px;border-bottom:1px solid #f0f0f0;">${escapeHtml(it.data_hora ?? '')}</td>
                <td style="padding:10px;border-bottom:1px solid #f0f0f0;">${fechado ? 'FECHADO' : 'ABERTO'}</td>
                <td style="padding:10px;border-bottom:1px solid #f0f0f0;">${escapeHtml(it.closed_at ?? '-')}</td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    </div>
  `;
}

async function apagarRelatorio(id){
  if (!confirm("Apagar o relatório #" + id + "? Isso não mexe na lista atual, só remove o relatório salvo.")) return;

  const resp = await fetch("admin.php", {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify({acao:"apagar_relatorio", id})
  }).then(r=>r.json()).catch(()=>({sucesso:false, erro:"Falha de rede"}));

  if (!resp.sucesso) {
    alert(resp.erro || "Não foi possível apagar.");
    return;
  }

  carregarRelatorios();
}

var sortable = Sortable.create(document.getElementById('lista'), {
  filter: 'button',
  preventOnFilter: false,
  animation: 150,
  onStart(){ pausado = true; },
  onEnd: function(){
    let ordem = [];
    document.querySelectorAll('#lista li').forEach(li=>ordem.push(li.dataset.id));
    fetch('admin.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({acao:'atualizar_ordem', ordem:ordem})
    }).finally(()=>{ pausado = false; carregar(); });
  }
});

function contadorToken(){
  if (!tokenCycleEndAt || isNaN(tokenCycleEndAt.getTime())) {
    contador.innerText = '--:--:--';
    return;
  }

  const agora = new Date();
  let diff = Math.floor((tokenCycleEndAt.getTime() - agora.getTime()) / 1000);

  if (diff < 0) diff = 0;

  const dias = Math.floor(diff / 86400);
  const resto = diff % 86400;
  const h = String(Math.floor(resto / 3600)).padStart(2,'0');
  const m = String(Math.floor((resto % 3600) / 60)).padStart(2,'0');
  const s = String(resto % 60).padStart(2,'0');

  contador.innerText = (dias > 0 ? `${dias}d ` : '') + `${h}:${m}:${s}`;
}

setInterval(carregar, 12000);
setInterval(contadorToken, 1000);
carregar();
contadorToken();
carregarRelatorios();
</script>
<div class="system-signature">
YD 808 • CORE v1.5
</div>
</body>
</html>
