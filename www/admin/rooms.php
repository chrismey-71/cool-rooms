<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/room_groups.php';

$u = require_admin();
$pdo = db();

ensure_room_group_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;
    $capacityRaw = trim((string)($_POST['capacity'] ?? ''));
    $capacity = null;
    $teacherOnly = isset($_POST['is_teacher_only']) ? 1 : 0;
    $groupType = normalize_room_group_type((string)($_POST['group_type'] ?? ''));
    $groupName = trim((string)($_POST['group_name'] ?? ''));
    $groupColor = normalize_room_group_color((string)($_POST['group_color'] ?? ''));
    $groupNameLength = function_exists('mb_strlen') ? mb_strlen($groupName, 'UTF-8') : strlen($groupName);
    if ($groupType !== '' && $groupName === '') {
      flash_set('error', 'Bitte eine vorhandene Bezeichnung auswählen oder eine neue eingeben.');
      redirect(url('/admin/rooms.php'));
    }
    if ($groupType === '' && $groupName !== '') {
      flash_set('error', 'Bitte zuerst Etage, Ort oder Funktion auswählen.');
      redirect(url('/admin/rooms.php'));
    }
    if ($groupNameLength > 80) {
      flash_set('error', 'Die Bezeichnung darf höchstens 80 Zeichen lang sein.');
      redirect(url('/admin/rooms.php'));
    }
    if ($groupName === '') {
      $groupType = '';
      $groupColor = '';
    }
    if ($groupName !== '') {
      $groupColor = room_group_ensure_definition($pdo, $groupType, $groupName, $groupColor);
    }
    if ($capacityRaw !== '') {
      $capacity = (int)$capacityRaw;
      if ($capacity < 0) $capacity = 0;
    }
    if ($name === '') {
      flash_set('error', 'Name fehlt.');
      redirect(url('/admin/rooms.php'));
    }
    if ($id > 0) {
      $stmtOld = $pdo->prepare("SELECT * FROM rooms WHERE id=? LIMIT 1");
      $stmtOld->execute([$id]);
      $old = $stmtOld->fetch() ?: null;

      $stmt = $pdo->prepare("UPDATE rooms SET name=?, capacity=?, is_teacher_only=?, is_active=?, group_type=?, group_name=?, group_color=? WHERE id=?");
      $stmt->execute([$name, $capacity, $teacherOnly, $active, $groupType, $groupName, $groupColor, $id]);

      emit_event('admin_room_updated', [
        'actor_id' => (int)$u['id'],
        'actor_role' => 'admin',
        'room_id' => $id,
        'old' => $old,
        'new' => ['name'=>$name,'capacity'=>$capacity,'is_teacher_only'=>$teacherOnly,'is_active'=>$active,'group_type'=>$groupType,'group_name'=>$groupName,'group_color'=>$groupColor],
      ]);
      flash_set('success', 'Raum gespeichert.');
    } else {
      $stmt = $pdo->prepare("INSERT INTO rooms (name,capacity,is_teacher_only,is_active,group_type,group_name,group_color) VALUES (?,?,?,?,?,?,?)");
      $stmt->execute([$name, $capacity, $teacherOnly, $active, $groupType, $groupName, $groupColor]);

      emit_event('admin_room_created', [
        'actor_id' => (int)$u['id'],
        'actor_role' => 'admin',
        'room_id' => (int)$pdo->lastInsertId(),
        'new' => ['name'=>$name,'capacity'=>$capacity,'is_teacher_only'=>$teacherOnly,'is_active'=>$active,'group_type'=>$groupType,'group_name'=>$groupName,'group_color'=>$groupColor],
      ]);
      flash_set('success', 'Raum angelegt.');
    }
    redirect(url('/admin/rooms.php'));
  }

  if ($action === 'save_group_settings') {
    $typeSequence = is_array($_POST['type_sequence'] ?? null) ? $_POST['type_sequence'] : [];
    $groupSequences = is_array($_POST['group_sequence'] ?? null) ? $_POST['group_sequence'] : [];
    $groupNames = is_array($_POST['group_name_setting'] ?? null) ? $_POST['group_name_setting'] : [];
    $groupColors = is_array($_POST['group_color_setting'] ?? null) ? $_POST['group_color_setting'] : [];

    $storedGroups = $pdo->query("
      SELECT id, group_type, group_name
      FROM room_group_settings
      ORDER BY id
    ")->fetchAll();
    $groupsById = [];
    foreach ($storedGroups as $storedGroup) {
      $groupsById[(int)$storedGroup['id']] = $storedGroup;
    }

    $finalNames = [];
    foreach ($groupsById as $id => $storedGroup) {
      $submittedName = array_key_exists($id, $groupNames)
        ? trim((string)$groupNames[$id])
        : (string)$storedGroup['group_name'];
      $nameLength = function_exists('mb_strlen') ? mb_strlen($submittedName, 'UTF-8') : strlen($submittedName);
      if ($submittedName === '') {
        flash_set('error', 'Bezeichnungen für Etagen, Orte und Funktionen dürfen nicht leer sein.');
        redirect(url('/admin/rooms.php#group-order'));
      }
      if ($nameLength > 80) {
        flash_set('error', 'Eine Bezeichnung ist länger als 80 Zeichen.');
        redirect(url('/admin/rooms.php#group-order'));
      }
      $finalNames[$id] = $submittedName;
    }

    $usedNames = [];
    foreach ($groupsById as $id => $storedGroup) {
      $type = (string)$storedGroup['group_type'];
      $name = $finalNames[$id];
      $normalizedName = function_exists('mb_strtolower')
        ? mb_strtolower($name, 'UTF-8')
        : strtolower($name);
      $uniqueKey = $type . "\0" . $normalizedName;
      if (isset($usedNames[$uniqueKey])) {
        flash_set('error', room_group_type_label($type) . ": Die Bezeichnung „$name“ ist mehrfach vorhanden.");
        redirect(url('/admin/rooms.php#group-order'));
      }
      $usedNames[$uniqueKey] = true;
    }

    $pdo->beginTransaction();
    try {
      $renameSettings = $pdo->prepare("
        UPDATE room_group_settings SET group_name = ? WHERE id = ? AND group_type = ?
      ");
      $renameRooms = $pdo->prepare("
        UPDATE rooms SET group_name = ? WHERE group_type = ? AND group_name = ?
      ");
      $renames = [];
      foreach ($groupsById as $id => $storedGroup) {
        $oldName = (string)$storedGroup['group_name'];
        $newName = $finalNames[$id];
        if ($newName === $oldName) continue;

        $temporaryName = '__tmp_' . $id . '_' . bin2hex(random_bytes(8));
        $type = (string)$storedGroup['group_type'];
        $renameSettings->execute([$temporaryName, $id, $type]);
        $renameRooms->execute([$temporaryName, $type, $oldName]);
        $renames[] = [
          'id' => $id,
          'type' => $type,
          'temporary_name' => $temporaryName,
          'new_name' => $newName,
        ];
      }
      foreach ($renames as $rename) {
        $renameSettings->execute([$rename['new_name'], $rename['id'], $rename['type']]);
        $renameRooms->execute([$rename['new_name'], $rename['type'], $rename['temporary_name']]);
      }

      $updateType = $pdo->prepare("
        UPDATE room_group_type_settings SET sort_order = ? WHERE group_type = ?
      ");
      $validTypes = array_keys(room_group_types());
      $savedTypes = [];
      $typePosition = 1;
      foreach ($typeSequence as $typeRaw) {
        $type = normalize_room_group_type((string)$typeRaw);
        if ($type === '' || isset($savedTypes[$type])) continue;
        $updateType->execute([$typePosition++, $type]);
        $savedTypes[$type] = true;
      }
      foreach ($validTypes as $type) {
        if (isset($savedTypes[$type])) continue;
        $updateType->execute([$typePosition++, $type]);
      }

      $updateGroup = $pdo->prepare("
        UPDATE room_group_settings SET sort_order = ? WHERE id = ? AND group_type = ?
      ");
      $updateColor = $pdo->prepare("
        UPDATE room_group_settings SET group_color = ? WHERE id = ?
      ");
      $syncRooms = $pdo->prepare("
        UPDATE rooms r
        JOIN room_group_settings rg ON rg.id = ?
        SET r.group_color = ?
        WHERE r.group_type = rg.group_type AND r.group_name = rg.group_name
      ");

      foreach ($validTypes as $type) {
        $sequence = is_array($groupSequences[$type] ?? null) ? $groupSequences[$type] : [];
        $savedGroups = [];
        $groupPosition = 1;
        foreach ($sequence as $idRaw) {
          $id = (int)$idRaw;
          if ($id <= 0 || isset($savedGroups[$id])) continue;
          $updateGroup->execute([$groupPosition++, $id, $type]);
          $savedGroups[$id] = true;
        }
      }

      foreach ($groupColors as $idRaw => $colorRaw) {
        $id = (int)$idRaw;
        if ($id <= 0) continue;
        $color = normalize_room_group_color((string)$colorRaw);
        if ($color === '') $color = '#2F6F3A';
        $updateColor->execute([$color, $id]);
        $syncRooms->execute([$id, $color]);
      }
      $pdo->commit();
      flash_set('success', 'Bezeichnungen, Reihenfolge und Farben der Raumgruppen gespeichert.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
        flash_set('error', 'Die Bezeichnung ist in dieser Gruppierung bereits vorhanden.');
        redirect(url('/admin/rooms.php#group-order'));
      }
      throw $e;
    }
    redirect(url('/admin/rooms.php#group-order'));
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $stmtOld = $pdo->prepare("SELECT * FROM rooms WHERE id=? LIMIT 1");
    $stmtOld->execute([$id]);
    $old = $stmtOld->fetch() ?: null;

    $stmt = $pdo->prepare("DELETE FROM rooms WHERE id=?");
    $stmt->execute([$id]);

    emit_event('admin_room_deleted', [
      'actor_id' => (int)$u['id'],
      'actor_role' => 'admin',
      'room_id' => $id,
      'old' => $old,
    ]);
    flash_set('success', 'Raum gelöscht.');
    redirect(url('/admin/rooms.php'));
  }
}

$edit = null;
if (isset($_GET['edit'])) {
  $id = (int)$_GET['edit'];
  $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id=?");
  $stmt->execute([$id]);
  $edit = $stmt->fetch() ?: null;
}

$rooms = $pdo->query("
  SELECT r.*
  FROM rooms r
  LEFT JOIN room_group_type_settings rgt ON rgt.group_type = r.group_type
  LEFT JOIN room_group_settings rg ON rg.group_type = r.group_type AND rg.group_name = r.group_name
  ORDER BY r.is_active DESC, COALESCE(rgt.sort_order, 9999), COALESCE(rg.sort_order, 9999), r.name
")->fetchAll();
$roomGroups = $pdo->query("
  SELECT rg.id, rg.group_type, rg.group_name, rg.group_color, rg.sort_order,
         COALESCE(rgt.sort_order, 9999) AS type_sort_order,
         COUNT(r.id) AS room_count
  FROM room_group_settings rg
  LEFT JOIN room_group_type_settings rgt ON rgt.group_type = rg.group_type
  LEFT JOIN rooms r ON r.group_type = rg.group_type AND r.group_name = rg.group_name
  GROUP BY rg.id, rg.group_type, rg.group_name, rg.group_color, rg.sort_order, rgt.sort_order
  ORDER BY type_sort_order, rg.sort_order, rg.group_name
")->fetchAll();
$roomGroupTypes = $pdo->query("
  SELECT group_type, sort_order
  FROM room_group_type_settings
  ORDER BY sort_order, group_type
")->fetchAll();
$typeOrderMap = [];
foreach ($roomGroupTypes as $roomGroupType) {
  $typeOrderMap[(string)$roomGroupType['group_type']] = (int)$roomGroupType['sort_order'];
}
$orderedGroupTypes = array_keys(room_group_types());
usort($orderedGroupTypes, static function (string $a, string $b) use ($typeOrderMap): int {
  $orderComparison = ($typeOrderMap[$a] ?? 9999) <=> ($typeOrderMap[$b] ?? 9999);
  return $orderComparison !== 0 ? $orderComparison : strcmp($a, $b);
});
$roomGroupsByType = [];
foreach ($roomGroups as $group) {
  $type = normalize_room_group_type((string)$group['group_type']);
  if ($type === '') continue;
  $roomGroupsByType[$type][] = $group;
}
$roomGroupNamesByType = [];
foreach (array_keys(room_group_types()) as $type) {
  $roomGroupNamesByType[$type] = array_map(
    static fn(array $group): string => (string)$group['group_name'],
    $roomGroupsByType[$type] ?? []
  );
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Räume verwalten</h2>

  <div class="card">
    <h3><?= $edit ? 'Bearbeiten' : 'Neu' ?></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h((string)($edit['id'] ?? 0)) ?>">

      <div class="row">
        <div><label>Raumname</label><input name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
        <div><label>Kapazität (leer = unbegrenzt)</label><input name="capacity" type="number" min="0" value="<?= h((string)($edit['capacity'] ?? '')) ?>" placeholder="z.B. 20"></div>
        <div>
          <label>Gruppierung</label>
          <select name="group_type" id="roomGroupTypeSelect">
            <?php $gt = (string)($edit['group_type'] ?? ''); ?>
            <option value="" <?= $gt === '' ? 'selected' : '' ?>>-- keine --</option>
            <option value="floor" <?= $gt === 'floor' ? 'selected' : '' ?>>Etage</option>
            <option value="location" <?= $gt === 'location' ? 'selected' : '' ?>>Ort</option>
            <option value="function" <?= $gt === 'function' ? 'selected' : '' ?>>Funktion</option>
          </select>
        </div>
        <div id="roomGroupNameField" <?= $gt === '' ? 'hidden' : '' ?>>
          <label id="roomGroupNameLabel"><?= h($gt !== '' ? room_group_type_label($gt) : 'Etage / Ort / Funktion') ?></label>
          <input name="group_name" id="roomGroupNameInput" list="room-groups" maxlength="80" value="<?= h((string)($edit['group_name'] ?? '')) ?>" placeholder="Vorhandene Bezeichnung wählen oder neue eingeben" <?= $gt === '' ? 'disabled' : 'required' ?>>
          <datalist id="room-groups">
            <?php foreach (($roomGroupsByType[$gt] ?? []) as $group): ?>
              <option value="<?= h((string)$group['group_name']) ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <div class="room-group-suggestions" id="roomGroupSuggestions" aria-label="Vorhandene Bezeichnungen"></div>
          <p class="small">Vorhandenen Eintrag auswählen oder eine neue Bezeichnung eingeben.</p>
        </div>
        <div id="roomGroupColorField" style="flex:0.5;min-width:160px;" <?= $gt === '' ? 'hidden' : '' ?>>
          <label>Farbe (bei neuer Gruppe)</label>
          <input name="group_color" id="roomGroupColorInput" type="color" value="<?= h((string)(($edit['group_color'] ?? '') ?: '#2F6F3A')) ?>" <?= $gt === '' ? 'disabled' : '' ?>>
          <p class="small">Bestehende Gruppen unten zentral ändern.</p>
        </div>
        <div style="flex:0.6;min-width:220px;">
          <label>Nur Lehrer:innen</label><br>
          <input type="checkbox" name="is_teacher_only" style="width:auto"
            <?= ($edit && (int)($edit['is_teacher_only'] ?? 0)===1) ? 'checked' : '' ?>>
          <p class="small">z.B. Lehrerzimmer</p>
        </div>
        <div style="flex:0.6;min-width:180px;">
          <label>Aktiv</label><br>
          <input type="checkbox" name="is_active" style="width:auto"
            <?= (!$edit) ? 'checked' : (((int)$edit['is_active']===1)?'checked':'') ?>>
        </div>
      </div>

      <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
    </form>
  </div>

  <div class="card" id="group-order">
    <h3>Raumgruppen verwalten</h3>
    <p class="small">Hier kannst du Bezeichnungen und Farben ändern oder die Einträge am Griff sortieren. Eine geänderte Bezeichnung wird automatisch bei allen zugeordneten Räumen übernommen.</p>
    <form method="post" id="roomGroupOrderForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_group_settings">

      <div class="room-order-list room-order-types" data-sortable-list="types">
        <?php foreach ($orderedGroupTypes as $type): ?>
          <?php $groupsForType = $roomGroupsByType[$type] ?? []; ?>
          <section class="room-order-type" data-sortable-item>
            <input type="hidden" name="type_sequence[]" value="<?= h($type) ?>">
            <div class="room-order-type__header">
              <button type="button" class="room-drag-handle" draggable="true" aria-label="<?= h(room_group_type_label($type)) ?> verschieben">Ziehen</button>
              <h4><?= h(room_group_type_label($type)) ?></h4>
              <span class="badge"><?= count($groupsForType) === 1 ? '1 Gruppe' : count($groupsForType) . ' Gruppen' ?></span>
            </div>

            <?php if ($groupsForType): ?>
              <div class="room-order-list room-order-groups" data-sortable-list="groups">
                <?php foreach ($groupsForType as $group): ?>
                  <div class="room-order-group" data-sortable-item>
                    <input type="hidden" name="group_sequence[<?= h($type) ?>][]" value="<?= (int)$group['id'] ?>">
                    <button type="button" class="room-drag-handle" draggable="true" aria-label="<?= h((string)$group['group_name']) ?> verschieben">Ziehen</button>
                    <label class="room-order-name">
                      <span>Bezeichnung</span>
                      <input name="group_name_setting[<?= (int)$group['id'] ?>]" maxlength="80" value="<?= h((string)$group['group_name']) ?>" required>
                    </label>
                    <span class="small"><?= h((string)$group['room_count']) ?> Räume</span>
                    <label class="room-order-color">
                      <span>Farbe</span>
                      <input type="color" name="group_color_setting[<?= (int)$group['id'] ?>]" value="<?= h((string)($group['group_color'] ?: '#2F6F3A')) ?>">
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="small room-order-empty">Noch keine Einträge in diesem Bereich.</p>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
      <p style="margin-top:12px;"><button type="submit">Änderungen speichern</button></p>
    </form>
  </div>

  <table class="table">
    <thead><tr><th>Status</th><th>Name</th><th>Gruppe</th><th>Typ</th><th>Kapazität</th><th>Aktion</th></tr></thead>
    <tbody>
      <?php foreach ($rooms as $r): ?>
        <tr>
          <td>
            <?php if ((int)$r['is_active']===1): ?>
              <span class="badge ok">aktiv</span>
            <?php else: ?>
              <span class="badge warn">inaktiv</span>
            <?php endif; ?>
          </td>
          <td><?= h($r['name']) ?></td>
          <td>
            <?php if (!empty($r['group_name'])): ?>
              <span class="badge" style="border-color:<?= h((string)($r['group_color'] ?: '#2F6F3A')) ?>; color:<?= h((string)($r['group_color'] ?: '#2F6F3A')) ?>">
                <?= h(room_group_type_label((string)($r['group_type'] ?? ''))) ?>: <?= h((string)$r['group_name']) ?>
              </span>
            <?php else: ?>
              <span class="small">keine</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int)($r['is_teacher_only'] ?? 0)===1): ?>
              <span class="badge warn">nur Lehrer:innen</span>
            <?php else: ?>
              <span class="badge ok">normal</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['capacity'] === null || $r['capacity'] === ''): ?>
              <span class="badge">∞</span>
            <?php else: ?>
              <span class="badge"><?= h((string)$r['capacity']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <a href="<?= h(url('/admin/rooms.php?edit='.(int)$r['id'])) ?>">Bearbeiten</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Raum wirklich löschen?')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
              <button class="danger small" style="margin-left:6px;">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<style>
.room-order-list{display:grid;gap:10px}
.room-order-types{margin-top:16px}
.room-order-type{border:1px solid var(--border);border-radius:14px;background:#f8fafc;padding:12px}
.room-order-type__header{display:flex;align-items:center;gap:10px}
.room-order-type__header h4{margin:0;flex:1}
.room-order-groups{margin-top:10px}
.room-order-group{display:grid;grid-template-columns:auto minmax(140px,1fr) auto minmax(110px,150px);align-items:center;gap:12px;padding:10px;border:1px solid var(--border);border-radius:12px;background:#fff}
.room-drag-handle{width:auto;padding:7px 10px;background:#e4e7ec;color:#344054;cursor:grab;touch-action:none;user-select:none}
.room-drag-handle:active{cursor:grabbing}
.room-order-name{margin:0}
.room-order-name span,.room-order-color span{font-size:12px}
.room-order-name input{margin-top:3px}
.room-order-color{display:flex;align-items:center;gap:8px;margin:0}
.room-order-color input{height:38px;padding:3px}
.room-order-empty{margin:10px 0 0 0}
.room-order-type.is-dragging,.room-order-group.is-dragging{opacity:.42;border-style:dashed}
.room-group-suggestions{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px}
.room-group-suggestion{width:auto;min-height:0;padding:5px 9px;border:1px solid var(--border);border-radius:999px;background:#f2f4f7;color:#344054;font-size:12px}
.room-group-suggestion:hover{background:#e4e7ec}
@media (max-width:700px){
  .room-order-group{grid-template-columns:auto 1fr auto}
  .room-order-color{grid-column:2 / -1}
}
</style>
<script>
(() => {
  const groupOptions = <?= json_encode($roomGroupNamesByType, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const groupLabels = {
    floor: 'Etage',
    location: 'Ort',
    function: 'Funktion'
  };
  const typeSelect = document.getElementById('roomGroupTypeSelect');
  const nameField = document.getElementById('roomGroupNameField');
  const nameLabel = document.getElementById('roomGroupNameLabel');
  const nameInput = document.getElementById('roomGroupNameInput');
  const optionList = document.getElementById('room-groups');
  const suggestions = document.getElementById('roomGroupSuggestions');
  const colorField = document.getElementById('roomGroupColorField');
  const colorInput = document.getElementById('roomGroupColorInput');

  const updateRoomGroupFields = (clearName) => {
    if (!typeSelect || !nameField || !nameLabel || !nameInput || !optionList || !suggestions || !colorField || !colorInput) return;
    const type = typeSelect.value;
    const enabled = Object.prototype.hasOwnProperty.call(groupLabels, type);
    nameField.hidden = !enabled;
    colorField.hidden = !enabled;
    nameInput.disabled = !enabled;
    nameInput.required = enabled;
    colorInput.disabled = !enabled;
    if (clearName) nameInput.value = '';
    optionList.replaceChildren();
    suggestions.replaceChildren();
    if (!enabled) return;

    nameLabel.textContent = groupLabels[type];
    const names = Array.isArray(groupOptions[type]) ? groupOptions[type] : [];
    names.forEach((name) => {
      const option = document.createElement('option');
      option.value = name;
      optionList.appendChild(option);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'room-group-suggestion';
      button.textContent = name;
      button.addEventListener('click', () => {
        nameInput.value = name;
        nameInput.focus();
      });
      suggestions.appendChild(button);
    });
    nameInput.placeholder = names.length > 0
      ? 'Vorhandene Bezeichnung wählen oder neue eingeben'
      : 'Neue Bezeichnung eingeben';
  };

  typeSelect?.addEventListener('change', () => updateRoomGroupFields(true));
  updateRoomGroupFields(false);

  const form = document.getElementById('roomGroupOrderForm');
  if (!form) return;

  let draggedItem = null;

  form.querySelectorAll('.room-drag-handle').forEach((handle) => {
    handle.addEventListener('dragstart', (event) => {
      draggedItem = handle.closest('[data-sortable-item]');
      if (!draggedItem || !event.dataTransfer) return;
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', 'room-order');
      requestAnimationFrame(() => draggedItem?.classList.add('is-dragging'));
    });

    handle.addEventListener('dragend', () => {
      draggedItem?.classList.remove('is-dragging');
      draggedItem = null;
    });
  });

  form.querySelectorAll('[data-sortable-list]').forEach((list) => {
    list.addEventListener('dragover', (event) => {
      if (!draggedItem || draggedItem.parentElement !== list) return;
      event.preventDefault();
      event.stopPropagation();
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';

      const siblings = Array.from(list.children).filter((item) => item !== draggedItem);
      const nextItem = siblings.find((item) => {
        const box = item.getBoundingClientRect();
        return event.clientY < box.top + box.height / 2;
      });
      list.insertBefore(draggedItem, nextItem ?? null);
    });

    list.addEventListener('drop', (event) => {
      if (!draggedItem || draggedItem.parentElement !== list) return;
      event.preventDefault();
      event.stopPropagation();
    });
  });
})();
</script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
