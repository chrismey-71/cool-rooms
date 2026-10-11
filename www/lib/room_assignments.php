<?php
declare(strict_types=1);

final class RoomAssignmentCapacityException extends RuntimeException {}

/**
 * Assign active students while enforcing room capacity under a row lock.
 */
function assign_students_to_room(PDO $pdo, array $studentIds, int $roomId): int {
  $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn(int $id): bool => $id > 0)));
  if (!$studentIds || $roomId <= 0) return 0;

  $ownsTransaction = !$pdo->inTransaction();
  if ($ownsTransaction) $pdo->beginTransaction();

  try {
    $stmt = $pdo->prepare("SELECT id, name, capacity, is_active, is_teacher_only FROM rooms WHERE id=? FOR UPDATE");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room || (int)$room['is_active'] !== 1 || (int)($room['is_teacher_only'] ?? 0) === 1) {
      throw new InvalidArgumentException('Ungültiger oder nicht buchbarer Raum.');
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role='student' AND is_active=1 AND id IN ($placeholders)");
    $stmt->execute($studentIds);
    $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$validIds) {
      if ($ownsTransaction) $pdo->commit();
      return 0;
    }

    $validPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM room_assignments ra JOIN users s ON s.id=ra.student_id WHERE ra.room_id=? AND s.role='student' AND s.is_active=1 AND ra.student_id NOT IN ($validPlaceholders)");
    $stmt->execute([$roomId, ...$validIds]);
    $occupiedByOthers = (int)$stmt->fetchColumn();

    $capacity = (int)($room['capacity'] ?? 0);
    if ($capacity > 0 && ($occupiedByOthers + count($validIds)) > $capacity) {
      throw new RoomAssignmentCapacityException('Der Raum ist voll. Die Raumzuordnung wurde nicht geändert.');
    }

    $stmt = $pdo->prepare("INSERT INTO room_assignments (student_id, room_id) VALUES (?,?) ON DUPLICATE KEY UPDATE room_id=VALUES(room_id)");
    foreach ($validIds as $studentId) {
      $stmt->execute([$studentId, $roomId]);
    }

    if ($ownsTransaction) $pdo->commit();
    return count($validIds);
  } catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
