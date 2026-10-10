#!/usr/bin/env python3
"""
Live test suite for the Drinks module (Theke, Kostenübersicht, Getränkeverwaltung, PayPal).

Runs against a deployed instance (default: bookingtest) with a logged-in admin session and
compares the collected facts with tests/drinks/reference.json.

  EP3_SESSION=<ep3-bs-session cookie> python3 tests/drinks/drinks_suite.py            # read + auth tests
  ... drinks_suite.py --write              # + round-trip write tests (all changes are undone)
  ... drinks_suite.py --write --theke      # + Theke tests (logs in with Theken-IDs read via the admin API)
  ... drinks_suite.py --destructive        # + creates and closes a "SMOKETEST" Spieltag (stays in the DB)
  ... drinks_suite.py --paypal             # + PayPal history import / email name import (writes drinks_paypal)
  ... drinks_suite.py --paypal-imap        # + PayPal IMAP fetch (marks mails in the PayPal inbox as read!)
  ... drinks_suite.py --record             # store the facts of this run as the new reference
  ... drinks_suite.py -k deposit           # only tests whose name contains "deposit"

Exit code 0 if no test failed. Facts whose name starts with "~" are volatile: a difference is a
warning, not a failure. Theken-IDs (login credentials) are never printed or stored.
"""

import argparse
import http.cookiejar
import json
import os
import re
import sys
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request
import uuid

HERE = os.path.dirname(os.path.abspath(__file__))
REFERENCE_FILE = os.path.join(HERE, 'reference.json')

# Test data on bookingtest (see README.md)
ADMIN_UID = 1          # session user; thekenadmin and backend admin
PEER_UID = 4           # receiver of test money transfers
TEAM_UID = 962         # team account "D50" with open Spieltage
MARK = 'SMOKETEST'     # comment / label of everything the suite creates
CENT = 0.01


# --------------------------------------------------------------------------- HTTP client

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Response:
    def __init__(self, status, headers, body):
        self.status = status
        self.headers = headers
        self.body = body
        self.text = body.decode('utf-8', 'replace')

    @property
    def location(self):
        location = self.headers.get('Location') or ''
        return urllib.parse.urlparse(location).path

    def json(self):
        try:
            return json.loads(self.text)
        except ValueError:
            raise AssertionError('no JSON (HTTP %d): %s' % (self.status, self.text[:200]))


class Client:
    def __init__(self, base_url, session_cookie=None):
        self.base_url = base_url.rstrip('/')
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        self.host = urllib.parse.urlparse(self.base_url).hostname
        if session_cookie:
            self.jar.set_cookie(http.cookiejar.Cookie(
                0, 'ep3-bs-session', session_cookie, None, False, self.host, False, False,
                '/', True, False, None, False, None, None, {}))

    def request(self, method, path, query=None, form=None, json_body=None):
        url = self.base_url + '/' + path.lstrip('/')
        if query:
            url += '?' + urllib.parse.urlencode(query)
        data = None
        # The test system sends no mails for suite requests (User\\Service\\MailService)
        headers = {'User-Agent': 'drinks-suite', 'X-Requested-With': 'XMLHttpRequest', 'X-EP3-Suppress-Mail': '1'}
        if json_body is not None:
            data = json.dumps(json_body).encode()
            headers['Content-Type'] = 'application/json'
        elif form is not None:
            data = urllib.parse.urlencode(form, doseq=True).encode()
            headers['Content-Type'] = 'application/x-www-form-urlencoded'
        # Network errors: GET is retried (idempotent), POST never (it could book twice)
        attempts = 3 if method == 'GET' else 1
        for attempt in range(attempts):
            req = urllib.request.Request(url, data=data, headers=headers, method=method)
            try:
                with self.opener.open(req, timeout=60) as resp:
                    return Response(resp.status, resp.headers, resp.read())
            except urllib.error.HTTPError as e:
                return Response(e.code, e.headers, e.read())
            except (urllib.error.URLError, TimeoutError, ConnectionError):
                if attempt == attempts - 1:
                    raise
                time.sleep(2)

    def get(self, path, **query):
        return self.request('GET', path, query=query or None)

    def post(self, path, form=None, **kwargs):
        return self.request('POST', path, form=form if form is not None else {}, **kwargs)

    def get_json(self, path, expect_status=200, **query):
        resp = self.get(path, **query)
        expect(resp.status == expect_status, 'GET %s: HTTP %d, expected %d: %s' % (path, resp.status, expect_status, resp.text[:200]))
        return resp.json()

    def post_json(self, path, form=None, expect_status=200, **kwargs):
        resp = self.post(path, form, **kwargs)
        data = resp.json()
        expect(resp.status == expect_status, 'POST %s: HTTP %d, expected %d: %s' % (path, resp.status, expect_status, resp.text[:300]))
        return data


# --------------------------------------------------------------------------- test registry

TESTS = []


def test(group):
    def register(fn):
        TESTS.append((fn.__name__, group, fn))
        return fn
    return register


class Skip(Exception):
    pass


def expect(condition, message):
    if not condition:
        raise AssertionError(message)


def money(value):
    return round(float(value or 0), 2)


def shape(obj):
    """Sorted top-level keys of a JSON object (structure fact)."""
    return sorted(obj.keys()) if isinstance(obj, dict) else type(obj).__name__


def page_facts(resp):
    title = re.search(r'<title>\s*(.*?)\s*</title>', resp.text, re.S)
    expect('Exception details' not in resp.text and 'Oops ...' not in resp.text,
           'error page: ' + re.sub(r'<[^>]+>', ' ', resp.text[resp.text.find('Mitteilung'):][:300]))
    return {'status': resp.status, 'title': title.group(1).split('·')[0].strip() if title else None}


class Context:
    def __init__(self, args):
        self.args = args
        self.admin = Client(args.base_url, args.session)
        self.anon = Client(args.base_url)
        self._cache = {}

    def once(self, key, fn):
        if key not in self._cache:
            self._cache[key] = fn()
        return self._cache[key]

    # shared lookups -------------------------------------------------------
    def user_data(self, uid):
        return self.admin.get_json('user/get-user-deposits-data', uid=uid, showStorno=1)

    def balance(self, uid):
        return money(self.user_data(uid)['balance'])

    def team_stats(self, team_event_id=None):
        query = {'uid': TEAM_UID}
        if team_event_id:
            query['team_event_id'] = team_event_id
        return self.admin.get_json('user/get-user-team-event-stats-data', **query)

    def open_team_events(self):
        events = [e for e in self.team_stats()['events'] if not e['closed']]
        if not events:
            raise Skip('team %d has no open Spieltag' % TEAM_UID)
        return events

    def history_entries(self, uid):
        return [e for day in self.user_data(uid)['history'] for e in day['entries']]

    def newest_entry(self, uid, entry_type, desc_contains):
        entries = [e for e in self.history_entries(uid)
                   if e['type'] == entry_type and desc_contains in ((e.get('desc') or '') + (e.get('comment') or ''))]
        expect(entries, 'no %s entry "%s" for user %d' % (entry_type, desc_contains, uid))
        return max(entries, key=lambda e: e['id'])

    def toggle(self, entry_type, entry_id):
        data = self.admin.post_json('user/toggle-deposit-order-deleted', {'entry_id': entry_id, 'entry_type': entry_type})
        expect(data.get('success') is True, 'toggle %s %s failed: %s' % (entry_type, entry_id, data))

    def drinks(self):
        """[(id, name, price, category)] from the drink management page."""
        def load():
            html = self.admin.get('user/manage-drinks').text
            rows = re.findall(r'name="id" form="drink-form-(-?\d+)" value="-?\d+" />\s*<input type="text" name="name" form="drink-form-\1" value="([^"]*)".*?'
                              r'name="price" form="drink-form-\1"[^>]*value="([\d.]+)"(.*?)</select>', html, re.S)
            result = []
            for drink_id, name, price, select in rows:
                category = re.search(r'<option value="(\d*)" selected', select)
                result.append((int(drink_id), name, float(price), category.group(1) if category else ''))
            return result
        return self.once('drinks', load)


# --------------------------------------------------------------------------- auth: anonymous access

ADMIN_PAGES = [
    'user/drinks-admin', 'user/drinks-admin/paypal-settings', 'user/drinks-admin/spieltage-overview',
    'user/manage-drinks', 'user/deposits', 'user/balance-list', 'user/deposit-overview', 'user/drinks-summary',
]

JSON_ENDPOINTS = [
    # (method, path) without login: must not answer 200
    ('POST', 'user/add-drink-booking'), ('POST', 'user/toggle-deposit-order-deleted'),
    ('POST', 'user/set-user-drinks-settings'), ('GET', 'user/get-user-deposits-data'),
    ('GET', 'user/get-user-team-event-stats-data'), ('POST', 'user/update-user-history-team-event'),
    ('POST', 'user/create-team-event'), ('POST', 'user/paypal-fetch'), ('POST', 'user/paypal-history-import'),
    ('POST', 'user/emails-import'), ('POST', 'user/create-deposit-from-paypal'),
    ('POST', 'user/reassign-paypal-transaction'), ('POST', 'user/ignore-paypal-transaction'),
    ('POST', 'user/send-money'), ('GET', 'user/money-recipient-team-events'),
    ('POST', 'user/bookings/submit-order'), ('POST', 'user/bookings/drop-order'),
    ('GET', 'user/teamlead-team-stats'), ('POST', 'user/teamlead-team-members'),
    ('POST', 'user/teamlead-order-relevance'), ('GET', 'user/teamlead-extra-cost'),
    ('POST', 'user/teamlead-extra-cost'), ('POST', 'user/teamlead-update-extra-cost'),
    ('POST', 'user/teamlead-delete-extra-cost'), ('GET', 'user/teamlead-guest-donation'),
    ('POST', 'user/teamlead-guest-donation'), ('POST', 'user/teamlead-update-guest-donation'),
    ('POST', 'user/teamlead-delete-guest-donation'), ('POST', 'user/teamlead-close-team-event'),
    ('POST', 'user/drinks-admin/party-mode-save'),
    ('GET', 'user/simple-order/team-stats'), ('GET', 'user/simple-order/spieltag'),
    ('POST', 'user/simple-order/submit-order'), ('POST', 'user/simple-order/drop-order'),
    ('POST', 'user/simple-order/send-money'), ('POST', 'user/simple-order/team-members'),
    ('POST', 'user/simple-order/team-order-relevance'), ('POST', 'user/simple-order/team-extra-cost'),
    ('POST', 'user/simple-order/team-update-extra-cost'), ('POST', 'user/simple-order/team-delete-extra-cost'),
    ('POST', 'user/simple-order/team-guest-donation'), ('POST', 'user/simple-order/team-update-guest-donation'),
    ('POST', 'user/simple-order/team-delete-guest-donation'), ('POST', 'user/simple-order/close-team-event'),
]


@test('auth')
def auth_pages_redirect_anonymous(ctx):
    facts = {}
    for path in ['user/drinks'] + ADMIN_PAGES:
        resp = ctx.anon.get(path)
        # the PayPal settings page shares the JSON thekenadmin guard (401) with the PayPal endpoints
        expect(resp.status in (301, 302, 401, 403), '%s: anonymous got HTTP %d' % (path, resp.status))
        facts[path] = [resp.status, resp.location]
    return facts


@test('auth')
def auth_json_endpoints_reject_anonymous(ctx):
    facts = {}
    for method, path in JSON_ENDPOINTS:
        resp = ctx.anon.request(method, path, query={'team_event_id': 1, 'uid': 1, 'team_uid': TEAM_UID, 'receiver_user_id': TEAM_UID},
                                form={} if method == 'POST' else None)
        expect(resp.status != 200, '%s %s: anonymous got HTTP 200' % (method, path))
        error = ''
        try:
            body = resp.json()
            error = body.get('error') or body.get('error_message') or ''
        except AssertionError:
            pass
        facts[method + ' ' + path] = [resp.status, error]
    return facts


@test('auth')
def auth_theke_pages_anonymous(ctx):
    login = ctx.anon.get('user/simple-login')
    order = ctx.anon.get('user/simple-order')
    expect(login.status == 200, 'simple-login: HTTP %d' % login.status)
    expect(order.status == 302 and order.location.endswith('/user/simple-login'), 'simple-order should redirect to login')
    return {'login': page_facts(login), 'order_redirect': order.location}


# --------------------------------------------------------------------------- read: pages

@test('read')
def read_admin_pages(ctx):
    facts = {}
    markers = {
        'user/drinks-admin': ['party_mode_enabled', 'user/drinks-admin/party-mode-save'],
        'user/drinks-admin/paypal-settings': ['imap_host', 'paypal_client_id', 'minimum_account_balance'],
        'user/drinks-admin/spieltage-overview': ['createAdminTeamStatsModal', 'TEAM_STATS_URLS', 'spieltage-overview-item'],
        'user/manage-drinks': ['add_drink', 'edit_drink'],
        'user/deposits': ['DEPOSITS_PAGE', 'drinks/deposits-page.js?v=', 'TEAM_STATS_URLS', 'add_deposit', 'team-stats-modal.js?v='],
        'user/balance-list': [],
        'user/deposit-overview': [],
        'user/drinks-summary': [],
    }
    for path in ADMIN_PAGES:
        resp = ctx.admin.get(path)
        expect(resp.status == 200, '%s: HTTP %d (session expired?)' % (path, resp.status))
        page = page_facts(resp)
        missing = [m for m in markers[path] if m not in resp.text]
        expect(not missing, '%s: missing %s' % (path, missing))
        facts[path] = page
    return facts


@test('read')
def read_drinks_page(ctx):
    resp = ctx.admin.get('user/drinks')
    expect(resp.status == 200, 'HTTP %d' % resp.status)
    facts = page_facts(resp)
    for marker in ['user-info-bar', 'DRINKS_PAGE', 'drinks/money-send.js?v=', 'drinks/order-form.js?v=', 'drinks/simple-order-session.js?v=']:
        expect(marker in resp.text, 'missing ' + marker)
    expect('party-mode-block' not in resp.text, 'party mode block shown although party mode is not active')
    facts['~drink_buttons'] = resp.text.count('data-drink-id=')
    return facts


@test('read')
def read_page_scripts_load(ctx):
    """Every <script src> of the Drinks pages exists on the server (catches files missing in a deploy)."""
    facts = {}
    for path in ['user/drinks'] + ADMIN_PAGES:
        html = ctx.admin.get(path).text
        for src in sorted(set(re.findall(r'<script[^>]+src="([^"]+)"', html))):
            if src.startswith('http'):
                continue
            script = src.split('?')[0]
            resp = ctx.admin.get(urllib.parse.urlparse(ctx.admin.base_url).path.rstrip('/') + script if not script.startswith('/') else script.lstrip('/'))
            expect(resp.status == 200 and len(resp.body) > 0, '%s: %s -> HTTP %d' % (path, src, resp.status))
            facts[script] = resp.status
    return facts


@test('read')
def read_paypal_settings_masked(ctx):
    html = ctx.admin.get('user/drinks-admin/paypal-settings').text
    facts = {}
    for field in ['imap_password', 'paypal_client_secret']:
        value = re.search(r'name="%s"[^>]*value="([^"]*)"' % field, html)
        facts[field + '_present'] = bool(value)
    facts['saved_flag'] = 'saved' in ctx.admin.get('user/drinks-admin/paypal-settings', saved=1).text.lower()
    return facts


@test('read')
def read_drinks_summary_variants(ctx):
    facts = {}
    for group in ['date', 'week', 'month', 'year']:
        for quick in ['', 'cw', 'lw', 'cm', 'lm', 'cy', 'ly', 'sinceLastCheck', 'sinceMyLastCheck']:
            query = {'group': group}
            if quick:
                query['quick'] = quick
            resp = ctx.admin.get('user/drinks-summary', **query)
            expect(resp.status == 200, 'drinks-summary %s: HTTP %d' % (query, resp.status))
            page_facts(resp)
    for mode in ['count', 'amount']:
        resp = ctx.admin.get('user/drinks-summary', mode=mode, show_users=0, show_emptycols=1, **{'from': '2026-01-01', 'to': '2026-12-31'})
        facts['mode_' + mode] = page_facts(resp)['status']
    return facts


@test('read')
def read_drinks_summary_groupings_agree(ctx):
    """The grand total must not depend on the grouping (weekday amounts used to be off by 100x)."""
    def grand_total(group, mode):
        html = ctx.admin.get('user/drinks-summary', group=group, mode=mode, show_users=1, show_emptycols=0,
                             **{'from': '2026-01-01', 'to': '2026-12-31'}).text
        if mode == 'amount':
            body = re.search(r'<tbody>(.*?)</tbody>', html, re.S)
            values = re.findall(r'<td style="text-align: right;[^"]*">([^<]+)</td>', body.group(1) if body else '')
            badly_formatted = [v for v in values if not re.match(r'^-?\d{1,3}(\.\d{3})*,\d{2}$', v.strip())]
            expect(not badly_formatted, '%s: amount cells not formatted as money: %s' % (group, badly_formatted[:5]))
        foot = re.search(r'<tfoot>(.*?)</tfoot>', html, re.S)
        cells = re.findall(r'<th[^>]*>\s*(.*?)\s*</th>', foot.group(1), re.S) if foot else []
        return cells[-1] if cells else None
    facts = {}
    for mode in ['count', 'amount']:
        totals = {group: grand_total(group, mode) for group in ['date', 'week', 'month', 'year', 'weekday']}
        expect(len(set(totals.values())) == 1, '%s totals differ by grouping: %s' % (mode, totals))
        facts['~' + mode] = totals['date']
    return facts


@test('read')
def read_deposit_overview_variants(ctx):
    facts = {}
    for query in [{}, {'showTransfers': 1}, {'quick': 'l31d'}, {'from': '2026-01-01', 'to': '2026-12-31'}, {'from': 'kaputt'}]:
        resp = ctx.admin.get('user/deposit-overview', **query)
        expect(resp.status == 200, 'deposit-overview %s: HTTP %d' % (query, resp.status))
        page_facts(resp)
        facts[json.dumps(query)] = resp.status
    return facts


# --------------------------------------------------------------------------- read: JSON

@test('read')
def read_user_deposits_data(ctx):
    data = ctx.user_data(ADMIN_UID)
    expect(data['is_team'] is False, 'admin user must not be a team account')
    entries = [e for day in data['history'] for e in day['entries']]
    running = 0.0
    for entry in sorted(entries, key=lambda e: e['datetime']):
        if not entry['deleted']:
            running += float(entry['amount'])
    expect(money(running) == money(data['balance']), 'history sum %.2f != balance %.2f' % (running, data['balance']))
    return {
        'shape': shape(data),
        'entry_shape_deposit': sorted(next((e for e in entries if e['type'] == 'Einzahlung'), {}).keys()),
        'entry_shape_order': sorted(next((e for e in entries if e['type'] in ('Buchung', 'Storno')), {}).keys()),
        'order_email_option': data['order_email_option'],
        '~balance': money(data['balance']),
        '~active_history_entries': len([e for e in entries if not e['deleted']]),
    }


@test('read')
def read_team_deposits_data(ctx):
    data = ctx.user_data(TEAM_UID)
    expect(data['is_team'] is True, 'team account expected')
    ids = [e['id'] for e in data['team_events']]
    expect(data['current_teamevent_id'] in ids, 'current_teamevent_id not among team_events')
    entries = [e for day in data['history'] for e in day['entries']]
    labelled = [e for e in entries if e['teamevent_id']]
    expect(all(e['spieltag_label'] for e in labelled), 'entries with teamevent_id but without spieltag_label')
    return {
        'team_event_ids': ids,
        'team_event_shape': sorted(data['team_events'][0].keys()) if data['team_events'] else [],
        '~balance': money(data['balance']),
        '~team_event_balances': {str(e['id']): money(e['balance']) for e in data['team_events']},
    }


def check_stats_payload(data):
    expect(data.get('success') is True, 'success expected: %s' % data)
    ids = [e['id'] for e in data['events']]
    expect(ids == sorted(ids, reverse=True), 'events not newest first: %s' % ids)
    expect(data['spieltage'] == [e['label'] for e in data['events']], 'spieltage do not match events')
    expect(set(data['open_spieltage']) <= set(data['spieltage']), 'open_spieltage not a subset')
    expect(data['team_event_id'] in ids, 'selected team_event_id not among events')
    selected = next(e for e in data['events'] if e['id'] == data['team_event_id'])
    expect(selected['label'] == data['spieltag'], 'spieltag label does not match the selected event')
    expect(bool(selected['closed']) == bool(data['team_event_closed']), 'closed flag mismatch')
    total = sum(float(r['total_price']) for r in data['rows'])
    expect(money(total) == money(data['total_sum']), 'rows sum %.2f != total_sum %s' % (total, data['total_sum']))
    expect(data['members'] == data['active_members'], 'members != active_members')
    candidate_uids = {c['uid'] for c in data['member_candidates']}
    member_uids = {m['uid'] for m in data['active_members'] if m['is_member']}
    expect(not (candidate_uids & member_uids), 'active members offered as candidates')
    for row in data['rows']:
        expect(row['relevant_member_count'] == len(row['relevant_members']), 'relevant_member_count mismatch')


def stats_facts(data):
    return {
        'shape': shape(data),
        'event_ids': [e['id'] for e in data['events']],
        'event_shape': sorted(data['events'][0].keys()) if data['events'] else [],
        'team_alias': data['team_alias'],
        'spieltag': data['spieltag'],
        'can_close_team_event': data['can_close_team_event'],
        '~account_balance': money(data['account_balance']),
        '~total_sum': money(data['total_sum']),
        '~rows': [[r['row_type'], r['article'], r['quantity'], money(r['total_price'])] for r in data['rows']],
        '~active_members': sorted(m['uid'] for m in data['active_members'] if m['is_member']),
    }


@test('read')
def read_team_stats_admin(ctx):
    facts = {}
    for event in ctx.team_stats()['events']:
        data = ctx.team_stats(event['id'])
        check_stats_payload(data)
        expect(data['team_event_id'] == event['id'], 'requested event %d, got %d' % (event['id'], data['team_event_id']))
        expect(all(e['can_manage_members'] == 1 for e in data['events']), 'admins manage every Spieltag')
        facts[str(event['id'])] = stats_facts(data)
    return facts


@test('read')
def read_team_stats_teamlead_matches_admin(ctx):
    facts = {}
    for event in ctx.team_stats()['events']:
        admin = ctx.team_stats(event['id'])
        lead = ctx.admin.get_json('user/teamlead-team-stats', team_uid=TEAM_UID, team_uids=json.dumps([TEAM_UID]), team_event_id=event['id'])
        check_stats_payload(lead)
        admin.pop('team_uid', None)
        diff = sorted(k for k in set(admin) | set(lead) if admin.get(k) != lead.get(k))
        expect(not diff, 'teamlead and admin payload differ in %s' % diff)
        facts[str(event['id'])] = 'identical'
    # selection by label (labels are only unique per team)
    first = ctx.team_stats()['events'][-1]
    by_label = ctx.admin.get_json('user/teamlead-team-stats', team_uid=TEAM_UID, spieltag=first['label'])
    expect(by_label['team_event_id'] == first['id'], 'selection by label failed')
    return facts


@test('read')
def read_team_stats_member_view(ctx):
    # A plain member view only shows Spieltage the user takes part in, read-only
    data = ctx.admin.get('user/teamlead-team-stats', team_uid=ADMIN_UID, user_uid=ADMIN_UID, is_team_member=1)
    if data.status == 400:
        return {'status': 400, 'error': data.json().get('error')}
    payload = data.json()
    expect(all(e['can_manage_members'] == 0 for e in payload['events']), 'member view must be read-only')
    return {'status': data.status, '~events': [e['id'] for e in payload['events']]}


@test('read')
def read_money_recipient_team_events(ctx):
    team = ctx.admin.get_json('user/money-recipient-team-events', receiver_user_id=TEAM_UID)
    single = ctx.admin.get_json('user/money-recipient-team-events', receiver_user_id=PEER_UID)
    expect(team['is_team'] is True and single['is_team'] is False, 'is_team flags wrong')
    open_ids = [e['id'] for e in ctx.open_team_events()]
    expect([e['id'] for e in team['team_events']] == open_ids, 'recipient events != open Spieltage of the team')
    expect(all(sorted(e.keys()) == ['id', 'label'] for e in team['team_events']), 'unexpected fields in team_events')
    return {'team_events': team['team_events'], 'single': single}


@test('read')
def read_extra_costs_and_guest_donations_lists(ctx):
    facts = {}
    for event in ctx.team_stats()['events']:
        costs = ctx.admin.get_json('user/teamlead-extra-cost', team_event_id=event['id'])
        donations = ctx.admin.get_json('user/teamlead-guest-donation', team_event_id=event['id'])
        expect(costs['success'] and donations['success'], 'list endpoints failed')
        facts[str(event['id'])] = {'~extra_costs': len(costs['extra_costs']), '~guest_donations': len(donations['guest_donations'])}
    return facts


@test('read')
def read_invalid_parameters(ctx):
    a = ctx.admin
    cases = {
        'deposits data without uid': a.get('user/get-user-deposits-data'),
        'deposits data unknown uid': a.get('user/get-user-deposits-data', uid=99999999),
        'team stats of a non-team': a.get('user/get-user-team-event-stats-data', uid=ADMIN_UID),
        'teamlead stats without team_uid': a.get('user/teamlead-team-stats'),
        'teamlead stats of a non-team': a.get('user/teamlead-team-stats', team_uid=ADMIN_UID),
        'extra costs of unknown event': a.get('user/teamlead-extra-cost', team_event_id=99999999),
        'extra costs without event': a.get('user/teamlead-extra-cost'),
        'recipient without id': a.get('user/money-recipient-team-events'),
        'GET on POST endpoint': a.get('user/teamlead-team-members'),
        'GET send-money': a.get('user/send-money'),
        'GET add-drink-booking': a.get('user/add-drink-booking'),
        'booking without orders': a.post('user/add-drink-booking', json_body={'uid': ADMIN_UID, 'orders': []}),
        'booking for unknown user': a.post('user/add-drink-booking', json_body={'uid': 99999999, 'orders': [{'drink_id': 1, 'count': 1, 'price': CENT}]}),
        'toggle unknown entry': a.post('user/toggle-deposit-order-deleted', {'entry_id': 99999999, 'entry_type': 'deposit'}),
        'toggle invalid type': a.post('user/toggle-deposit-order-deleted', {'entry_id': 1, 'entry_type': 'x'}),
        'settings invalid alias': a.post('user/set-user-drinks-settings', {'uid': ADMIN_UID, 'drinks_alias': '<x>'}),
        'settings invalid email option': a.post('user/set-user-drinks-settings', {'uid': ADMIN_UID, 'order_email_option': 'x'}),
        'settings invalid teamlead email': a.post('user/set-user-drinks-settings', {'uid': TEAM_UID, 'teamlead_email': 'no-mail'}),
        'paypal deposit invalid ids': a.post('user/create-deposit-from-paypal', {'paypal_id': 0, 'user_id': 0}),
        'paypal deposit unknown': a.post('user/create-deposit-from-paypal', {'paypal_id': 99999999, 'user_id': ADMIN_UID}),
        'paypal reassign invalid': a.post('user/reassign-paypal-transaction', {'paypal_id': 0, 'user_id': 0}),
        'paypal ignore invalid': a.post('user/ignore-paypal-transaction', {'paypal_id': 0}),
        'history import without dates': a.post('user/paypal-history-import'),
        'emails import without dates': a.post('user/emails-import'),
        'money to oneself': a.post('user/send-money', {'receiver_user_id': ADMIN_UID, 'amount': '1'}),
        'money without amount': a.post('user/send-money', {'receiver_user_id': PEER_UID, 'amount': '0'}),
        'money to team without Spieltag': a.post('user/send-money', {'receiver_user_id': TEAM_UID, 'amount': '0.01'}),
        'create team event for non-team': a.post('user/create-team-event', {'uid': ADMIN_UID, 'label': MARK}),
        'create team event without label': a.post('user/create-team-event', {'uid': TEAM_UID, 'label': ''}),
        'history move invalid': a.post('user/update-user-history-team-event', {'uid': TEAM_UID, 'entry_id': 0, 'entry_type': 'order', 'team_event_id': 0}),
        'drop unknown order': a.post('user/bookings/drop-order', {'order_id': 99999999}),
    }
    facts = {}
    for name, resp in cases.items():
        expect(resp.status != 200, '%s: HTTP 200' % name)
        try:
            body = resp.json()
            facts[name] = [resp.status, body.get('error') or body.get('error_message')]
        except AssertionError:
            facts[name] = [resp.status, None]
    return facts


# --------------------------------------------------------------------------- write: round trips

@test('write')
def write_deposit_roundtrip(ctx):
    b0 = ctx.balance(ADMIN_UID)
    resp = ctx.admin.post('user/deposits', {'add_deposit': 1, 'deposit_user_id': ADMIN_UID, 'deposit_amount': CENT, 'deposit_comment': MARK})
    expect(resp.status == 302 and 'Deposit' in urllib.parse.unquote(resp.headers.get('Location', '')), 'deposit not added: HTTP %d' % resp.status)
    entry = ctx.newest_entry(ADMIN_UID, 'Einzahlung', MARK)
    b1 = ctx.balance(ADMIN_UID)
    ctx.toggle('deposit', entry['id'])
    b2 = ctx.balance(ADMIN_UID)
    expect(money(b1 - b0) == CENT, 'deposit changed balance by %.2f' % (b1 - b0))
    expect(b2 == b0, 'storno did not restore the balance (%.2f -> %.2f)' % (b0, b2))
    return {'delta_after_deposit': money(b1 - b0), 'delta_after_storno': money(b2 - b0), 'createdby_set': bool(entry['createdby'])}


@test('write')
def write_deposit_storno_restore_roundtrip(ctx):
    ctx.admin.post('user/deposits', {'add_deposit': 1, 'deposit_user_id': ADMIN_UID, 'deposit_amount': CENT, 'deposit_comment': MARK})
    entry = ctx.newest_entry(ADMIN_UID, 'Einzahlung', MARK)
    b1 = ctx.balance(ADMIN_UID)
    ctx.toggle('deposit', entry['id'])
    ctx.toggle('deposit', entry['id'])
    expect(ctx.balance(ADMIN_UID) == b1, 'restore did not bring the deposit back')
    ctx.toggle('deposit', entry['id'])
    return {'restored': True}


@test('write')
def write_admin_booking_roundtrip(ctx):
    b0 = ctx.balance(ADMIN_UID)
    data = ctx.admin.post_json('user/add-drink-booking', json_body={'uid': ADMIN_UID, 'orders': [{'drink_id': 1, 'count': 2, 'price': CENT, 'comment': MARK}]})
    expect(data == {'success': True}, 'booking failed: %s' % data)
    entry = ctx.newest_entry(ADMIN_UID, 'Buchung', MARK)
    b1 = ctx.balance(ADMIN_UID)
    ctx.toggle('order', entry['id'])
    b2 = ctx.balance(ADMIN_UID)
    expect(money(b0 - b1) == 2 * CENT, 'booking changed balance by %.2f' % (b1 - b0))
    expect(b2 == b0, 'storno did not restore the balance')
    return {'delta': money(b1 - b0), 'restored': b2 == b0, 'entry_quantity': entry['quantity'], 'entry_comment': entry['comment']}


@test('write')
def write_team_booking_roundtrip(ctx):
    event = ctx.open_team_events()[0]
    before = ctx.team_stats(event['id'])
    data = ctx.admin.post_json('user/add-drink-booking', json_body={'uid': TEAM_UID, 'team_event_id': event['id'], 'orders': [{'drink_id': 1, 'count': 1, 'price': CENT, 'comment': MARK}]})
    expect(data == {'success': True}, 'team booking failed: %s' % data)
    entry = ctx.newest_entry(TEAM_UID, 'Buchung', MARK)
    expect(entry['teamevent_id'] == event['id'], 'booking not on the selected Spieltag')
    after = ctx.team_stats(event['id'])
    ctx.toggle('order', entry['id'])
    restored = ctx.team_stats(event['id'])
    expect(money(before['total_sum'] - after['total_sum']) == CENT, 'Spieltag total changed by %.2f' % (after['total_sum'] - before['total_sum']))
    expect(money(restored['total_sum']) == money(before['total_sum']), 'storno did not restore the Spieltag total')
    return {'spieltag_delta': money(after['total_sum'] - before['total_sum']), 'restored': True}


@test('write')
def write_money_transfer_roundtrip(ctx):
    a0, p0 = ctx.balance(ADMIN_UID), ctx.balance(PEER_UID)
    key = str(uuid.uuid4())
    first = ctx.admin.post_json('user/send-money', {'receiver_user_id': PEER_UID, 'amount': '0,01', 'transfer_key': key})
    again = ctx.admin.post_json('user/send-money', {'receiver_user_id': PEER_UID, 'amount': '0,01', 'transfer_key': key})
    a1, p1 = ctx.balance(ADMIN_UID), ctx.balance(PEER_UID)
    expect(first.get('success') and again.get('already_processed'), 'idempotency key not honoured: %s / %s' % (first, again))
    expect(money(a0 - a1) == CENT and money(p1 - p0) == CENT, 'transfer amounts wrong (%.2f / %.2f)' % (a1 - a0, p1 - p0))
    deposit = ctx.newest_entry(PEER_UID, 'Einzahlung', 'Geld empfangen von')
    ctx.toggle('deposit', deposit['id'])
    a2, p2 = ctx.balance(ADMIN_UID), ctx.balance(PEER_UID)
    expect(a2 == a0 and p2 == p0, 'storno of one side did not cancel both sides')
    return {'sender_delta': money(a1 - a0), 'receiver_delta': money(p1 - p0), 'already_processed': again.get('already_processed'), 'both_restored': True}


@test('write')
def write_money_transfer_to_team_roundtrip(ctx):
    event = ctx.open_team_events()[0]
    before = ctx.team_stats(event['id'])
    data = ctx.admin.post_json('user/send-money', {'receiver_user_id': TEAM_UID, 'team_event_id': event['id'], 'amount': '0.01', 'transfer_key': str(uuid.uuid4())})
    expect(data.get('success'), 'transfer to team failed: %s' % data)
    deposit = ctx.newest_entry(TEAM_UID, 'Einzahlung', 'Geld empfangen von')
    expect(deposit['teamevent_id'] == event['id'], 'team deposit not on the selected Spieltag')
    after = ctx.team_stats(event['id'])
    payer = next((m for m in after['active_members'] if m['uid'] == ADMIN_UID), None)
    ctx.toggle('deposit', deposit['id'])
    restored = ctx.team_stats(event['id'])
    expect(money(restored['account_balance']) == money(before['account_balance']), 'team balance not restored')
    return {'team_balance_delta': money(after['account_balance'] - before['account_balance']), 'payer_listed': payer is not None}


@test('write')
def write_user_settings_roundtrip(ctx):
    data = ctx.user_data(ADMIN_UID)
    form = {'uid': ADMIN_UID, 'drinks_enabled': '1' if data['drinks_enabled'] else '0', 'drinks_alias': data['drinks_alias'] or '',
            'order_email_option': data['order_email_option'], 'teamlead_email': data['teamlead_email'], 'is_team': '0'}
    result = ctx.admin.post_json('user/set-user-drinks-settings', form)
    after = ctx.user_data(ADMIN_UID)
    keys = ['drinks_enabled', 'drinks_alias', 'order_email_option', 'teamlead_email', 'is_team']
    changed = [k for k in keys if data[k] != after[k]]
    expect(result == {'success': True} and not changed, 'settings changed by a no-op save: %s' % changed)
    # teamlead email normalisation on the team account (restored afterwards)
    team = ctx.user_data(TEAM_UID)
    ctx.admin.post_json('user/set-user-drinks-settings', {'uid': TEAM_UID, 'teamlead_email': ' A@Example.org; b@example.org,a@example.org '})
    normalised = ctx.user_data(TEAM_UID)['teamlead_email']
    ctx.admin.post_json('user/set-user-drinks-settings', {'uid': TEAM_UID, 'teamlead_email': team['teamlead_email']})
    expect(ctx.user_data(TEAM_UID)['teamlead_email'] == team['teamlead_email'], 'teamlead_email not restored')
    return {'noop_save_changed': changed, 'normalised_teamlead_email': normalised}


@test('write')
def write_party_mode_roundtrip(ctx):
    def read():
        html = ctx.admin.get('user/drinks-admin').text
        enabled = bool(re.search(r'name="party_mode_enabled" value="1"\s*checked', html))
        start = re.search(r'name="party_mode_start" value="([^"]*)"', html).group(1).replace('&#x3A;', ':')
        end = re.search(r'name="party_mode_end" value="([^"]*)"', html).group(1).replace('&#x3A;', ':')
        message = re.search(r'name="party_mode_message"[^>]*>(.*?)</textarea>', html, re.S).group(1)
        return enabled, start, end, message
    before = read()
    form = {'party_mode_start': before[1], 'party_mode_end': before[2], 'party_mode_message': before[3]}
    if before[0]:
        form['party_mode_enabled'] = '1'
    resp = ctx.admin.post('user/drinks-admin/party-mode-save', form)
    expect(resp.status == 302, 'party mode save: HTTP %d' % resp.status)
    after = read()
    expect(after == before, 'party mode changed by a no-op save: %s -> %s' % (before, after))
    theke = ctx.anon.get('user/simple-login').text
    return {'enabled': before[0], 'redirect': resp.location, '~theke_shows_party_mode': 'Party-Mode' in theke}


@test('write')
def write_manage_drinks_roundtrip(ctx):
    resp = ctx.admin.post('user/manage-drinks', {'add_drink': 1, 'name': MARK, 'price': '0.01', 'category': '0'})
    expect(resp.status == 302, 'add drink: HTTP %d' % resp.status)
    ctx._cache.pop('drinks', None)
    added = [d for d in ctx.drinks() if d[1] == MARK]
    expect(len(added) == 1, 'test drink not listed once: %s' % added)
    drink_id = added[0][0]
    ctx.admin.post('user/manage-drinks', {'edit_drink': 1, 'id': drink_id, 'name': MARK + ' 2', 'price': '0.02', 'category': '0', 'existing_image': ''})
    ctx._cache.pop('drinks', None)
    edited = [d for d in ctx.drinks() if d[0] == drink_id]
    ctx.admin.post('user/manage-drinks', {'delete_drink': 1, 'id': drink_id})
    ctx._cache.pop('drinks', None)
    expect(edited and edited[0][1:3] == (MARK + ' 2', 0.02), 'edit not stored: %s' % edited)
    expect(not [d for d in ctx.drinks() if d[0] == drink_id], 'test drink not deleted')
    return {'added_category': added[0][3], 'edited': list(edited[0][1:3]), 'deleted': True, '~drink_count': len(ctx.drinks())}


# --------------------------------------------------------------------------- write: Kostenübersicht

def teamlead_post(ctx, endpoint, form, expect_status=200):
    return ctx.admin.post_json('user/teamlead-' + endpoint, form, expect_status=expect_status)


def with_member(ctx, event_id, uid):
    """Add uid to the Spieltag if needed; returns a function that undoes it."""
    stats = ctx.team_stats(event_id)
    if any(m['uid'] == uid and m['is_member'] for m in stats['active_members']):
        return lambda: None
    teamlead_post(ctx, 'team-members', {'team_event_id': event_id, 'member_user_id': uid, 'operation': 'add'})
    return lambda: teamlead_post(ctx, 'team-members', {'team_event_id': event_id, 'member_user_id': uid, 'operation': 'remove'})


@test('write')
def write_team_members_roundtrip(ctx):
    event = ctx.open_team_events()[0]
    before = sorted(m['uid'] for m in ctx.team_stats(event['id'])['active_members'] if m['is_member'])
    candidate = next(c['uid'] for c in ctx.team_stats(event['id'])['member_candidates'] if c['uid'] not in before)
    teamlead_post(ctx, 'team-members', {'team_event_id': event['id'], 'member_user_id': candidate, 'operation': 'add'})
    added = sorted(m['uid'] for m in ctx.team_stats(event['id'])['active_members'] if m['is_member'])
    teamlead_post(ctx, 'team-members', {'team_event_id': event['id'], 'member_user_id': candidate, 'operation': 'remove'})
    after = sorted(m['uid'] for m in ctx.team_stats(event['id'])['active_members'] if m['is_member'])
    expect(candidate in added and after == before, 'member add/remove not reflected (%s / %s)' % (added, after))
    invalid = teamlead_post(ctx, 'team-members', {'team_event_id': event['id'], 'member_user_id': candidate, 'operation': 'x'}, expect_status=400)
    return {'added_then_removed': True, 'invalid_operation_error': invalid['error']}


@test('write')
def write_extra_cost_roundtrip(ctx):
    event = ctx.open_team_events()[0]
    undo_member = with_member(ctx, event['id'], ADMIN_UID)
    try:
        before = ctx.team_stats(event['id'])
        created = teamlead_post(ctx, 'extra-cost', {'team_event_id': event['id'], 'payer_user_id': ADMIN_UID, 'amount': CENT, 'comment': MARK, 'relevant_member_ids': str(ADMIN_UID)})
        cost = next(c for c in created['extra_costs'] if c['comment'] == MARK)
        expect(cost['relevant_member_ids'] == [ADMIN_UID], 'relevance not stored: %s' % cost['relevant_member_ids'])
        expect(money(before['total_sum'] - created['total_sum']) == CENT, 'extra cost not in total')
        updated = teamlead_post(ctx, 'update-extra-cost', {'extra_cost_id': cost['id'], 'team_event_id': event['id'], 'payer_user_id': ADMIN_UID, 'amount': 2 * CENT, 'comment': MARK})
        expect(money(before['total_sum'] - updated['total_sum']) == 2 * CENT, 'extra cost update not in total')
        listed = ctx.admin.get_json('user/teamlead-extra-cost', team_event_id=event['id'])['extra_costs']
        wrong_event = [e for e in ctx.team_stats()['events'] if e['id'] != event['id'] and not e['closed']]
        wrong = teamlead_post(ctx, 'delete-extra-cost', {'extra_cost_id': cost['id'], 'team_event_id': wrong_event[0]['id']}, expect_status=500) if wrong_event else {'error': None}
        teamlead_post(ctx, 'delete-extra-cost', {'extra_cost_id': cost['id'], 'team_event_id': event['id']})
        invalid = teamlead_post(ctx, 'extra-cost', {'team_event_id': event['id'], 'payer_user_id': ADMIN_UID, 'amount': 0}, expect_status=400)
        after = ctx.team_stats(event['id'])
    finally:
        undo_member()
    expect(money(after['total_sum']) == money(before['total_sum']), 'total not restored after delete')
    return {'listed': any(c['id'] == cost['id'] for c in listed), 'delete_other_spieltag_error': wrong['error'], 'zero_amount_error': invalid['error'],
            'row_article': next(r['article'] for r in created['rows'] if r['row_type'] == 'extra_cost' and MARK in r['article'])}


@test('write')
def write_guest_donation_roundtrip(ctx):
    event = ctx.open_team_events()[0]
    undo_member = with_member(ctx, event['id'], ADMIN_UID)
    try:
        created = teamlead_post(ctx, 'guest-donation', {'team_event_id': event['id'], 'receiver_user_id': ADMIN_UID, 'amount': CENT, 'comment': MARK})
        donation = next(d for d in created['guest_donations'] if d['comment'] == MARK)
        updated = teamlead_post(ctx, 'update-guest-donation', {'guest_donation_id': donation['id'], 'team_event_id': event['id'], 'receiver_user_id': ADMIN_UID, 'amount': 2 * CENT, 'comment': MARK})
        amount = next(d for d in updated['guest_donations'] if d['id'] == donation['id'])['amount']
        teamlead_post(ctx, 'delete-guest-donation', {'guest_donation_id': donation['id'], 'team_event_id': event['id']})
        not_member = teamlead_post(ctx, 'guest-donation', {'team_event_id': event['id'], 'receiver_user_id': PEER_UID, 'amount': CENT}, expect_status=400) \
            if not any(m['uid'] == PEER_UID and m['is_member'] for m in created['active_members']) else {'error': None}
        left = [d for d in ctx.admin.get_json('user/teamlead-guest-donation', team_event_id=event['id'])['guest_donations'] if d['id'] == donation['id']]
    finally:
        undo_member()
    expect(not left, 'guest donation not deleted')
    return {'due_amount': donation['due_amount'], 'updated_amount': amount, 'non_member_error': not_member['error']}


@test('write')
def write_order_relevance_roundtrip(ctx):
    event = next((e for e in ctx.open_team_events() if any(r['row_type'] == 'order' for r in ctx.team_stats(e['id'])['rows'])), None)
    if not event:
        raise Skip('no open Spieltag with orders')
    undo_member = with_member(ctx, event['id'], ADMIN_UID)
    try:
        stats = ctx.team_stats(event['id'])
        row = next(r for r in stats['rows'] if r['row_type'] == 'order')
        original = [m['uid'] for m in row['relevant_members']]
        form = {'team_event_id': event['id'], 'drink_id': row['drink_id'], 'unit_price': row['unit_price']}
        teamlead_post(ctx, 'order-relevance', dict(form, member_user_ids=str(ADMIN_UID)))
        changed = next(r for r in ctx.team_stats(event['id'])['rows'] if r['drink_id'] == row['drink_id'] and r['unit_price'] == row['unit_price'])
        all_members = len([m for m in stats['active_members'] if m['is_member']])
        restore = '' if len(original) == all_members else ','.join(map(str, original))
        teamlead_post(ctx, 'order-relevance', dict(form, member_user_ids=restore))
        restored = next(r for r in ctx.team_stats(event['id'])['rows'] if r['drink_id'] == row['drink_id'] and r['unit_price'] == row['unit_price'])
    finally:
        undo_member()
    expect([m['uid'] for m in changed['relevant_members']] == [ADMIN_UID], 'relevance not applied')
    expect(money(changed['share_per_member']) == money(changed['total_price']), 'single member must carry the whole row')
    return {'restored': sorted(m['uid'] for m in restored['relevant_members']) == sorted(original)}


@test('write')
def write_history_move_roundtrip(ctx):
    events = ctx.open_team_events()
    if len(events) < 2:
        raise Skip('needs two open Spieltage')
    entries = [e for e in ctx.history_entries(TEAM_UID) if e['teamevent_id'] == events[0]['id'] and not e['deleted']]
    if not entries:
        raise Skip('no entry on Spieltag %d' % events[0]['id'])
    entry = entries[0]
    entry_type = 'deposit' if entry['type'] == 'Einzahlung' else 'order'
    form = {'uid': TEAM_UID, 'entry_id': entry['id'], 'entry_type': entry_type}
    moved = ctx.admin.post_json('user/update-user-history-team-event', dict(form, team_event_id=events[1]['id']))
    now = next(e for e in ctx.history_entries(TEAM_UID) if e['id'] == entry['id'] and e['type'] == entry['type'])
    ctx.admin.post_json('user/update-user-history-team-event', dict(form, team_event_id=events[0]['id']))
    back = next(e for e in ctx.history_entries(TEAM_UID) if e['id'] == entry['id'] and e['type'] == entry['type'])
    expect(now['teamevent_id'] == events[1]['id'] and back['teamevent_id'] == events[0]['id'], 'entry not moved and back')
    closed = [e for e in ctx.team_stats()['events'] if e['closed']]
    refused = ctx.admin.post_json('user/update-user-history-team-event', dict(form, team_event_id=closed[0]['id']), expect_status=400) if closed else {'error': None}
    return {'moved_label': moved['team_event_label'] == events[1]['label'], 'closed_target_error': refused['error']}


@test('write')
def write_closed_spieltag_is_read_only(ctx):
    closed = [e for e in ctx.team_stats()['events'] if e['closed']]
    if not closed:
        raise Skip('no closed Spieltag')
    event_id = closed[0]['id']
    facts = {}
    for endpoint, form in [
        ('team-members', {'member_user_id': ADMIN_UID, 'operation': 'add'}),
        ('extra-cost', {'payer_user_id': ADMIN_UID, 'amount': CENT}),
        ('guest-donation', {'receiver_user_id': ADMIN_UID, 'amount': CENT}),
        ('order-relevance', {'drink_id': 1, 'unit_price': CENT}),
    ]:
        facts[endpoint] = teamlead_post(ctx, endpoint, dict(form, team_event_id=event_id), expect_status=400)['error']
    closing_again = teamlead_post(ctx, 'close-team-event', {'team_event_id': event_id})
    expect(closing_again.get('already_closed') is True, 'closing a closed Spieltag should report already_closed')
    facts['close_again'] = closing_again['already_closed']
    return facts


# --------------------------------------------------------------------------- destructive

@test('destructive')
def destructive_create_and_close_spieltag(ctx):
    label = '%s %s' % (MARK, time.strftime('%Y-%m-%d %H:%M:%S'))
    created = ctx.admin.post_json('user/create-team-event', {'uid': TEAM_UID, 'label': label})
    again = ctx.admin.post_json('user/create-team-event', {'uid': TEAM_UID, 'label': label})
    expect(created['team_event_id'] == again['team_event_id'], 'create-team-event is not idempotent')
    event_id = created['team_event_id']
    stats = ctx.team_stats(event_id)
    expect(stats['spieltag'] == label and not stats['team_event_closed'], 'new Spieltag not selectable')
    closed = teamlead_post(ctx, 'close-team-event', {'team_event_id': event_id})
    after = ctx.team_stats(event_id)
    reopen = ctx.admin.post_json('user/create-team-event', {'uid': TEAM_UID, 'label': label}, expect_status=400)
    expect(after['team_event_closed'] and closed['already_closed'] is False, 'Spieltag not closed')
    return {'closed': True, 'settlement': closed['settlement'], 'create_closed_error': reopen['error']}


# --------------------------------------------------------------------------- Theke

class Theke:
    """Theke (SimpleLogin) session in its own cookie jar; the Theken-ID is read via the admin API."""

    def __init__(self, ctx, uid):
        alias = ctx.user_data(uid)['drinks_alias']
        if not alias:
            raise Skip('user %d has no Theken-ID' % uid)
        self.client = Client(ctx.args.base_url)
        resp = self.client.post('user/simple-login', {'alias': alias})
        expect(resp.status == 302 and resp.location.endswith('/user/simple-order'), 'Theke login failed for user %d (HTTP %d)' % (uid, resp.status))
        self.uid = uid


@test('theke')
def theke_login_errors(ctx):
    c = Client(ctx.args.base_url)
    unknown = c.post('user/simple-login', {'alias': 'no-such-id-' + uuid.uuid4().hex[:8]})
    empty = c.post('user/simple-login', {'alias': ''})
    stale = c.post('user/simple-login', {'quick_login_token': 'deadbeef'})
    facts = {}
    for name, resp, text in [('unknown', unknown, 'Theken-ID nicht gefunden.'), ('empty', empty, 'Bitte geben Sie eine Theken-ID ein.'),
                             ('stale_quick_login', stale, 'Schnell-Login ist abgelaufen')]:
        expect(resp.status == 200 and text in resp.text, '%s: expected "%s"' % (name, text))
        facts[name] = text
    return facts


@test('theke')
def theke_order_page_and_spieltag(ctx):
    user = Theke(ctx, ADMIN_UID)
    page = user.client.get('user/simple-order')
    expect(page.status == 200, 'order page: HTTP %d' % page.status)
    facts = page_facts(page)
    facts['simple_mode'] = 'window.SIMPLE_ORDER_MODE = true' in page.text
    facts['non_team_spieltag'] = user.client.get('user/simple-order/spieltag').status
    stats = user.client.get('user/simple-order/team-stats')
    facts['member_team_stats'] = stats.status
    team = Theke(ctx, TEAM_UID)
    spieltag = team.client.get_json('user/simple-order/spieltag')
    expect(spieltag['current_spieltag'] in spieltag['open_spieltage'], 'current Spieltag not among the open ones')
    open_labels = [e['label'] for e in ctx.open_team_events()]
    expect(spieltag['open_spieltage'] == open_labels, 'Theke Spieltage %s != open Spieltage %s' % (spieltag['open_spieltage'], open_labels))
    facts['team_page'] = page_facts(team.client.get('user/simple-order'))
    return facts


@test('theke')
def theke_new_spieltag_name_must_be_unique(ctx):
    """'Neuen Spieltag anlegen' with an existing name is rejected; the existing Spieltag keeps its members."""
    team = Theke(ctx, TEAM_UID)
    label = team.client.get_json('user/simple-order/spieltag')['current_spieltag']
    if not label:
        raise Skip('team %d has no open Spieltag' % TEAM_UID)
    event = next(e for e in ctx.open_team_events() if e['label'] == label)
    before = ctx.admin.get_json('user/get-user-team-event-stats-data', uid=TEAM_UID, team_event_id=event['id'])
    data = team.client.post_json('user/simple-order/spieltag', {'spieltag': '__new__', 'new_spieltag': label, 'member_user_ids': '', 'is_medenspiel': 0}, expect_status=409)
    after = ctx.admin.get_json('user/get-user-team-event-stats-data', uid=TEAM_UID, team_event_id=event['id'])
    members = lambda stats: sorted(m['uid'] for m in stats['members'] if m.get('is_member'))
    expect(members(before) == members(after), 'members changed: %s -> %s' % (members(before), members(after)))
    return {'status': 409, 'error_mentions_name': label in data.get('error', ''), 'members_kept': True}


@test('theke')
def theke_team_stats_match_admin(ctx):
    team = Theke(ctx, TEAM_UID)
    facts = {}
    for event in ctx.team_stats()['events']:
        theke = team.client.get_json('user/simple-order/team-stats', spieltag=event['id'])
        check_stats_payload(theke)
        admin = ctx.team_stats(event['id'])
        admin.pop('team_uid', None)
        diff = sorted(k for k in set(admin) | set(theke) if admin.get(k) != theke.get(k))
        expect(not diff, 'Theke and admin payload differ in %s' % diff)
        facts[str(event['id'])] = 'identical'
    return facts


@test('theke')
def theke_order_and_drop_roundtrip(ctx):
    user = Theke(ctx, ADMIN_UID)
    drink = min((d for d in ctx.drinks() if d[0] > 1 and d[3] not in ('', '0')), key=lambda d: d[2])
    b0 = ctx.balance(ADMIN_UID)
    ordered = user.client.post_json('user/simple-order/submit-order', {'drink_counts[%d]' % drink[0]: 1, 'keep_logged_in': 0})
    order = max((e for e in ctx.history_entries(ADMIN_UID) if e['type'] == 'Buchung' and e['drink_id'] == drink[0]), key=lambda e: e['id'])
    dropped = user.client.post_json('user/simple-order/drop-order', {'order_id': order['id']})
    again = user.client.post_json('user/simple-order/drop-order', {'order_id': order['id']}, expect_status=409)
    b2 = ctx.balance(ADMIN_UID)
    expect(money(b0 - ordered['balance']) == money(drink[2]), 'order did not cost the drink price')
    expect(b2 == b0, 'drop did not restore the balance')
    empty = user.client.post_json('user/simple-order/submit-order', {'keep_logged_in': 0})
    return {'ordered': ordered['success'], 'dropped': dropped == {'success': True}, 'drop_twice': again['error_message'], 'empty_order_balance': money(empty['balance']) == b0}


@test('theke')
def theke_team_writes_roundtrip(ctx):
    team = Theke(ctx, TEAM_UID)
    spieltag = team.client.get_json('user/simple-order/spieltag')
    event = next(e for e in ctx.open_team_events() if e['label'] == spieltag['current_spieltag'])
    post = lambda endpoint, form, status=200: team.client.post_json('user/simple-order/' + endpoint, dict(form, team_event_id=event['id']), expect_status=status)
    before = ctx.team_stats(event['id'])
    added = not any(m['uid'] == ADMIN_UID and m['is_member'] for m in before['active_members'])
    if added:
        post('team-members', {'member_user_id': ADMIN_UID, 'operation': 'add'})
    try:
        cost = post('team-extra-cost', {'payer_user_id': ADMIN_UID, 'amount': CENT, 'comment': MARK})
        cost_id = next(c['id'] for c in cost['extra_costs'] if c['comment'] == MARK)
        post('team-delete-extra-cost', {'extra_cost_id': cost_id})
        donation = post('team-guest-donation', {'receiver_user_id': ADMIN_UID, 'amount': CENT, 'comment': MARK})
        donation_id = next(d['id'] for d in donation['guest_donations'] if d['comment'] == MARK)
        post('team-delete-guest-donation', {'guest_donation_id': donation_id})
        foreign = [e for e in ctx.admin.get_json('user/get-user-team-event-stats-data', uid=854)['events']][:1]
        foreign_error = team.client.post_json('user/simple-order/team-extra-cost', {'team_event_id': foreign[0]['id'], 'payer_user_id': ADMIN_UID, 'amount': CENT}, expect_status=404)['error'] if foreign else None
    finally:
        if added:
            post('team-members', {'member_user_id': ADMIN_UID, 'operation': 'remove'})
    after = ctx.team_stats(event['id'])
    expect(money(after['total_sum']) == money(before['total_sum']), 'Spieltag total not restored')
    expect(sorted(m['uid'] for m in after['active_members'] if m['is_member']) == sorted(m['uid'] for m in before['active_members'] if m['is_member']), 'members not restored')
    return {'restored': True, 'foreign_team_spieltag_error': foreign_error}


@test('theke')
def theke_send_money_requires_password(ctx):
    user = Theke(ctx, ADMIN_UID)
    missing = user.client.post_json('user/simple-order/send-money', {'receiver_user_id': PEER_UID, 'amount': '0.01'}, expect_status=400)
    wrong = user.client.post_json('user/simple-order/send-money', {'receiver_user_id': PEER_UID, 'amount': '0.01', 'password': 'wrong-' + uuid.uuid4().hex}, expect_status=403)
    recipients = user.client.get_json('user/money-recipient-team-events', receiver_user_id=TEAM_UID)
    return {'missing_password': missing['error'], 'wrong_password': wrong['error'], 'theke_recipient_events': len(recipients['team_events']) > 0}


# --------------------------------------------------------------------------- PayPal

def recent_range(days=3):
    end = time.time()
    return {'from': time.strftime('%Y-%m-%d', time.localtime(end - days * 86400)), 'to': time.strftime('%Y-%m-%d', time.localtime(end))}


@test('paypal')
def paypal_history_import(ctx):
    resp = ctx.admin.request('POST', 'user/paypal-history-import', query=recent_range(), form={})
    data = resp.json()
    expect(resp.status == 200 and data.get('success'), 'history import failed: %s' % data.get('error'))
    return {'keys': shape(data), '~import_errors': len(data['import_result']['errors']), '~needs_review': len(data['auto_result']['needs_review'])}


@test('paypal')
def paypal_emails_import(ctx):
    resp = ctx.admin.request('POST', 'user/emails-import', query=recent_range(), form={})
    data = resp.json()
    expect(resp.status == 200 and data.get('success'), 'emails import failed: %s' % data.get('error'))
    return {'result_keys': shape(data['result']), '~folders': data['result']['folders']}


@test('paypal-imap')
def paypal_imap_fetch(ctx):
    data = ctx.admin.post_json('user/paypal-fetch')
    expect(data.get('success'), 'fetch failed: %s' % data)
    return {'keys': shape(data), '~imported': data['result']['imported']}


# --------------------------------------------------------------------------- cleanup

def cleanup_leftovers(ctx):
    """Undo SMOKETEST data an aborted run left behind. Returns a description of what was undone."""
    undone = []
    for uid in (ADMIN_UID, PEER_UID, TEAM_UID):
        for entry in ctx.history_entries(uid):
            if not entry['deleted'] and MARK in (str(entry.get('desc') or '') + str(entry.get('comment') or '')):
                entry_type = 'deposit' if entry['type'] == 'Einzahlung' else 'order'
                ctx.toggle(entry_type, entry['id'])
                undone.append('%s %d of user %d (%s EUR)' % (entry_type, entry['id'], uid, entry['amount']))
    for event in ctx.team_stats()['events']:
        for cost in ctx.admin.get_json('user/teamlead-extra-cost', team_event_id=event['id'])['extra_costs']:
            if cost['comment'] == MARK:
                ctx.admin.post_json('user/teamlead-delete-extra-cost', {'extra_cost_id': cost['id'], 'team_event_id': event['id']})
                undone.append('extra cost %d' % cost['id'])
        for donation in ctx.admin.get_json('user/teamlead-guest-donation', team_event_id=event['id'])['guest_donations']:
            if donation['comment'] == MARK:
                ctx.admin.post_json('user/teamlead-delete-guest-donation', {'guest_donation_id': donation['id'], 'team_event_id': event['id']})
                undone.append('guest donation %d' % donation['id'])
    ctx._cache.pop('drinks', None)
    for drink_id, name, _price, _category in ctx.drinks():
        if name.startswith(MARK):
            ctx.admin.post('user/manage-drinks', {'delete_drink': 1, 'id': drink_id})
            undone.append('drink %d' % drink_id)
    ctx._cache.pop('drinks', None)
    return undone


# --------------------------------------------------------------------------- runner

def compare(name, facts, reference, path=''):
    """Yields (level, message) for differences against the reference facts."""
    if isinstance(reference, dict) and isinstance(facts, dict):
        for key in sorted(set(reference) | set(facts)):
            sub = path + '/' + key if path else key
            if key not in facts:
                yield ('FAIL' if not key.startswith('~') else 'WARN', '%s: %s missing' % (name, sub))
            elif key not in reference:
                yield ('NEW', '%s: %s = %s' % (name, sub, json.dumps(facts[key], ensure_ascii=False)[:120]))
            else:
                for item in compare(name, facts[key], reference[key], sub):
                    level = 'WARN' if '~' in sub and item[0] == 'FAIL' else item[0]
                    yield (level, item[1])
    elif facts != reference:
        yield ('FAIL', '%s: %s %s -> %s' % (name, path, json.dumps(reference, ensure_ascii=False)[:150], json.dumps(facts, ensure_ascii=False)[:150]))


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--base-url', default=os.environ.get('EP3_BASE_URL', 'https://bookingtest.stc-butzbach.de'))
    parser.add_argument('--session', default=os.environ.get('EP3_SESSION'), help='ep3-bs-session cookie of a logged-in admin (or EP3_SESSION)')
    parser.add_argument('--write', action='store_true', help='round-trip write tests (changes are undone)')
    parser.add_argument('--theke', action='store_true', help='Theke tests (need --write: orders and drops)')
    parser.add_argument('--destructive', action='store_true', help='creates and closes a SMOKETEST Spieltag')
    parser.add_argument('--paypal', action='store_true', help='PayPal history import and email name import')
    parser.add_argument('--paypal-imap', action='store_true', help='PayPal IMAP fetch (marks mails as read)')
    parser.add_argument('--record', action='store_true', help='store facts as the new reference')
    parser.add_argument('-k', dest='filter', help='only tests whose name contains this text')
    args = parser.parse_args()
    if not args.session:
        parser.error('admin session cookie required (--session or EP3_SESSION)')

    groups = {'auth', 'read'}
    if args.write:
        groups.add('write')
    if args.theke:
        groups.add('theke')
    if args.destructive:
        groups.add('destructive')
    if args.paypal:
        groups.add('paypal')
    if args.paypal_imap:
        groups.add('paypal-imap')

    reference = {}
    if os.path.exists(REFERENCE_FILE):
        with open(REFERENCE_FILE, encoding='utf-8') as f:
            reference = json.load(f)

    ctx = Context(args)
    writes = bool(groups & {'write', 'theke', 'destructive'})
    if writes:
        for item in cleanup_leftovers(ctx):
            print('CLEAN leftover of an earlier run undone: ' + item)
    results = {}
    counts = {'PASS': 0, 'FAIL': 0, 'SKIP': 0, 'WARN': 0, 'NEW': 0}
    for name, group, fn in TESTS:
        if group not in groups or (args.filter and args.filter not in name):
            continue
        started = time.time()
        try:
            facts = fn(ctx)
        except Skip as e:
            counts['SKIP'] += 1
            print('SKIP  %-48s %s' % (name, e))
            continue
        except AssertionError as e:
            counts['FAIL'] += 1
            print('FAIL  %-48s %s' % (name, e))
            continue
        except Exception:
            counts['FAIL'] += 1
            print('ERROR %-48s %s' % (name, traceback.format_exc().strip().splitlines()[-1]))
            continue
        results[name] = facts
        problems = [] if args.record or name not in reference else list(compare(name, facts, reference[name]))
        failed = [m for level, m in problems if level == 'FAIL']
        status = 'FAIL' if failed else 'PASS'
        counts[status] += 1
        note = '' if name in reference or args.record else '(no reference yet)'
        print('%-5s %-48s %5.1fs %s' % (status, name, time.time() - started, note))
        for level, message in problems:
            if level != 'NEW' or args.filter:
                print('      %-4s %s' % (level, message))
            counts[level] = counts.get(level, 0) + (level != 'FAIL')

    if writes:
        leftovers = cleanup_leftovers(ctx)
        for item in leftovers:
            print('FAIL  test data was left behind and has been undone: ' + item)
        counts['FAIL'] += len(leftovers)

    if args.record:
        reference.update(results)
        with open(REFERENCE_FILE, 'w', encoding='utf-8') as f:
            json.dump(reference, f, ensure_ascii=False, indent=2, sort_keys=True)
            f.write('\n')
        print('reference updated: %s (%d tests)' % (REFERENCE_FILE, len(results)))
    print('\n%(PASS)d passed, %(FAIL)d failed, %(SKIP)d skipped, %(WARN)d warnings' % counts)
    return 1 if counts['FAIL'] else 0


if __name__ == '__main__':
    sys.exit(main())
