<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">

    {{-- The app's compiled Tailwind, inlined so Chromium styles the PDF offline. --}}
    <style>{!! \App\Support\PdfRenderer::tailwindCss() !!}</style>

    <style>
        /* Each document declares its own page size/orientation; Browsershot
           renders with preferCSSPageSize so this wins. */
        @page { size: {{ $pageSize ?? '8.5in 11in' }}; margin: 0; }
        html, body { margin: 0; padding: 0; }
        /* The GreatVibes signature face (used by the certificate). */
        @font-face {
            font-family: 'GreatVibes';
            font-style: normal;
            font-weight: normal;
            src: url("{{ resource_path('fonts/GreatVibes-Regular.ttf') }}") format("truetype");
        }
    </style>

    @stack('styles')
</head>
<body class="{{ $bodyClass ?? '' }}">
    @yield('content')
</body>
</html>
