<?php
include "config.php";
echo "Conectado OK. Hora do servidor: ";
$r = $conn->query("SELECT NOW() as agora");
echo $r->fetch_assoc()['agora'];
