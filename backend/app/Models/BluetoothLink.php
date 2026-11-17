<?php

namespace App\Models;

class BluetoothLink extends RemoteSensorLink
{
    public function bluetoothDevice()
    {
        return $this->belongsTo(BluetoothDevice::class);
    }
}
