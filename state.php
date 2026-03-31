<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$file = __DIR__ . '/game_state.json';

if($_SERVER['REQUEST_METHOD']==='POST'){
  $data = file_get_contents('php://input');
  if($data) file_put_contents($file, $data);
  echo '{"ok":true}';
} else {
  echo file_exists($file) ? file_get_contents($file) : '{}';
}
