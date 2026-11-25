<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/class_group_view.php';

$u = require_teacher();
$pdo = db();

$scope = (string)($_GET['scope'] ?? 'all'); // all | class | room
$scopeClass = (string)($_GET['class'] ?? '');
$scopeRoomId = (int)($_GET['room_id'] ?? 0);

if (!in_array($scope, ['all', 'class', 'room'], true)) {
  http_response_code(400);
  echo 'Ungültiger Stream-Scope.';
  exit;
}

// A teacher may only subscribe to the stream needed by the current view.
// Client-provided class/room values are validated before the session lock is released.
if (!is_admin_user($u)) {
  if ($scope === 'all') {
    http_response_code(403);
    echo 'Dieser Stream ist nur für Admins verfügbar.';
    exit;
  }

  if ($scope === 'class') {
    $stmt = $pdo->prepare("SELECT class_name FROM teacher_responsibility WHERE teacher_id=? LIMIT 1");
    $stmt->execute([(int)$u['id']]);
    $responsibleClass = (string)($stmt->fetchColumn() ?: '');
    if ($scopeClass === '' || $responsibleClass === '' || !hash_equals($responsibleClass, $scopeClass)) {
      http_response_code(403);
      echo 'Dieser Klassenstream ist nicht freigegeben.';
      exit;
    }
  }

  if ($scope === 'room') {
    if ($scopeRoomId <= 0) {
      http_response_code(400);
      echo 'Kein gültiger Raum angegeben.';
      exit;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM rooms WHERE id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$scopeRoomId]);
    if (!$stmt->fetchColumn()) {
      http_response_code(404);
      echo 'Raum nicht gefunden.';
      exit;
    }
  }
}

// Apply the same roster scope as the HTML view, not just client-side row hiding.
$groupStudentIds=null;
if($scope==='class'){
  class_groups_schema($pdo);
  try{$groupView=class_group_view($pdo,$u,$scopeClass,is_string($_GET['group']??null)?$_GET['group']:null);}
  catch(InvalidArgumentException $e){http_response_code(400);exit('Ungültiger Gruppenfilter.');}
  if($groupView['group_ids']){
    [$groupSql,$groupParams]=class_groups_condition($groupView['group_ids']);
    $st=$pdo->prepare("SELECT s.id FROM users s WHERE s.role='student' AND s.class_name=? $groupSql");$st->execute(array_merge([$scopeClass],$groupParams));
    $groupStudentIds=array_fill_keys(array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)),true);
  }
}

// Release the PHP session lock before opening the long-lived SSE stream.
if (session_status() === PHP_SESSION_ACTIVE) {
  session_write_close();
}

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');

$lastId = 0;
$hasClientLastId = false;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
  $lastId = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
  $hasClientLastId = true;
}
if (isset($_GET['last_id'])) {
  $lastId = max($lastId, (int)$_GET['last_id']);
  $hasClientLastId = true;
}

// On the first connection, start at the current event tail. Otherwise a proxy
// that drops Last-Event-ID can replay old room changes on every reconnect.
if (!$hasClientLastId) {
  $lastId = (int)($pdo->query("SELECT COALESCE(MAX(id), 0) FROM events")->fetchColumn() ?: 0);
}

echo "retry: 10000\n\n";
@ob_flush(); @flush();

$end = time() + 30; // short-lived; EventSource reconnects automatically

while (time() < $end) {
  $stmt = $pdo->prepare("SELECT id, type, payload FROM events WHERE id > ? ORDER BY id ASC LIMIT 50");
  $stmt->execute([$lastId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if ($rows) {
    foreach ($rows as $r) {
      $lastId = (int)$r['id'];
      $payload = $r['payload'] ?? '{}';

      if ($scope !== 'all') {
        $p = json_decode($payload, true) ?: [];
        if ($scope === 'class') {
          if ($r['type'] === 'reset') {
            $resetScope = (string)($p['scope'] ?? 'all');
            $resetClass = (string)($p['class_name'] ?? '');
            if ($resetScope === 'class' && $resetClass !== $scopeClass) continue;
            $payload = '{}';
          } elseif (($p['class_name'] ?? '') !== $scopeClass) {
            continue;
          } elseif ($r['type'] === 'assignment') {
            if($groupStudentIds!==null && !isset($groupStudentIds[(int)($p['student_id']??0)]))continue;
            $payload = json_encode([
              'student_id' => (int)($p['student_id'] ?? 0),
              'class_name' => (string)($p['class_name'] ?? ''),
              'room_id' => (int)($p['room_id'] ?? 0),
              'room_name' => (string)($p['room_name'] ?? ''),
              'updated_at' => (string)($p['updated_at'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          } else {
            $payload = '{}';
          }
        } elseif ($scope === 'room') {
          if ($r['type'] === 'reset') {
            if ((string)($p['scope'] ?? 'all') === 'class') continue;
            $payload = '{}';
          } else {
            if ((int)($p['room_id'] ?? 0) !== $scopeRoomId) continue;
            $payload = '{}';
          }
        }
      }

      // Admins viewing a filtered class also receive only that group's assignments.
      if($scope==='class' && $r['type']==='assignment' && $groupStudentIds!==null){
        $groupPayload=json_decode($payload,true)?:[];
        if(!isset($groupStudentIds[(int)($groupPayload['student_id']??0)]))continue;
      }

      echo "id: {$r['id']}\n";
      echo "event: {$r['type']}\n";
      echo "data: {$payload}\n\n";
    }
    @ob_flush(); @flush();
    continue;
  }

  echo ": ping\n\n";
  @ob_flush(); @flush();
  usleep(400000);
}
