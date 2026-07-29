<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fonts the PDF converter can honour
    |--------------------------------------------------------------------------
    |
    | LibreOffice embeds fonts into the exported PDF, but only fonts it can
    | SEE: a family that is not installed in the container gets substituted
    | at conversion time and the card re-flows at different metrics — which
    | is exactly what ruins a print onto purchased stock.
    |
    | These are the families installed in the image (smc_docker/Dockerfile)
    | plus the names that map onto them metrically, so a template designed in
    | Word with Arial or Calibri lands on Liberation Sans / Carlito at the
    | same widths. Anything else is flagged at upload — a warning, not a
    | refusal, since substitution is sometimes acceptable.
    |
    | Matching is case-insensitive. Uploading the font file itself is a later
    | phase; until then this list is the contract.
    |
    */

    'supported_fonts' => [
        // Shipped with the image.
        'Liberation Sans',
        'Liberation Serif',
        'Liberation Mono',
        'DejaVu Sans',
        'DejaVu Serif',
        'DejaVu Sans Mono',
        'Carlito',
        'Caladea',
        // Metric-compatible aliases resolved by fontconfig.
        'Arial',
        'Helvetica',
        'Times New Roman',
        'Times',
        'Courier New',
        'Courier',
        'Calibri',
        'Cambria',
    ],

];
