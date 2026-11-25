const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../www/assets/student-rooms.js'), 'utf8');
const control = props => Object.assign({addEventListener(type, fn) { this[type] = fn; }}, props);
const query = control({value: '', focus() { this.focused = true; }}), area = control({value: ''}), free = control({checked: false});
const count = {}, empty = {}, clear = control({}), tools = {};
const card = (roomName, full = '0') => ({dataset: {roomName, full}, hidden: false});
const cards = [card('Raum A'), card('Raum B', '1'), card('Büro & Beratung'), card('Schulhof')];
function group(id, name, children) {
  const groupCount = {};
  return {dataset: {roomGroup: id, roomAreaName: name}, hidden: false, groupCount,
    querySelector() { return groupCount; }, querySelectorAll() { return children; }};
}
const groups = [group('0', 'Etage EG', cards.slice(0, 2)), group('1', 'Etage OG', cards.slice(2, 3)), group('2', 'Ort Außen', cards.slice(3))];
const selectors = {'[data-room-query]': query, '[data-room-area]': area, '[data-room-free]': free, '[data-room-count]': count,
  '[data-room-clear]': clear, '[data-room-empty]': empty, '[data-room-tools]': tools};
const root = {querySelector(s) { return selectors[s]; }, querySelectorAll() { return groups; }};
vm.runInNewContext(source, {document: {querySelector() { return root; }}});
assert.equal(tools.hidden, false);assert.equal(count.textContent, '4 von 4 Räumen');assert.equal(clear.hidden, true);
query.value = 'raum b';query.input();assert.deepEqual(cards.map(c => c.hidden), [true, false, true, true]);
assert.equal(groups[1].hidden, true);assert.equal(groups[0].groupCount.textContent, '1 Raum');
query.value = 'buro';query.input();assert.equal(cards[2].hidden, false);assert.equal(count.textContent, '1 von 4 Räumen');
query.value = 'aussen';query.input();assert.equal(cards[3].hidden, false);
query.value = 'eg raum';query.input();assert.deepEqual(cards.map(c => c.hidden), [false, false, true, true]);
free.checked = true;free.change();assert.deepEqual(cards.map(c => c.hidden), [false, true, true, true]);
area.value = '1';area.change();assert.equal(empty.hidden, false);assert.equal(count.textContent, '0 von 4 Räumen');
clear.click();assert.equal(query.value, '');assert.equal(free.checked, false);assert.equal(area.value, '');assert.equal(query.focused, true);
area.value = '2';area.change();assert.deepEqual(cards.map(c => c.hidden), [true, true, true, false]);
query.value = '<img onerror=alert(1)>';query.input();assert.equal(empty.hidden, false);
console.log('PASS: room search, multiple terms, area search, umlauts, area/free combinations, counts, empty state and reset');
