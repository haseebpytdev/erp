<?php

namespace App\Services\Accounting;

use Illuminate\Http\UploadedFile;

interface ReceiptExtractionProvider
{
    /** @return array<string,mixed> */
    public function extract(UploadedFile $file): array;
}
