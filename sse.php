<?php
// sse.php — Server-Sent Events for live viewer
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // disable nginx buffering
header('Access-Control-Allow-Origin: *');

$file = __DIR__ . '/game_state.json';
$last = '';

// Send for up to 30s then client reconnects
$end = time() + 30;

while(time() < $end){
  if(file_exists($file)){
    $data = file_get_contents($file);
    if($data && $data !== $last){
      $last = $data;
      echo "data: " . trim($data) . "\n\n";
      if(ob_get_level()) ob_flush();
      flush();
    }
  }
  usleep(500000); // 500ms
}
// Tell client to reconnect
echo "retry: 1000\n\n";
if(ob_get_level()) ob_flush();
flush();
