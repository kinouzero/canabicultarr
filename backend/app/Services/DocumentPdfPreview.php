<?php

namespace App\Services;

use App\Exceptions\DocumentException;
use App\Models\Document;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentPdfPreview
{
    public function response(Request $request, Document $document)
    {
        Gate::authorize('view', $document);
        abort_unless($document->format === 'pdf' && $document->media_type === 'application/pdf', 404);
        $size = $document->size_bytes;
        $etag = '"'.$document->sha256.'"';
        $headers = [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline',
            'Accept-Ranges' => 'bytes', 'ETag' => $etag, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Referrer-Policy' => 'no-referrer', 'X-Accel-Buffering' => 'no',
        ];
        try {
            $disk = Storage::disk($document->disk);
            if (! $disk->exists($document->path)) {
                throw new DocumentException('documents.errors.fileUnavailable', 404);
            }
            $range = $request->isMethod('GET') && (! $request->headers->has('If-Range') || $request->header('If-Range') === $etag)
                ? $this->range($request->header('Range'), $size) : null;
            if ($range === false) {
                return response('', 416, [...$headers, 'Content-Range' => 'bytes */'.$size, 'Content-Length' => '0']);
            }
            [$start, $end] = $range ?? [0, $size - 1];
            $length = $end - $start + 1;
            $headers['Content-Length'] = (string) $length;
            if ($range !== null) {
                $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
            }
            if ($request->isMethod('HEAD')) {
                return response('', 200, $headers);
            }
            if ($disk instanceof AwsS3V3Adapter && $range !== null) {
                // Forward ranges upstream: do not download/discard an S3 object's prefix.
                $result = $disk->getClient()->getObject([
                    'Bucket' => $disk->getConfig()['bucket'], 'Key' => $disk->path($document->path),
                    'Range' => 'bytes='.$start.'-'.$end, '@http' => ['stream' => true],
                ]);
                if ($result['ContentRange'] !== 'bytes '.$start.'-'.$end.'/'.$size) {
                    throw new DocumentException('documents.errors.storageUnavailable', 503);
                }
                $stream = $result['Body']->detach();
            } else {
                $stream = $disk->readStream($document->path);
                if (is_resource($stream) && $start > 0) {
                    if (! stream_get_meta_data($stream)['seekable'] || fseek($stream, $start) !== 0) {
                        fclose($stream);
                        throw new DocumentException('documents.errors.storageUnavailable', 503);
                    }
                }
            }
            if (! is_resource($stream)) {
                throw new DocumentException('documents.errors.storageUnavailable', 503);
            }
        } catch (DocumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DocumentException('documents.errors.storageUnavailable', 503);
        }

        return response()->stream(function () use ($stream, $length) {
            $output = fopen('php://output', 'wb');
            try {
                stream_copy_to_stream($stream, $output, $length);
            } finally {
                fclose($stream);
                fclose($output);
            }
        }, $range !== null ? 206 : 200, $headers);
    }

    /** Single byte range; null means ignore/serve full, false means unsatisfiable. */
    private function range(?string $header, int $size): array|false|null
    {
        if ($header === null || ! preg_match('/^bytes=(\d*)-(\d*)$/iD', trim($header), $parts) || ($parts[1] === '' && $parts[2] === '')) {
            return null;
        }
        if ($parts[1] === '') {
            $suffix = (int) $parts[2];

            return $suffix > 0 && $size > 0 ? [max(0, $size - $suffix), $size - 1] : false;
        }
        $start = (int) $parts[1];
        $end = $parts[2] === '' ? $size - 1 : min((int) $parts[2], $size - 1);

        return $start <= $end && $start < $size ? [$start, $end] : false;
    }
}
