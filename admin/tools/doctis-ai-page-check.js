#!/usr/bin/env node
// Doctis — AI Assistant page check (headless, jsdom).
//
// Logs in, loads ai_assist_page.php with its real scripts in jsdom, and checks
// that the tab named by the URL hash is shown, that no script fails, and that
// Send and Ctrl+Enter post a chat request (with meeting_id / series_of when
// the page was opened for a meeting). Requests to ai_assist_api.php are
// recorded, not sent, so no AI call is made.
//
// Requires the jsdom package (Debian: apt install node-jsdom).
//
// usage: doctis-ai-page-check.js BASE_URL USER PASSWORD [QUERY_AND_HASH] [help|meeting]
//   e.g. doctis-ai-page-check.js http://10.0.0.94/doctis administrator root '?meeting_id=45#tab-meeting' meeting
// Exit code 0 when every check passes.
'use strict';
const { JSDOM, VirtualConsole, ResourceLoader } = require('jsdom');

const [base, user, password, queryHash = '', tab = 'help'] = process.argv.slice(2);
if( !base || !user ) {
  console.error('usage: doctis-ai-page-check.js BASE_URL USER PASSWORD [QUERY_AND_HASH] [help|meeting]');
  process.exit(2);
}
const root = base.replace(/\/$/, '') + '/';

const jar = {};
function storeCookies(res) {
  for( const c of res.headers.getSetCookie ? res.headers.getSetCookie() : [] ) {
    const [pair] = c.split(';');
    const i = pair.indexOf('=');
    jar[pair.slice(0, i).trim()] = pair.slice(i + 1).trim();
  }
}
const cookieHeader = () => Object.entries(jar).map(([k, v]) => k + '=' + v).join('; ');
async function post(page, fields) {
  const res = await fetch(root + page, { method: 'POST', redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookieHeader() },
    body: new URLSearchParams(fields) });
  storeCookies(res);
}
async function get(url) {
  for( let hops = 0; hops < 5; hops++ ) {
    const res = await fetch(url, { redirect: 'manual', headers: { Cookie: cookieHeader() } });
    storeCookies(res);
    if( res.status >= 300 && res.status < 400 ) { url = new URL(res.headers.get('location'), url).href; continue; }
    return res.text();
  }
  throw new Error('too many redirects');
}

class Loader extends ResourceLoader {
  fetch(u, opts) { opts.headers = Object.assign({}, opts.headers, { Cookie: cookieHeader() }); return super.fetch(u, opts); }
}

(async () => {
  await post('login_password_page.php', { username: user, return: 'index.php' });
  await post('login.php', { username: user, password: password || '', return: 'index.php', secure_session: '0' });
  const pageUrl = root + 'ai_assist_page.php' + queryHash;
  const html = await get(pageUrl.split('#')[0]);
  if( !html.includes('id="ai-tab-nav"') ) { console.error('FAIL: not the AI Assistant page (login or access problem)'); process.exit(1); }

  const errors = [];
  const vc = new VirtualConsole();
  vc.on('jsdomError', e => errors.push(String(e.detail || e.message || e)));
  const sent = [];
  const dom = new JSDOM(html, { url: pageUrl, runScripts: 'dangerously', resources: new Loader(), virtualConsole: vc,
    beforeParse(window) {
      window.XMLHttpRequest = class {
        open() {} setRequestHeader() {} abort() {}
        send(body) {
          sent.push(JSON.parse(body));
          this.responseText = JSON.stringify({ history: null, error: null, reply: 'stub', usage: {} });
          setTimeout(() => this.onload && this.onload.call(this), 0);
        }
      };
    } });

  await new Promise(r => dom.window.addEventListener('load', () => setTimeout(r, 200)));
  const d = dom.window.document;
  const params = new URLSearchParams((queryHash.split('#')[0] || '').replace(/^\?/, ''));
  const wantTab = (queryHash.split('#')[1] || 'tab-help');
  const expectExtra = { meeting_id: params.get('meeting_id'), series_of: params.get('series_of') };
  const results = [];
  const check = (name, ok, detail) => results.push({ name, ok, detail });

  check('no script errors', errors.length === 0, errors.join(' | '));
  check('tab #' + wantTab + ' shown', d.getElementById(wantTab) && d.getElementById(wantTab).classList.contains('active'),
    [...d.querySelectorAll('.tab-content > .tab-pane.active')].map(p => p.id).join(','));

  const input = d.getElementById(tab === 'meeting' ? 'ai-meeting-input' : 'ai-chat-input');
  const btn = d.getElementById(tab === 'meeting' ? 'ai-meeting-send-btn' : 'ai-send-btn');
  const before = sent.length;
  input.value = 'Check message one';
  btn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
  await new Promise(r => setTimeout(r, 100));
  input.value = 'Check message two';
  input.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true }));
  await new Promise(r => setTimeout(r, 100));
  const chats = sent.slice(before).filter(b => b.action === 'chat');
  const matches = b => b.mode === tab
    && String(b.meeting_id ?? '') === String(expectExtra.meeting_id ?? '')
    && String(b.series_of ?? '') === String(expectExtra.series_of ?? '');
  check('Send posts a ' + tab + ' chat', chats[0] && matches(chats[0]) && chats[0].history.slice(-1)[0].content === 'Check message one',
    JSON.stringify(chats[0] && { mode: chats[0].mode, meeting_id: chats[0].meeting_id, series_of: chats[0].series_of }));
  check('Ctrl+Enter posts a ' + tab + ' chat', chats[1] && matches(chats[1]) && chats[1].history.slice(-1)[0].content === 'Check message two',
    JSON.stringify(chats[1] && { mode: chats[1].mode, turns: chats[1].history.length }));

  let failed = 0;
  for( const r of results ) {
    console.log((r.ok ? 'PASS ' : 'FAIL ') + r.name + (r.ok ? '' : '  [' + r.detail + ']'));
    if( !r.ok ) failed++;
  }
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error('FAIL:', e.message); process.exit(1); });
