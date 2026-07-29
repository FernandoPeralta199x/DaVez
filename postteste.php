<?php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode([
        'ok' => true,
        'msg' => 'POST FUNCIONOU'
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'msg' => 'GET FUNCIONOU'
]);