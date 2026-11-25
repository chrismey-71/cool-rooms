const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../www/assets/class-roster.js'), 'utf8');
const input = {value: '', addEventListener(type, fn) { this[type] = fn; }};
const matchCount = {textContent: ''}, empty = {hidden: true}, present = {}, unbooked = {}, search = {hidden: true};
const forms = [];
function row(id, name, active, booked) {
  const number = {textContent: '0'}, cell = {replaceChildren(child) { this.child = child; }};
  const form = {addEventListener(type, fn) { this[type] = fn; }}; forms.push(form);
  return {dataset: {studentId: String(id), present: String(active), booked: String(booked)}, hidden: false,
    querySelector(selector) { return {'.class-roster__name': {textContent: name}, '.class-roster__number': number, '.room-cell': cell}[selector]; }, number, cell};
}
const rows = [row(1, 'Müller, Anna', 1, 1), row(2, 'Bauer, Ben', 1, 0), row(3, 'Claus, Cleo', 0, 0)];
const summary = {focus() { this.focused = true; }};
const actions = {open: false, contains(node) { return node === this; }, querySelector() { return summary; }};
const root = {dataset: {class: '1HLS', streamUrl: '/stream.php?scope=class&class=1HLS', nextChangeMs: '0', returnKey: 'roster-test'},
  querySelectorAll(selector) { return selector === 'tr[data-student-id]' ? rows : forms; },
  querySelector(selector) { return {'[data-roster-query]': input, '[data-search-count]': matchCount, '[data-search-empty]': empty,
    '[data-roster-search]': search, '[data-present-count]': present, '[data-unbooked-count]': unbooked, '.class-roster__actions': actions}[selector]; }};
const docEvents = {}, winEvents = {}, storage = new Map(); let instance;
class Stream { constructor() { instance = this; this.listeners = {}; } addEventListener(type, fn) { this.listeners[type] = fn; } close() { this.closed = true; } }
const document = {querySelector() { return root; }, addEventListener(type, fn) { docEvents[type] = fn; }, createElement() { return {}; }};
const window = {EventSource: Stream, scrollY: 500, addEventListener(type, fn) { winEvents[type] = fn; }, scrollTo() {}};
let reloads = 0;
vm.runInNewContext(source, {document, window, EventSource: Stream, sessionStorage: {getItem: key => storage.get(key), removeItem: key => storage.delete(key), setItem: (key, value) => storage.set(key, value)},
  location: {reload() { reloads++; }}, setTimeout() { throw Error('Unexpected rapid reload timer'); }, clearTimeout() {}, requestAnimationFrame(fn) { fn(); }, console});
assert.equal(search.hidden, false);
input.value = 'muller'; input.input(); assert.deepEqual(rows.map(r => r.hidden), [false, true, true]); assert.equal(matchCount.textContent, '1 von 3');
input.value = 'Anna Muller'; input.input(); assert.deepEqual(rows.map(r => r.hidden), [false, true, true]);
input.value = 'nobody'; input.input(); assert.equal(empty.hidden, false);
input.value = ''; input.input(); assert.deepEqual(rows.map(r => r.number.textContent), ['1','2','3']); assert.equal(empty.hidden, true);
instance.listeners.assignment({data: JSON.stringify({class_name: '1HLS', student_id: 2, room_name: '<img onerror=alert(1)>', updated_at: 'now'})});
assert.equal(rows[1].cell.child.textContent, '<img onerror=alert(1)>'); assert.equal(present.textContent, '2'); assert.equal(unbooked.textContent, '0');
instance.listeners.assignment({data: JSON.stringify({class_name: 'OTHER', student_id: 2, room_name: 'wrong'})}); assert.equal(rows[1].cell.child.textContent, '<img onerror=alert(1)>');
instance.listeners.assignment({data: '{broken'});
instance.listeners.assignment({data: JSON.stringify({class_name: '1HLS', student_id: 2, room_name: null})}); assert.equal(unbooked.textContent, '1'); assert.equal(rows[1].cell.child.textContent, 'Kein Raum');
forms[0].submit(); assert.equal(JSON.parse(storage.get('roster-test')).scrollY, 500);
actions.open = true; docEvents.keydown({key: 'Escape'}); assert.equal(actions.open, false); assert.equal(summary.focused, true);
actions.open = true; docEvents.click({target: {}}); assert.equal(actions.open, false);
instance.listeners.class_groups_changed(); assert.equal(reloads, 1); winEvents.pagehide(); assert.equal(instance.closed, true);
console.log('PASS: name search, accent matching, numbering, empty state, safe live updates, counters, return state, disclosure and stream cleanup');
