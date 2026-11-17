import http.client
import json
import threading
import unittest
from http.server import HTTPServer
from unittest.mock import patch
from relay import RelayHandler, perform

class RelayTests(unittest.TestCase):
    def setUp(self):
        self.calls = []
        self.server = HTTPServer(('127.0.0.1', 0), RelayHandler)
        self.server.token = 'fictional-private-relay-token-for-testing'
        def radio(body):
            self.calls.append(body)
            return {'ok': True, 'data': []}
        self.server.perform = radio
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown(); self.server.server_close(); self.thread.join()

    def request(self, path='/bluetooth', body=None, token=None, method='POST'):
        connection = http.client.HTTPConnection('127.0.0.1', self.server.server_port, timeout=2)
        headers = {'Content-Type': 'application/json'}
        if token: headers['Authorization'] = 'Bearer ' + token
        connection.request(method, path, body=json.dumps(body) if body is not None else None, headers=headers)
        response = connection.getresponse(); status = response.status; data = response.read(); connection.close()
        return status, json.loads(data)

    def test_unauthorized_requests_never_reach_radio(self):
        for token in [None, 'incorrect-token']:
            self.assertEqual(self.request(body={'action': 'discover'}, token=token)[0], 401)
        self.assertEqual(self.calls, [])

    def test_authenticated_relay_accepts_only_fixed_protocol_fields(self):
        token = self.server.token
        self.assertEqual(self.request(body={'action': 'discover', 'script': 'untrusted.py'}, token=token), (200, {'ok': True, 'data': []}))
        self.assertEqual(self.calls, [{'action': 'discover'}])
        self.assertEqual(self.request(body={'action': 'write'}, token=token)[0], 400)
        self.assertEqual(self.request(path='/other', body={'action': 'discover'}, token=token)[0], 404)
        self.assertEqual(self.request(path='/health', token=token, method='GET')[0], 200)
        self.assertEqual(len(self.calls), 1)

    def test_large_inputs_are_rejected_without_radio_access(self):
        self.assertEqual(self.request(body={'action': 'discover', 'name': 'x' * 9000}, token=self.server.token)[0], 400)
        self.assertEqual(self.calls, [])

    def test_subprocess_timeout_and_invalid_output_are_sanitized(self):
        import subprocess
        with patch('relay.subprocess.run', side_effect=subprocess.TimeoutExpired('fixed bridge', 25)):
            self.assertEqual(perform({'action': 'discover'}), {'ok': False, 'error': 'timeout'})
        with patch('relay.subprocess.run', return_value=type('Result', (), {'returncode': 0, 'stdout': 'private invalid driver output'})()):
            self.assertEqual(perform({'action': 'discover'}), {'ok': False, 'error': 'unavailable'})

if __name__ == '__main__': unittest.main()
