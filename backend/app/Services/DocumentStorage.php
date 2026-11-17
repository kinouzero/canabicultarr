<?php

namespace App\Services;

class DocumentStorage
{
    public function disks(): array
    {
        $disks = ['documents'];
        $s3 = config('filesystems.disks.documents_s3');
        if (! empty($s3['key']) && ! empty($s3['secret']) && ! empty($s3['bucket'])) {
            $disks[] = 'documents_s3';
        }

        return $disks;
    }
}
