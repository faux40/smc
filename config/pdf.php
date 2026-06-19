<?php

return [
    /*
     | Headless-browser (Browsershot/Chromium) settings for all generated PDFs.
     | Dev (Docker) defaults below; prod (Forge) overrides via env. `no_sandbox`
     | is required when Chromium runs as a non-root container/CI user.
     */
    'node_binary' => env('PDF_NODE_BINARY', '/usr/bin/node'),
    'node_modules' => env('PDF_NODE_MODULES', base_path('node_modules')),
    'chrome_path' => env('PDF_CHROME_PATH', '/usr/bin/chromium'),
    'no_sandbox' => env('PDF_NO_SANDBOX', true),
];
