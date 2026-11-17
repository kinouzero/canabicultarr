<?php

namespace App\Services;

use App\Models\Document;
use App\Rules\DocumentFile;
use GdImage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentThumbnail
{
    public const EDGE = 640;

    public const SCREEN_EDGE = 1600;

    public function response(Document $document, DocumentLibrary $library, bool $screen = false)
    {
        Gate::authorize('view', $document);
        abort_unless($document->format === 'image' && in_array($document->media_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        // Derivatives stay on the original private disk and never bypass current access.
        $profile = $screen ? 'screen' : 'thumbnail';
        $path = $document->id.'/'.$profile.'-v1-'.$document->sha256.'.webp';
        try {
            $disk = Storage::disk($document->disk);
            if (! $disk->exists($document->path)) {
                return $library->preview($document);
            }
            if ($disk->exists($path)) {
                $bytes = $disk->get($path);
            } else {
                $stream = $disk->readStream($document->path);
                if (! is_resource($stream)) {
                    return $library->preview($document);
                }
                try {
                    $original = stream_get_contents($stream, DocumentFile::MAX_BYTES + 1);
                } finally {
                    fclose($stream);
                }
                $bytes = $original === false ? null : $this->render($original, $screen ? self::SCREEN_EDGE : self::EDGE);
                if ($bytes === null) {
                    return $library->preview($document);
                }
                $disk->put($path, $bytes, ['visibility' => 'private', 'ContentType' => 'image/webp']);
            }
        } catch (Throwable) {
            // A failed derivative must not hide an otherwise available original.
            return $library->preview($document);
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/webp', 'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function render(string $bytes, int $edge): ?string
    {
        if (strlen($bytes) > DocumentFile::MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 16000000) {
            return null;
        }
        $memory = ini_parse_quantity(ini_get('memory_limit'));
        if ($memory > 0 && $memory - memory_get_usage(true) < $info[0] * $info[1] * 6 + strlen($bytes) * 2 + 8388608) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }
        $scale = min(1, $edge / max($info[0], $info[1]));
        $image = imagescale($source, max(1, (int) round($info[0] * $scale)), max(1, (int) round($info[1] * $scale)));
        unset($source);
        if (! $image) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $metadata = fopen('php://temp', 'w+b');
            try {
                fwrite($metadata, $bytes);
                rewind($metadata);
                $orientation = (@exif_read_data($metadata)['Orientation']) ?? 1;
            } finally {
                fclose($metadata);
            }
            $image = $this->orient($image, (int) $orientation);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        try {
            return imagewebp($image, null, 80) ? ob_get_contents() : null;
        } finally {
            ob_end_clean();
        }
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [4, 5], true) ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3 => 180, 5, 6, 7 => -90, 8 => 90, default => 0,
        };

        return $angle ? imagerotate($image, $angle, 0) : $image;
    }
}
