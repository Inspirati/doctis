#!/usr/bin/env python3
"""Drive the Doctis AI Meeting Assistant over HTTP, as the browser does.

Chat (Meeting tab):
    doctis-meeting-chat.py USER PASSWORD [--clear] [--meeting-id N] MESSAGE [MESSAGE ...]

    Logs in, loads (or with --clear, discards) the user's stored meeting
    conversation, then sends each MESSAGE as one turn and prints each reply,
    token usage, and any saved_document result. --meeting-id N opens the
    conversation for writing the minutes of meeting N, like the Write Minutes
    button on My Meetings.

Approve minutes (chair):
    doctis-meeting-chat.py USER PASSWORD --approve N

    Submits Approve Minutes for meeting N from My Meetings, including the
    server-side confirmation step.

Environment: DOCTIS_URL (default http://localhost/doctis/).
Each chat turn calls the Anthropic API (billed to the configured key) and an
agenda or minutes confirmation writes documents and git commits.
"""
import html, http.cookiejar, json, os, re, sys, urllib.parse, urllib.request

BASE = os.environ.get('DOCTIS_URL', 'http://localhost/doctis/').rstrip('/') + '/'


def login(user, password):
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    for page, fields in (
            ('login_password_page.php', {'username': user, 'return': 'index.php'}),
            ('login.php', {'username': user, 'password': password,
                           'return': 'index.php', 'secure_session': '0'})):
        opener.open(BASE + page, urllib.parse.urlencode(fields).encode()).read()
    if not any('STRING_COOKIE' in c.name.upper() for c in jar):
        sys.exit('login failed for ' + user)
    return opener


def chat(opener, user, messages, meeting_id, clear):
    def api(body):
        if meeting_id:
            body['meeting_id'] = meeting_id
        req = urllib.request.Request(
            BASE + 'ai_assist_api.php', json.dumps(body).encode(),
            {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'})
        raw = opener.open(req, timeout=180).read().decode()
        try:
            return json.loads(raw)
        except ValueError:
            sys.exit('non-JSON response:\n' + raw[:3000])

    if clear:
        api({'action': 'clear', 'mode': 'meeting'})
        history = []
    else:
        history = api({'action': 'load', 'mode': 'meeting'}).get('history') or []
        print('[loaded %d prior turns]' % len(history))

    for text in messages:
        history.append({'role': 'user', 'content': text})
        print('\n=== USER (%s) ===\n%s' % (user, text))
        data = api({'action': 'chat', 'mode': 'meeting', 'history': history})
        if data.get('error'):
            print('=== ERROR ===\n' + data['error'])
            return 1
        print('=== ASSISTANT ===\n' + data['reply'])
        print('[usage %s]' % data.get('usage'))
        history.append({'role': 'assistant', 'content': data['reply']})
        if data.get('saved_document'):
            print('=== SAVED_DOCUMENT ===\n' + json.dumps(data['saved_document'], indent=2))
    return 0


def hidden_fields(form_html):
    pairs = []
    for tag in re.findall(r'<input[^>]*type="hidden"[^>]*>', form_html):
        name = re.search(r'name="([^"]*)"', tag)
        value = re.search(r'value="([^"]*)"', tag)
        if name:
            pairs.append((name.group(1), html.unescape(value.group(1) if value else '')))
    return pairs


def approve(opener, meeting_id):
    page = opener.open(BASE + 'my_view_meeting_page.php').read().decode()
    form = next((f for f in re.findall(r'<form[^>]*meeting_minutes_approve\.php[^>]*>(.*?)</form>', page, re.S)
                 if 'value="%d"' % meeting_id in f), None)
    if form is None:
        sys.exit('no Approve Minutes action for meeting %d (not chair, or nothing awaiting approval)' % meeting_id)
    confirm = opener.open(BASE + 'meeting_minutes_approve.php',
                          urllib.parse.urlencode(hidden_fields(form)).encode()).read().decode()
    form = next(f for f in re.findall(r'<form[^>]*>(.*?)</form>', confirm, re.S) if '_confirmed' in f)
    result = opener.open(BASE + 'meeting_minutes_approve.php',
                         urllib.parse.urlencode(hidden_fields(form)).encode())
    print('approved meeting %d -> %s' % (meeting_id, result.geturl()))
    return 0


def main():
    if len(sys.argv) < 4:
        sys.exit(__doc__)
    user, password, args = sys.argv[1], sys.argv[2], sys.argv[3:]
    meeting_id, clear, approve_id = 0, False, 0
    while args and args[0].startswith('--'):
        if args[0] == '--meeting-id':
            meeting_id, args = int(args[1]), args[2:]
        elif args[0] == '--approve':
            approve_id, args = int(args[1]), args[2:]
        elif args[0] == '--clear':
            clear, args = True, args[1:]
        else:
            sys.exit('unknown option ' + args[0])
    opener = login(user, password)
    if approve_id:
        return approve(opener, approve_id)
    return chat(opener, user, args, meeting_id, clear)


sys.exit(main())
