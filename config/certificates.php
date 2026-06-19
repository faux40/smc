<?php

return [
    /*
     | Optional certificate background image, drawn full-page UNDER the cert
     | text by Chromium (CSS background-image). Set CERT_BACKGROUND_PATH to an
     | explicit file, otherwise the renderer auto-detects
     | storage/app/private/cert_background.{png,jpg,jpeg,gif,webp}. Swap the
     | design by replacing that one file; remove it for a plain cert. No upload
     | UI by design. PNG/JPG sized to 8.5"×11" landscape recommended.
     */
    'background' => env('CERT_BACKGROUND_PATH'),
];
