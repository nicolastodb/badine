<?php
// cmd.php — Relais Apple Watch
// GET ?p=p0&token=badminton   → enregistre la commande
// GET ?poll&token=badminton   → retourne la dernière commande

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('TOKEN', 'badminton'); // changez si besoin
$file = __DIR__ . '/cmd.json';

if (($_GET['token'] ?? '') !== TOKEN) {
    http_response_code(403);
    echo '{"error":"unauthorized"}';
    exit;
}

// Poll : l'app HTML demande la dernière commande
if (isset($_GET['poll'])) {
    echo file_exists($file) ? file_get_contents($file) : '{"cmd":null,"ts":0}';
    exit;
}

// Commande depuis la Watch
$cmd = $_GET['p'] ?? '';
if (!in_array($cmd, ['p0','p1','undo'])) {
    http_response_code(400);
    echo '{"error":"use p=p0, p=p1 or p=undo"}';
    exit;
}

file_put_contents($file, json_encode(['cmd' => $cmd, 'ts' => microtime(true)]));
echo '{"ok":true}';
