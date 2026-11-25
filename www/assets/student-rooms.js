(() => {
  const root = document.querySelector('[data-student-rooms]');
  if (!root) return;
  const query = root.querySelector('[data-room-query]');
  const area = root.querySelector('[data-room-area]');
  const free = root.querySelector('[data-room-free]');
  const count = root.querySelector('[data-room-count]');
  const clear = root.querySelector('[data-room-clear]');
  const empty = root.querySelector('[data-room-empty]');
  const normalize = value => value.toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/ß/g, 'ss');
  const groups = Array.from(root.querySelectorAll('[data-room-group]'), group => ({
    element: group,
    count: group.querySelector('[data-group-count]'),
    cards: Array.from(group.querySelectorAll('[data-room-card]'), card => ({
      element: card,
      search: normalize(card.dataset.roomName + ' ' + group.dataset.roomAreaName)
    }))
  }));
  const total = groups.reduce((sum, group) => sum + group.cards.length, 0);
  function filter() {
    const terms = normalize(query.value).trim().split(/\s+/).filter(Boolean);
    let visible = 0;
    groups.forEach(group => {
      let matches = 0;
      group.cards.forEach(card => {
        const show = (area.value === '' || area.value === group.element.dataset.roomGroup)
          && (!free.checked || card.element.dataset.full !== '1')
          && terms.every(term => card.search.includes(term));
        card.element.hidden = !show;
        if (show) matches++;
      });
      group.element.hidden = matches === 0;
      group.count.textContent = matches + (matches === 1 ? ' Raum' : ' Räume');
      visible += matches;
    });
    count.textContent = visible + ' von ' + total + (total === 1 ? ' Raum' : ' Räumen');
    empty.hidden = visible !== 0 || total === 0;
    clear.hidden = !terms.length && area.value === '' && !free.checked;
  }
  query.addEventListener('input', filter);
  area.addEventListener('change', filter);
  free.addEventListener('change', filter);
  clear.addEventListener('click', () => {
    query.value = ''; area.value = ''; free.checked = false;
    filter(); query.focus();
  });
  root.querySelector('[data-room-tools]').hidden = false;
  filter();
})();
