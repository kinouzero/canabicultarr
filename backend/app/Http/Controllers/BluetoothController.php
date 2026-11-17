<?php

namespace App\Http\Controllers;

use App\Http\Requests\BluetoothRequest;
use App\Models\BluetoothDevice;
use App\Models\BluetoothLink;
use App\Services\BluetoothDevices;
use App\Services\BluetoothOperations;

class BluetoothController extends Controller
{
    public function status(BluetoothRequest $request, BluetoothOperations $operations)
    {
        return ['data' => $operations->status($request->user())];
    }

    public function discover(BluetoothRequest $request, BluetoothOperations $operations)
    {
        return response()->json(['data' => $operations->start($request->user(), 'discover', $request->validated())], 202);
    }

    public function operation(BluetoothRequest $request, string $id, BluetoothOperations $operations)
    {
        return ['data' => $operations->get($request->user(), $id)];
    }

    public function index(BluetoothRequest $request, BluetoothDevices $devices)
    {
        $result = $devices->listing($request->user(), $request->validated());

        return ['data' => $result->items(), 'meta' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total(), 'per_page' => $result->perPage()]];
    }

    public function store(BluetoothRequest $request, BluetoothOperations $operations)
    {
        return response()->json(['data' => $operations->start($request->user(), 'pair', $request->validated())], 202);
    }

    public function destroy(BluetoothRequest $request, BluetoothDevice $device, BluetoothDevices $devices)
    {
        $devices->remove($request->user(), $device, $request->validated('version'));

        return response()->noContent();
    }

    public function update(BluetoothRequest $request, BluetoothLink $link, BluetoothDevices $devices)
    {
        $devices->toggle($request->user(), $link, $request->validated());

        return response()->noContent();
    }

    public function readings(BluetoothRequest $request, BluetoothDevice $device, BluetoothOperations $operations)
    {
        return response()->json(['data' => $operations->start($request->user(), 'read', [...$request->validated(), 'device_id' => $device->id])], 202);
    }
}
