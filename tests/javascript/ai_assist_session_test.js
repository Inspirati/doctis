// Minimal DOM/XHR contract test; run with node, no browser packages required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const elements = new Map();
const requests = [];

class Element {
	constructor() { this.children = []; this.style = {}; this.value = ''; this.disabled = false; this.events = {}; }
	appendChild(child) { child.parentNode = this; this.children.push(child); if (child.id) elements.set(child.id, child); }
	removeChild(child) { this.children = this.children.filter(item => item !== child); elements.delete(child.id); }
	addEventListener(name, callback) { this.events[name] = callback; }
	focus() {}
	set innerHTML(value) { this.html = value; this.children = []; }
	get innerHTML() { return this.html; }
}
class Request {
	constructor() { requests.push(this); }
	open() {}
	setRequestHeader() {}
	send(body) { this.body = JSON.parse(body); }
	respond(data) { this.responseText = JSON.stringify(data); this.onload(); }
}
const window = { location: { hash: '' } };
const document = {
	readyState: 'loading', addEventListener() {}, querySelectorAll() { return []; },
	createElement() { return new Element(); }, getElementById(id) { return elements.get(id) || null; },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js/ai_assist.js'), 'utf8'),
	{ window, document, XMLHttpRequest: Request, setTimeout() {} });
const input = new Element();
const send = new Element();
const clear = new Element();
const messages = new Element();
const chat = window.AiAssist.createChatSession({
	mode: 'meeting', input, sendBtn: send, clearBtn: clear, messages,
	extra: { meeting_id: 6 }, statusTxt: new Element(), compactWelcome: 'New chat',
});
assert.equal(send.disabled, true, 'Wait for the saved conversation before sending');
requests.at(-1).respond({ conversation_id: 41, history: [] });
input.value = 'Plan a meeting';
chat.send();
assert.equal(requests.at(-1).body.conversation_id, 41);
requests.at(-1).respond({ conversation_id: 41, request_id: 7, error: 'Provider failed' });
assert.equal(input.value, 'Plan a meeting', 'Restore failed input for retry');
chat.send();
assert.equal(requests.at(-1).body.history.length, 1, 'Do not append a phantom failed turn');
requests.at(-1).respond({ conversation_id: 41, reply: 'Ready', error: null });
const visibleCount = messages.children.length;
chat.clear();
assert.equal(requests.at(-1).body.conversation_id, 41);
assert.equal(requests.at(-1).body.meeting_id, 6, 'Archive the requested meeting context');
assert.equal(messages.children.length, visibleCount, 'Keep visible history until archive is confirmed');
requests.at(-1).respond({ error: 'Archive failed' });
assert.equal(messages.children.length, visibleCount);
chat.clear();
requests.at(-1).respond({ conversation_id: 42, history: [], error: null });
input.value = 'Fresh question';
chat.send();
assert.equal(requests.at(-1).body.conversation_id, 42);
assert.equal(requests.at(-1).body.history.length, 1);
console.log('AI browser session contract: load, failed retry, safe archive and new conversation ID PASS');
