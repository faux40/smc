<?php

return [
    /*
     | Optional certificate background. If a single-page, 8.5"×11" LANDSCAPE
     | PDF exists at this path it is merged UNDER the rendered certificate text
     | (vector overlay via FPDI — no per-page raster, so memory stays flat for
     | large batches). Swap the design by replacing this one file; delete it to
     | fall back to text-only certificates. No upload UI by design.
     */
    'background' => env('CERT_BACKGROUND_PATH', storage_path('app/private/cert_background.pdf')),
];
