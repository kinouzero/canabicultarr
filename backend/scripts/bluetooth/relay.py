"""Authenticated native BLE relay for Docker hosts without radio passthrough."""
import argparse
import hmac
import json
import os
from pathlib import Path
import ssl
import socket
import subprocess
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

BRIDGE = Path(__file__).with_name('bridge.py')

def perform(request):
    try:
        result = subprocess.run([sys.executable, str(BRIDGE)], input=json.dumps(request), text=True, capture_output=True, timeout=25, check=False)
        if result.returncode != 0 or len(result.stdout) > 262144:
            return {'ok': False, 'error': 'unavailable'}
        payload = json.loads(result.stdout)
        if not isinstance(payload, dict) or not isinstance(payload.get('ok'), bool):
            return {'ok': False, 'error': 'unavailable'}
        return payload
    except subprocess.TimeoutExpired:
        return {'ok': False, 'error': 'timeout'}
    except Exception:
        return {'ok': False, 'error': 'unavailable'}

class RelayHandler(BaseHTTPRequestHandler):
    server_version = 'CanabicultarrBLE/1'
    sys_version = ''

    def setup(self):
        super().setup()
        self.connection.settimeout(4)

    def log_message(self, *args):
        pass  # Never log credentials, addresses or driver exceptions.

    def reply(self, status, payload):
        data = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Connection', 'close')
        self.end_headers()
        try: self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError): pass
        self.close_connection = True

    def authorized(self):
        supplied = self.headers.get('Authorization', '')
        return hmac.compare_digest(supplied.encode(), ('Bearer ' + self.server.token).encode())

    def do_GET(self):
        if not self.authorized(): return self.reply(401, {'ok': False})
        if self.path != '/health': return self.reply(404, {'ok': False})
        self.reply(200, {'ok': True})

    def do_POST(self):
        if not self.authorized(): return self.reply(401, {'ok': False})
        if self.path != '/bluetooth': return self.reply(404, {'ok': False})
        try:
            length = int(self.headers.get('Content-Length', '0'))
            if length < 1 or length > 8192 or self.headers.get('Transfer-Encoding') or self.headers.get_content_type() != 'application/json':
                return self.reply(400, {'ok': False})
            body = json.loads(self.rfile.read(length))
            if not isinstance(body, dict) or body.get('action') not in ['discover', 'read']:
                return self.reply(400, {'ok': False})
            # Forward only the fixed bridge protocol; no caller paths or commands.
            request = {key: body[key] for key in ['action', 'adapter', 'address'] if key in body}
            self.reply(200, self.server.perform(request))
        except (ValueError, TimeoutError):
            self.reply(400, {'ok': False})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=8766)
    parser.add_argument('--cert')
    parser.add_argument('--key')
    parser.add_argument('--allow-http', action='store_true')
    args = parser.parse_args()
    token = os.environ.get('CANABICULTARR_BLUETOOTH_TOKEN', '')
    if not 32 <= len(token) <= 256 or any(ord(char) < 33 or ord(char) > 126 for char in token):
        parser.error('Set CANABICULTARR_BLUETOOTH_TOKEN to a private random value of at least 32 ASCII characters.')
    if bool(args.cert) != bool(args.key): parser.error('Provide both --cert and --key.')
    if args.host not in ['127.0.0.1', '::1', 'localhost'] and not args.cert and not args.allow_http:
        parser.error('Use TLS for a non-loopback listener, or explicitly opt in with --allow-http on a trusted host network.')
    class Server(HTTPServer):
        address_family = socket.AF_INET6 if ':' in args.host else socket.AF_INET
    server = Server((args.host, args.port), RelayHandler)
    server.token = token
    server.perform = perform
    if args.cert:
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.load_cert_chain(args.cert, args.key)
        server.socket = context.wrap_socket(server.socket, server_side=True, do_handshake_on_connect=False)
    print('Native Bluetooth relay listening. Stop with Ctrl+C.', flush=True)
    try: server.serve_forever()
    except KeyboardInterrupt: pass
    finally: server.server_close()

if __name__ == '__main__': main()
