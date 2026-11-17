import asyncio
import unittest
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch
from bridge import decode, perform, BridgeError, CHANNELS

class BridgeTests(unittest.IsolatedAsyncioTestCase):
    def test_decoding_and_invalid_ranges(self):
        self.assertEqual(decode('temperature', b'\xbb\xfe'), '-3.25')
        self.assertEqual(decode('air_humidity', b'\xf7\x0d'), '35.75')
        self.assertEqual(decode('battery', b'\x54'), '84')
        for kind, raw in [('battery', b'\x65'), ('temperature', b'\x01'), ('air_humidity', b'\xff\xff')]:
            with self.assertRaises(BridgeError): decode(kind, raw)

    async def test_discovery_limits_results_and_sanitizes_names(self):
        scanner = SimpleNamespace(discover=AsyncMock(return_value={i: (SimpleNamespace(address=f'AA:BB:CC:DD:EE:{i:02X}', name='ignored'), SimpleNamespace(local_name='Probe\n', rssi=-60)) for i in range(110)}))
        with patch('bridge.sys.platform', 'linux'):
            result = await perform({'action': 'discover', 'adapter': 'hci1'}, scanner, None)
        self.assertEqual(len(result), 100)
        self.assertEqual(result[0]['name'], 'Probe')
        self.assertEqual(scanner.discover.call_args.kwargs['bluez'], {'adapter': 'hci1'})

    async def test_native_macos_discovery_preserves_uuid_identity_without_bluez_options(self):
        address = '12345678-1234-1234-1234-123456789abc'
        scanner = SimpleNamespace(discover=AsyncMock(return_value={1: (SimpleNamespace(address=address, name='Mac probe'), SimpleNamespace(local_name=None, rssi=-50))}))
        with patch('bridge.sys.platform', 'darwin'):
            result = await perform({'action': 'discover'}, scanner, None)
        self.assertEqual(result[0]['address'], address.upper())
        self.assertNotIn('bluez', scanner.discover.call_args.kwargs)

    async def test_read_disconnects_on_success_and_invalid_data_without_any_write(self):
        class Client:
            def __init__(self):
                self.closed = False
                characteristic = SimpleNamespace(properties=['read'])
                self.services = SimpleNamespace(get_characteristic=lambda uuid: characteristic if uuid == CHANNELS['battery'][0] else None)
                self.read_gatt_char = AsyncMock(return_value=b'\x54')
            async def __aenter__(self): return self
            async def __aexit__(self, *args): self.closed = True
        connection = Client()
        scanner = SimpleNamespace(find_device_by_address=AsyncMock(return_value=object()))
        result = await perform({'action': 'read', 'address': 'AA:BB:CC:DD:EE:02'}, scanner, lambda *a, **kw: connection)
        self.assertEqual(result['readings'], [{'kind': 'battery', 'value': '84'}]); self.assertTrue(connection.closed)
        connection.closed = False; connection.read_gatt_char.return_value = b'\xff'
        with self.assertRaises(BridgeError): await perform({'action': 'read', 'address': 'AA:BB:CC:DD:EE:02'}, scanner, lambda *a, **kw: connection)
        self.assertTrue(connection.closed)

    async def test_invalid_address_and_missing_device_do_not_connect(self):
        scanner = SimpleNamespace(find_device_by_address=AsyncMock(return_value=None))
        with self.assertRaises(BridgeError): await perform({'action': 'read', 'address': 'not-an-address'}, scanner, None)
        scanner.find_device_by_address.assert_not_called()
        with self.assertRaises(BridgeError): await perform({'action': 'read', 'address': 'AA:BB:CC:DD:EE:02'}, scanner, None)

if __name__ == '__main__': unittest.main()
