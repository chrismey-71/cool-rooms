<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/room_groups.php';
require_once __DIR__ . '/../lib/room_assignments.php';

$u = require_student();
$pdo = db();

ensure_room_group_schema($pdo);

$classRoom = null;
$classRoomId = 0;
if (!empty($u['class_name'])) {
  try {
    $stmt = $pdo->prepare("
      SELECT r.id, r.name
      FROM class_settings cs
      JOIN rooms r ON r.id = cs.home_room_id
      WHERE cs.class_name=? AND r.is_active=1 AND (r.is_teacher_only=0 OR r.is_teacher_only IS NULL)
      LIMIT 1
    ");
    $stmt->execute([(string)$u['class_name']]);
    $classRoom = $stmt->fetch() ?: null;
    $classRoomId = (int)($classRoom['id'] ?? 0);
  } catch (Throwable $e) {
    $classRoom = null;
    $classRoomId = 0;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $roomId = (int)($_POST['room_id'] ?? 0);

  if ($roomId === 0) {
    // Set to "not checked in": remove assignment
    $stmt = $pdo->prepare("DELETE FROM room_assignments WHERE student_id=?");
    $stmt->execute([(int)$u['id']]);

    emit_event('assignment', [
      'student_id' => (int)$u['id'],
      'class_name' => (string)($u['class_name'] ?? ''),
      'room_id' => 0,
      'room_name' => 'nicht eingebucht',
      'updated_at' => date('Y-m-d H:i:s'),
    ]);

    flash_set('success', 'Status gespeichert: nicht eingebucht.');
    redirect(url('/student/home.php'));
  }

  $stmt = $pdo->prepare("SELECT id, name FROM rooms WHERE id=? AND is_active=1 AND (is_teacher_only=0 OR is_teacher_only IS NULL)");
  $stmt->execute([$roomId]);
  $room = $stmt->fetch();

  if (!$room) {
    flash_set('error', 'Ungültiger Raum.');
    redirect(url('/student/home.php'));
  }

  try {
    assign_students_to_room($pdo, [(int)$u['id']], $roomId);
  } catch (RoomAssignmentCapacityException|InvalidArgumentException $e) {
    flash_set('error', $e->getMessage());
    redirect(url('/student/home.php'));
  }

  // current timestamp
  $stmt = $pdo->prepare("SELECT updated_at FROM room_assignments WHERE student_id=?");
  $stmt->execute([(int)$u['id']]);
  $ts = (string)($stmt->fetchColumn() ?: '');

  emit_event('assignment', [
    'student_id' => (int)$u['id'],
    'class_name' => (string)($u['class_name'] ?? ''),
    'room_id' => (int)$roomId,
    'room_name' => (string)($room['name'] ?? ''),
    'updated_at' => $ts,
  ]);

  flash_set('success', 'Raum gespeichert.');
  redirect(url('/student/home.php'));
}

$stmt = $pdo->prepare("
  SELECT r.id, r.name, r.capacity, r.group_type, r.group_name,
         COALESCE(rg.group_color, r.group_color) AS group_color,
         COALESCE(rgt.sort_order, 9999) AS type_sort_order,
         COALESCE(rg.sort_order, 9999) AS group_sort_order,
         COUNT(ra.student_id) AS occupied,
         CASE WHEN r.id = ? THEN 1 ELSE 0 END AS is_class_room
  FROM rooms r
  LEFT JOIN room_assignments ra ON ra.room_id = r.id
  LEFT JOIN room_group_type_settings rgt ON rgt.group_type = r.group_type
  LEFT JOIN room_group_settings rg ON rg.group_type = r.group_type AND rg.group_name = r.group_name
  WHERE r.is_active=1 AND (r.is_teacher_only=0 OR r.is_teacher_only IS NULL)
  GROUP BY r.id, r.name, r.capacity, r.group_type, r.group_name, r.group_color,
           rg.group_color, rgt.sort_order, rg.sort_order
  ORDER BY type_sort_order, group_sort_order, COALESCE(NULLIF(r.group_name,''), 'zzzz'), is_class_room DESC, r.name
");
$stmt->execute([$classRoomId]);
$rooms = $stmt->fetchAll();
$roomGroups = [];
foreach ($rooms as $room) {
  $groupName = trim((string)($room['group_name'] ?? ''));
  $groupType = trim((string)($room['group_type'] ?? ''));
  $groupKey = $groupType . '|' . $groupName;
  if ($groupName === '') {
    $groupName = 'Weitere Räume';
    $groupType = '';
    $groupKey = 'other|';
  }
  if (!isset($roomGroups[$groupKey])) {
    $color = trim((string)($room['group_color'] ?? ''));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
      $color = fallback_room_group_color($groupKey);
    }
    $roomGroups[$groupKey] = [
      'type' => $groupType,
      'name' => $groupName,
      'color' => strtoupper($color),
      'rooms' => [],
    ];
  }
  $roomGroups[$groupKey]['rooms'][] = $room;
}

$stmt = $pdo->prepare("
  SELECT r.id, r.name, ra.updated_at
  FROM room_assignments ra
  JOIN rooms r ON r.id = ra.room_id
  WHERE ra.student_id=?
");
$stmt->execute([(int)$u['id']]);
$current = $stmt->fetch() ?: null;

require __DIR__ . '/../partials/header.php';
require __DIR__ . '/../partials/student_rooms.php';
require __DIR__ . '/../partials/footer.php';
