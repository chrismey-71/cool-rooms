<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';

function daily_reset_counts(PDO $pdo): array {
  return [
    'deleted_student_assignments' => (int)$pdo->query('SELECT COUNT(*) FROM room_assignments')->fetchColumn(),
    'deleted_teacher_locations' => (int)$pdo->query('SELECT COUNT(*) FROM teacher_locations')->fetchColumn(),
    'deleted_teacher_responsibilities' => (int)$pdo->query('SELECT COUNT(*) FROM teacher_responsibility')->fetchColumn(),
    'students_set_present' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=0")->fetchColumn(),
  ];
}

/** Reset daily state and write both audit and SSE events atomically (InnoDB). */
function reset_all_assignments(array $actor, string $source = 'admin'): array {
  $pdo = db();
  $typeMaxLength = ensure_events_schema($pdo);
  $pdo->beginTransaction();
  try {
    $counts = [
      'deleted_student_assignments' => $pdo->exec('DELETE FROM room_assignments'),
      'deleted_teacher_locations' => $pdo->exec('DELETE FROM teacher_locations'),
      'deleted_teacher_responsibilities' => $pdo->exec('DELETE FROM teacher_responsibility'),
      'students_set_present' => $pdo->exec("UPDATE users SET is_active=1 WHERE role='student' AND is_active=0"),
    ];
    $actorPayload = [
      'actor_id' => (int)($actor['id'] ?? 0),
      'actor_role' => (string)($actor['role'] ?? 'system'),
      'actor_name' => (string)($actor['name'] ?? ''),
      'actor_username' => (string)($actor['username'] ?? ''),
    ];
    emit_event($source === 'cron' ? 'cron_reset_all_assignments' : 'admin_reset_all_assignments',
      array_merge($actorPayload, $counts, ['source' => $source]), $typeMaxLength);
    // Preserve the event consumed by existing live views and reports.
    emit_event('reset', array_merge($actorPayload, [
      'by' => $actorPayload['actor_id'],
      'source' => $source,
      'scope' => 'all',
    ]), $typeMaxLength);
    $pdo->commit();
    return $counts;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
