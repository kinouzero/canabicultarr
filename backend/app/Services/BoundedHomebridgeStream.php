<?php

namespace App\Services;

use ElephantIO\Stream\SocketStream;
use RuntimeException;

class BoundedHomebridgeStream extends SocketStream
{
    private int $readBytes = 0;

    private ?float $startedAt = null;

    public function read($size = 0)
    {
        $this->startedAt ??= microtime(true);
        if ($size < 0 || $size > 1048576 || $this->readBytes + $size > 4194304 || microtime(true) - $this->startedAt > 55) {
            throw new RuntimeException('Provider event stream limit reached.');
        }
        if (! is_resource($this->handle)) {
            return null;
        }
        $read = [$this->handle];
        $write = $except = [];
        if (stream_select($read, $write, $except, 2) === 0) {
            return '';
        }
        // Bound even the HTTP handshake lines, before allocation; never log payloads.
        $data = $size > 0 ? fread($this->handle, $size) : fgets($this->handle, 8193);
        $this->readBytes += is_string($data) ? strlen($data) : 0;
        if ($this->readBytes > 4194304 || ($size === 0 && is_string($data) && strlen($data) === 8192 && ! str_ends_with($data, "\n"))) {
            throw new RuntimeException('Provider event stream limit reached.');
        }

        return $data;
    }
}
