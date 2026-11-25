<?php
declare(strict_types=1);
$roomScriptVersion=filemtime(__DIR__.'/../assets/student-rooms.js');
?>
<div class="card student-dashboard" data-student-rooms>
  <div class="student-hero">
    <h2>Hallo <?= h($u['first_name']) ?></h2>
    <p class="student-hero__meta">
      <span><strong>Klasse:</strong> <?= h($u['class_name'] ?? '') ?></span>
      <span><strong>Dein Klassenraum ist:</strong> <b class="student-hero__classroom"><?= h($classRoom ? (string)$classRoom['name'] : 'nicht festgelegt') ?></b></span>
    </p>
  </div>

  <div class="student-current-status <?= $current ? 'has-room' : 'no-room' ?>">
    <span class="student-current-status__label">Du bist derzeit im Raum:</span>
    <strong><?= h($current ? (string)$current['name'] : 'Nicht eingebucht') ?></strong>
  </div>

  <section class="student-room-picker" aria-labelledby="room-picker-title">
    <div class="student-room-picker__header">
      <h3 id="room-picker-title">Raum wählen</h3>
      <span>Raum antippen</span>
    </div>

    <div class="student-room-tools" data-room-tools hidden>
      <label class="student-room-search" for="room-query">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg>
        <span class="student-room-sr">Raum oder Bereich suchen</span>
        <input id="room-query" type="search" placeholder="Raum oder Bereich suchen …" autocomplete="off" data-room-query>
      </label>
      <div class="student-room-tools__filters">
        <label class="student-room-area" for="room-area"><span class="student-room-sr">Bereich auswählen</span>
          <select id="room-area" data-room-area>
            <option value="">Alle Bereiche</option>
            <?php foreach(array_values($roomGroups) as $index=>$group): ?>
              <option value="<?= $index ?>"><?= h(room_group_type_label((string)$group['type']).' · '.$group['name']) ?> (<?= count($group['rooms']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="student-room-free"><input type="checkbox" data-room-free> Nur freie</label>
      </div>
      <div class="student-room-results">
        <span role="status" aria-live="polite" aria-atomic="true" data-room-count></span>
        <button type="button" data-room-clear hidden>Filter zurücksetzen</button>
      </div>
    </div>

    <?php if(!$rooms): ?><p class="small">Derzeit sind keine Räume zur Auswahl freigegeben.</p><?php endif; ?>
    <p class="student-room-empty" data-room-empty hidden>Kein Raum gefunden. Prüfe den Suchbegriff oder setze die Filter zurück.</p>

    <form method="post" class="student-room-booking">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="student-room-groups" id="roomStatusTable">
        <?php foreach(array_values($roomGroups) as $index=>$group): ?>
          <section class="student-room-group" data-room-group="<?= $index ?>" data-room-area-name="<?= h(room_group_type_label((string)$group['type']).' '.$group['name']) ?>" style="--room-group-color:<?= h((string)$group['color']) ?>" aria-labelledby="room-group-<?= $index ?>">
            <div class="student-room-group__header">
              <div><span class="student-room-group__label"><?= h(room_group_type_label((string)$group['type'])) ?></span><h4 id="room-group-<?= $index ?>"><?= h((string)$group['name']) ?></h4></div>
              <span class="student-room-group__count" data-group-count><?= count($group['rooms']) ?> <?= count($group['rooms'])===1?'Raum':'Räume' ?></span>
            </div>
            <div class="student-room-card-grid">
              <?php foreach($group['rooms'] as $rr):
                $cap=$rr['capacity'];$occ=(int)$rr['occupied'];
                $unlimited=($cap===null || $cap==='' || (int)$cap===0);
                $free=$unlimited?null:max((int)$cap-$occ,0);
                $full=!$unlimited && $occ>=(int)$cap;
                $isCurrentRoom=$current && (int)$current['id']===(int)$rr['id'];
                $isClassRoom=(int)($rr['is_class_room']??0)===1;
              ?>
                <button type="submit" name="room_id" value="<?= (int)$rr['id'] ?>" class="student-room-card <?= $full?'is-full':'is-free' ?> <?= $isCurrentRoom?'is-current':'' ?> <?= $isClassRoom?'is-class-room':'' ?>" data-room-card data-full="<?= $full?'1':'0' ?>" data-room-name="<?= h((string)$rr['name']) ?>" <?= $full && !$isCurrentRoom?'disabled':'' ?> <?= $isCurrentRoom?'aria-current="location"':'' ?>>
                  <strong class="student-room-card__name"><?= h((string)$rr['name']) ?></strong>
                  <?php if($isCurrentRoom || $isClassRoom): ?><span class="student-room-card__tags"><?php if($isCurrentRoom): ?><span class="student-room-tag--current">✓ Dein Raum</span><?php endif; ?><?php if($isClassRoom): ?><span>Klassenraum</span><?php endif; ?></span><?php endif; ?>
                  <span class="student-room-card__availability <?= $full?'is-full':'' ?>"><?= $full?'Voll':($unlimited?'Frei · unbegrenzt':(int)$free.($free===1?' Platz frei':' Plätze frei')) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    </form>
  </section>

  <div class="student-room-exit">
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="room_id" value="0"><button type="submit" class="secondary">Unterrichtsende / Abmelden</button></form>
    <p class="small">Danach bist du nicht eingebucht und belegst keinen Raum.</p>
  </div>
  <details class="student-manual-select">
    <summary>Alternativ: Raum aus Liste wählen</summary>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label for="room-alternative">Verfügbare Räume</label>
      <select id="room-alternative" name="room_id" required>
        <option value="">– bitte wählen –</option>
        <option value="0" <?= $current?'':'selected' ?>>Unterrichtsende / nicht eingebucht</option>
        <?php foreach($rooms as $r):
          $cap=$r['capacity'];$occ=(int)$r['occupied'];$unlimited=($cap===null || $cap==='' || (int)$cap===0);
          $full=!$unlimited && $occ>=(int)$cap;$isCurrent=$current && (int)$current['id']===(int)$r['id'];
          $label=(string)$r['name'];
          if((int)($r['is_class_room']??0)===1)$label.=' – Klassenraum';
          if(!empty($r['group_name']))$label.=' – '.room_group_type_label((string)($r['group_type']??'')).': '.$r['group_name'];
          $label.=$unlimited?" ({$occ}/∞)":" ({$occ}/{$cap})";
          if($full)$label.=' – voll';
        ?>
          <option value="<?= (int)$r['id'] ?>" <?= $isCurrent?'selected':'' ?> <?= $full && !$isCurrent?'disabled':'' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Speichern</button>
    </form>
  </details>
  <?php if(!empty($_SESSION['via_kiosk'])): ?>
    <form method="post" action="<?= h(url('/logout.php')) ?>" class="small student-room-kiosk">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="to" value="kiosk">
      <span>Du bist über Kiosk eingeloggt: </span><button type="submit" class="link-button">Abmelden</button>
    </form>
  <?php endif; ?>
</div>
<script defer src="<?= h(url('/assets/student-rooms.js?v='.$roomScriptVersion)) ?>"></script>
