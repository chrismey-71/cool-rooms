(() => {
  const root = document.querySelector('[data-class-roster]');
  if (!root) return;
  const rows = Array.from(root.querySelectorAll('tr[data-student-id]'));
  const query = root.querySelector('[data-roster-query]');
  const searchCount = root.querySelector('[data-search-count]');
  const empty = root.querySelector('[data-search-empty]');
  const normalize = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('de');
  const names = new Map(rows.map(row => [row, normalize(row.querySelector('.class-roster__name').textContent)]));

  function search() {
    const value = normalize(query.value.trim());
    const terms = value.split(/\s+/);
    let visible = 0;
    rows.forEach(row => {
      row.hidden = !terms.every(term => names.get(row).includes(term));
      if (!row.hidden) row.querySelector('.class-roster__number').textContent = String(++visible);
    });
    searchCount.textContent = value ? `${visible} von ${rows.length}` : '';
    empty.hidden = !value || visible > 0;
  }
  root.querySelector('[data-roster-search]').hidden = false;
  query.addEventListener('input', search);

  // Restore once after a presence POST, scoped to this teacher and lesson/group.
  const returnKey = root.dataset.returnKey;
  try {
    const saved = sessionStorage.getItem(returnKey);
    sessionStorage.removeItem(returnKey);
    const state = saved ? JSON.parse(saved) : null;
    if (state && Date.now() - state.at < 60000 && typeof state.query === 'string') {
      query.value = state.query;
      search();
      if (Number.isFinite(state.scrollY) && state.scrollY >= 0) {
        requestAnimationFrame(() => window.scrollTo(0, state.scrollY));
      }
    }
  } catch { /* Storage can be unavailable; the list works without it. */ }
  root.querySelectorAll('.class-roster__attendance form').forEach(form => {
    form.addEventListener('submit', () => {
      try {
        sessionStorage.setItem(returnKey, JSON.stringify({query: query.value, scrollY: window.scrollY, at: Date.now()}));
      } catch { /* Keep the normal server-side form submission. */ }
    });
  });

  function totals() {
    root.querySelector('[data-present-count]').textContent = String(rows.filter(row => row.dataset.present === '1').length);
    root.querySelector('[data-unbooked-count]').textContent = String(rows.filter(row => row.dataset.present === '1' && row.dataset.booked !== '1').length);
  }

  // The native disclosure remains usable without JavaScript.
  const actions = root.querySelector('.class-roster__actions');
  document.addEventListener('click', event => {
    if (actions.open && !actions.contains(event.target)) actions.open = false;
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && actions.open) {
      actions.open = false;
      actions.querySelector('summary').focus();
    }
  });

  let stream = null;
  if (window.EventSource) {
    stream = new EventSource(root.dataset.streamUrl);
    stream.addEventListener('assignment', event => {
      let data;
      try { data = JSON.parse(event.data); } catch { return; }
      if (!data || data.class_name !== root.dataset.class) return;
      const id = Number.parseInt(String(data.student_id), 10);
      if (!Number.isInteger(id) || id < 1) return;
      const row = rows.find(item => Number(item.dataset.studentId) === id);
      if (!row) return;
      const cell = row.querySelector('.room-cell');
      const room = document.createElement('span');
      room.className = 'class-roster__room' + (data.room_name ? '' : ' class-roster__room--empty');
      room.textContent = data.room_name || 'Kein Raum';
      cell.replaceChildren(room);
      cell.title = 'Stand: ' + (data.updated_at || 'noch keine Raumbuchung');
      row.dataset.booked = data.room_name ? '1' : '0';
      totals();
    });
    ['teacher_class_home_room_booking', 'teacher_student_active_change', 'reset', 'class_groups_changed'].forEach(type => {
      stream.addEventListener(type, () => location.reload());
    });
    stream.onerror = () => {}; // EventSource reconnects without reloading the page.
  }
  const delay = Number(root.dataset.nextChangeMs);
  const lessonTimer = delay >= 5000 ? setTimeout(() => location.reload(), delay) : null;
  window.addEventListener('pagehide', () => {
    if (stream) stream.close();
    if (lessonTimer !== null) clearTimeout(lessonTimer);
  }, {once: true});
})();
