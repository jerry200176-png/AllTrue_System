import copy
import json
import subprocess
import unittest
from types import SimpleNamespace
from unittest import mock
import writeback_cursor as cursor
from writeback_cursor import COMMENT, ISSUE, MARKER, REPOSITORY, SCHEMA, CommentStore, digest, reserve, succeed, validate


class CursorTest(unittest.TestCase):
    def setUp(self):
        self.requests = [dict(request_id=f'request-{i}', public_reply=f'body-{i}') for i in range(9)]
        self.state = dict(schema=SCHEMA, prefix=0, prefix_hash=digest([]), snapshot_sha=None,
                          last_request_id=None, recent_outcomes=[], unknown=[])

    def test_five_then_remaining_and_older_run_cannot_rewind(self):
        for _ in range(5):
            self.state = reserve(self.state, self.requests, 'a' * 40)
            self.state = succeed(self.state, self.requests, self.state['last_request_id'])
        self.assertEqual(5, validate(self.state, self.requests)['prefix'])
        with self.assertRaises(ValueError):
            validate(self.state, self.requests[:3])
        for _ in range(4):
            self.state = reserve(self.state, self.requests, 'b' * 40)
        self.assertEqual(9, self.state['prefix'])
        with self.assertRaises(ValueError):
            reserve(self.state, self.requests, 'b' * 40)

    def test_partial_timeout_does_not_replay_unknown_id(self):
        reserved = reserve(self.state, self.requests, 'a' * 40)
        restarted = json.loads(json.dumps(reserved))
        next_state = reserve(restarted, self.requests, 'a' * 40)
        self.assertEqual(['request-0', 'request-1'], [r['id'] for r in next_state['unknown']])
        self.assertEqual(2, next_state['prefix'])
        finished = succeed(next_state, self.requests, 'request-1')
        self.assertEqual(['request-0'], [r['id'] for r in finished['unknown']])

    def test_changed_request_and_invalid_cursor_fail_closed(self):
        state = reserve(self.state, self.requests, 'a' * 40)
        changed = copy.deepcopy(self.requests)
        changed[0]['public_reply'] = 'edited'
        for bad, requests in [(None, self.requests), ({}, self.requests), (state, changed)]:
            with self.assertRaises(ValueError):
                validate(bad, requests)
        with self.assertRaises(ValueError):
            succeed(state, self.requests, 'request-8')

    def test_store_constant_two_calls_bounded_timeout_and_confirmed_body(self):
        calls = []
        def runner(args, **kwargs):
            calls.append((args, kwargs))
            body = json.loads(kwargs['input'])['body'] if kwargs['input'] else MARKER + '\n' + json.dumps(self.state)
            return SimpleNamespace(stdout=json.dumps(dict(id=COMMENT,
                issue_url=f'https://api.github.com/repos/{REPOSITORY}/issues/{ISSUE}', body=body)))
        store = CommentStore(runner)
        self.assertEqual(self.state, store.read())
        store.write(reserve(self.state, self.requests, 'a' * 40))
        self.assertEqual(2, len(calls))
        self.assertTrue(all(k['timeout'] == 15 and k['check'] for _, k in calls))
        self.assertTrue(all('/actions/runs' not in str(a) for a, _ in calls))

    def test_plan_frozen_main_content_and_out_of_order_prefix(self):
        import yaml
        entries=self.requests[:6]
        state=self.state
        for _ in range(5):
            state=reserve(state, entries, 'a'*40)
        def load(text):
            return yaml.safe_load(text)['requests']
        def dump(rows):
            return yaml.safe_dump({'requests':rows})
        current=[entries]
        def git(args, **kwargs):
            if args[1]=='show':
                return SimpleNamespace(stdout=dump(current[0] if args[2].startswith('b'*40) else entries), returncode=0)
            return SimpleNamespace(stdout='', returncode=1 if args[-2:] == ['a'*40,'b'*40] and len(current[0])<len(entries) else 0)
        store=mock.Mock();store.read.return_value=state
        with mock.patch.object(cursor,'CommentStore',return_value=store), mock.patch.object(cursor.subprocess,'run',side_effect=git):
            self.assertEqual(1,len(cursor.plan_snapshot(dump(entries),'b'*40,load,lambda e,r:e,'repo')[3]))
            current[0]=entries[:3]
            self.assertEqual([],cursor.plan_snapshot(dump(entries[:3]),'b'*40,load,lambda e,r:e,'repo')[3])
            for target in (None, {}, {'snapshot_sha':'invalid'}):
                store.read.return_value=target
                with self.assertRaises(ValueError):
                    cursor.plan_snapshot(dump(entries[:3]),'b'*40,load,lambda e,r:e,'repo')
            store.read.return_value=state
            with self.assertRaises(ValueError):
                cursor.plan_snapshot(dump(entries),'b'*40,load,lambda e,r:e,'repo')
            current[0]=entries
            with self.assertRaises(ValueError):
                cursor.plan_snapshot(dump(entries),'b'*40,load,lambda e,r:e,'repo',6)
            with mock.patch.object(cursor.subprocess,'run',side_effect=subprocess.CalledProcessError(1,['git'])):
                with self.assertRaises(subprocess.CalledProcessError):
                    cursor.plan_snapshot(dump(entries),'b'*40,load,lambda e,r:e,'repo')

    def test_old_snapshot_cannot_resurrect_a_deleted_unreserved_tail(self):
        import yaml
        old=self.requests[:2]; newer=old[:1]
        state=reserve(self.state,newer,'b'*40)
        store=mock.Mock();store.read.return_value=state
        def git(args, **kwargs):
            if args[1]=='show':
                return SimpleNamespace(stdout=yaml.safe_dump({'requests':old if args[2].startswith('a'*40) else newer}),returncode=0)
            return SimpleNamespace(stdout='',returncode=1 if args[-2:]==['b'*40,'a'*40] else 0)
        with mock.patch.object(cursor,'CommentStore',return_value=store),mock.patch.object(cursor.subprocess,'run',side_effect=git):
            with self.assertRaisesRegex(ValueError,'reintroduces'):
                cursor.plan_snapshot(yaml.safe_dump({'requests':old}),'a'*40,lambda text:yaml.safe_load(text)['requests'],lambda e,r:e,'repo')

    def test_missing_malformed_or_wrong_comment_never_bootstraps(self):
        for response in ['{}', 'null', '[]', 'not-json', json.dumps(dict(id=COMMENT, issue_url='wrong', body=MARKER))]:
            store = CommentStore(lambda *a, **k: SimpleNamespace(stdout=response))
            with self.assertRaises((ValueError, json.JSONDecodeError)):
                store.read()
        def timeout(*args, **kwargs):
            raise subprocess.TimeoutExpired(args[0], 15)
        with self.assertRaises(subprocess.TimeoutExpired):
            CommentStore(timeout).write(self.state)


if __name__ == '__main__':
    unittest.main()
