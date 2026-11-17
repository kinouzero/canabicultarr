"""Read standard BLE sensors using the local OS backend (BlueZ/CoreBluetooth/WinRT)."""
import asyncio
import json
import re
import struct
import sys
from datetime import datetime, timezone

BASE = '-0000-1000-8000-00805f9b34fb'
SERVICES = ['0000181a' + BASE, '0000180f' + BASE]
CHANNELS = {
    'temperature': ('00002a6e' + BASE, 2, '<h', 100, -273.15, 327.67),
    'air_humidity': ('00002a6f' + BASE, 2, '<H', 100, 0, 100),
    'battery': ('00002a19' + BASE, 1, '<B', 1, 0, 100),
}

class BridgeError(Exception):
    pass

def decode(kind, raw):
    _, size, fmt, scale, low, high = CHANNELS[kind]
    if len(raw) != size:
        raise BridgeError('invalidReading')
    value = struct.unpack(fmt, raw)[0] / scale
    if not low <= value <= high:
        raise BridgeError('invalidReading')
    return str(int(value)) if scale == 1 else f'{value:.2f}'

def valid_address(value):
    return isinstance(value, str) and (re.fullmatch(r'(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}', value) or re.fullmatch(r'[0-9A-Fa-f]{8}(?:-[0-9A-Fa-f]{4}){3}-[0-9A-Fa-f]{12}', value))

async def perform(request, scanner, client):
    adapter = request.get('adapter', 'hci0')
    if not re.fullmatch(r'hci\d{1,3}', adapter):
        raise BridgeError('unavailable')
    options = {'bluez': {'adapter': adapter}} if sys.platform == 'linux' else {}
    if request.get('action') == 'discover':
        found = await scanner.discover(timeout=8, return_adv=True, service_uuids=SERVICES, **options)
        candidates = []
        for device, advertisement in found.values():
            if not valid_address(device.address):
                continue
            name = advertisement.local_name or device.name or 'Bluetooth sensor'
            candidates.append({'address': device.address.upper(), 'name': ''.join(c for c in name if c.isprintable())[:120], 'rssi': advertisement.rssi})
            if len(candidates) == 100:
                break
        return candidates
    if request.get('action') != 'read' or not valid_address(request.get('address', '')):
        raise BridgeError('invalidInput')
    device = await scanner.find_device_by_address(request['address'], timeout=5, **options)
    if device is None:
        raise BridgeError('disconnected')
    async with client(device, timeout=10, services=SERVICES, **options) as connection:
        readings = []
        for kind, definition in CHANNELS.items():
            characteristic = connection.services.get_characteristic(definition[0])
            if characteristic and 'read' in characteristic.properties:
                readings.append({'kind': kind, 'value': decode(kind, await connection.read_gatt_char(characteristic))})
        if not readings:
            raise BridgeError('unsupportedDevice')
        return {'observed_at': datetime.now(timezone.utc).isoformat(), 'readings': readings}

async def main():
    try:
        from bleak import BleakScanner, BleakClient
        request = json.loads(sys.stdin.buffer.read(8192))
        data = await asyncio.wait_for(perform(request, BleakScanner, BleakClient), timeout=20)
        result = {'ok': True, 'data': data}
    except BridgeError as error:
        result = {'ok': False, 'error': str(error)}
    except TimeoutError:
        result = {'ok': False, 'error': 'timeout'}
    except Exception:
        # Driver exceptions can include radio addresses and private host details.
        result = {'ok': False, 'error': 'unavailable'}
    print(json.dumps(result, ensure_ascii=True))

if __name__ == '__main__':
    asyncio.run(main())
