<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class DocumentFile implements ValidationRule
{
    public const MAX_BYTES = 10485760;

    public static function metadata(UploadedFile $file): ?array
    {
        $extension = strtolower(pathinfo(str_replace('\\', '/', $file->getClientOriginalName()), PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        return match (true) {
            $extension === 'pdf' && $mime === 'application/pdf' => ['pdf', 'pdf', $mime],
            in_array($extension, ['jpg', 'jpeg'], true) && $mime === 'image/jpeg' => ['image', 'jpg', $mime],
            $extension === 'png' && $mime === 'image/png' => ['image', 'png', $mime],
            $extension === 'webp' && $mime === 'image/webp' => ['image', 'webp', $mime],
            $extension === 'txt' && $mime === 'text/plain' => ['text', 'txt', $mime],
            $extension === 'csv' && in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true) => ['text', 'csv', $mime],
            default => null,
        };
    }

    public static function validatedMetadata(mixed $file): array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() < 1) {
            throw ValidationException::withMessages(['file' => ['documents.validation.fileInvalid']]);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => ['documents.errors.tooLarge']]);
        }

        return self::metadata($file)
            ?? throw ValidationException::withMessages(['file' => ['documents.validation.fileInvalid']]);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            self::validatedMetadata($value);
        } catch (ValidationException $exception) {
            $fail($exception->errors()['file'][0]);
        }
    }
}
