<?php

declare(strict_types=1);

final class RuntimeLog
{
    private const LIMIT = 262_144;

    private readonly string $path;

    public function __construct(string $profile)
    {
        $this->path = $profile.'/desktop-runtime.log';
    }

    public function record(string $event, ?string $service = null): void
    {
        $file = $this->open('c+b');
        if (! is_resource($file)) {
            return;
        }

        try {
            if (! flock($file, LOCK_EX)) {
                return;
            }
            $line = gmdate('Y-m-d\TH:i:s\Z').' '.$event.($service === null ? '' : ' '.$service).PHP_EOL;
            fseek($file, 0, SEEK_END);
            if (ftell($file) + strlen($line) > self::LIMIT) {
                fseek($file, -intdiv(self::LIMIT, 2), SEEK_END);
                fgets($file);
                $recent = stream_get_contents($file, self::LIMIT);
                if ($recent === false || ! ftruncate($file, 0) || ! rewind($file)) {
                    return;
                }
                fwrite($file, $recent);
            }
            fwrite($file, $line);
        } finally {
            fclose($file);
        }
    }

    public function contents(): string
    {
        $file = $this->open('rb');
        if (! is_resource($file)) {
            return '';
        }

        try {
            return flock($file, LOCK_SH) ? stream_get_contents($file, self::LIMIT) ?: '' : '';
        } finally {
            fclose($file);
        }
    }

    private function open(string $mode)
    {
        if (is_link($this->path) || (file_exists($this->path) && ! is_file($this->path))) {
            return false;
        }

        return @fopen($this->path, $mode);
    }
}
