<?php
declare(strict_types=1);

function room_group_types(): array {
  return [
    'floor' => 'Etage',
    'location' => 'Ort',
    'function' => 'Funktion',
  ];
}

function normalize_room_group_type(string $type): string {
  return array_key_exists($type, room_group_types()) ? $type : '';
}

function normalize_room_group_color(string $color): string {
  $color = trim($color);
  return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtoupper($color) : '';
}

function room_group_type_label(string $type): string {
  return room_group_types()[$type] ?? 'Bereich';
}

function fallback_room_group_color(string $key): string {
  $palette = ['#2F6F3A', '#175CD3', '#B54708', '#7A5AF8', '#C11574', '#0F766E', '#344054'];
  return $palette[abs((int)crc32($key)) % count($palette)];
}

function ensure_room_group_schema(PDO $pdo): void {
  static $ready = false;
  if ($ready) return;

  $columns = [
    'group_type' => "ALTER TABLE rooms ADD COLUMN group_type VARCHAR(20) NULL",
    'group_name' => "ALTER TABLE rooms ADD COLUMN group_name VARCHAR(80) NULL",
    'group_color' => "ALTER TABLE rooms ADD COLUMN group_color VARCHAR(7) NULL",
  ];

  $columnCheck = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rooms'
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  foreach ($columns as $column => $sql) {
    $columnCheck->execute([$column]);
    if (!$columnCheck->fetch()) {
      $pdo->exec($sql);
    }
  }

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS room_group_type_settings (
      group_type VARCHAR(20) NOT NULL PRIMARY KEY,
      sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS room_group_settings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      group_type VARCHAR(20) NOT NULL,
      group_name VARCHAR(80) NOT NULL,
      group_color VARCHAR(7) NULL,
      sort_order INT NOT NULL DEFAULT 0,
      UNIQUE KEY uq_room_group (group_type, group_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $insertType = $pdo->prepare("
    INSERT IGNORE INTO room_group_type_settings (group_type, sort_order)
    VALUES (?, ?)
  ");
  foreach (array_keys(room_group_types()) as $index => $type) {
    $insertType->execute([$type, $index + 1]);
  }

  room_group_seed_from_rooms($pdo);
  $ready = true;
}

function room_group_seed_from_rooms(PDO $pdo): void {
  $rows = $pdo->query("
    SELECT group_type, group_name, MAX(group_color) AS group_color
    FROM rooms
    WHERE group_type IN ('floor', 'location', 'function')
      AND group_name IS NOT NULL
      AND group_name <> ''
    GROUP BY group_type, group_name
    ORDER BY group_type, group_name
  ")->fetchAll();

  $maxOrderStmt = $pdo->prepare("
    SELECT COALESCE(MAX(sort_order), 0)
    FROM room_group_settings
    WHERE group_type = ?
  ");
  $insertGroup = $pdo->prepare("
    INSERT IGNORE INTO room_group_settings (group_type, group_name, group_color, sort_order)
    VALUES (?, ?, ?, ?)
  ");
  $nextOrders = [];

  foreach ($rows as $row) {
    $type = normalize_room_group_type((string)$row['group_type']);
    $name = trim((string)$row['group_name']);
    if ($type === '' || $name === '') continue;

    if (!isset($nextOrders[$type])) {
      $maxOrderStmt->execute([$type]);
      $nextOrders[$type] = (int)$maxOrderStmt->fetchColumn() + 1;
    }
    $color = normalize_room_group_color((string)($row['group_color'] ?? ''));
    if ($color === '') $color = fallback_room_group_color($type . '|' . $name);
    $insertGroup->execute([$type, $name, $color, $nextOrders[$type]]);
    if ($insertGroup->rowCount() > 0) {
      $nextOrders[$type]++;
    }
  }
}

function room_group_ensure_definition(PDO $pdo, string $type, string $name, string $suggestedColor): string {
  $type = normalize_room_group_type($type);
  $name = trim($name);
  if ($type === '' || $name === '') return '';

  $find = $pdo->prepare("
    SELECT group_color
    FROM room_group_settings
    WHERE group_type = ? AND group_name = ?
    LIMIT 1
  ");
  $find->execute([$type, $name]);
  $existingColor = $find->fetchColumn();
  if ($existingColor !== false) {
    $color = normalize_room_group_color((string)$existingColor);
    return $color !== '' ? $color : fallback_room_group_color($type . '|' . $name);
  }

  $maxOrder = $pdo->prepare("
    SELECT COALESCE(MAX(sort_order), 0) + 1
    FROM room_group_settings
    WHERE group_type = ?
  ");
  $maxOrder->execute([$type]);
  $sortOrder = max(1, (int)$maxOrder->fetchColumn());
  $color = normalize_room_group_color($suggestedColor);
  if ($color === '') $color = fallback_room_group_color($type . '|' . $name);

  $insert = $pdo->prepare("
    INSERT INTO room_group_settings (group_type, group_name, group_color, sort_order)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
  ");
  $insert->execute([$type, $name, $color, $sortOrder]);

  $find->execute([$type, $name]);
  $storedColor = normalize_room_group_color((string)$find->fetchColumn());
  return $storedColor !== '' ? $storedColor : $color;
}
