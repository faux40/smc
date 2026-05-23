<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @font-face {
            font-family: 'GreatVibes';
            font-style: normal;
            font-weight: normal;
            src: url("{{ resource_path('fonts/GreatVibes-Regular.ttf') }}") format("truetype");
        }
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', serif; color: #1f2937; }

        .cert {
            position: relative;
            width: 100%;
            height: 7.4in;            /* one landscape Letter page */
            overflow: hidden;
        }
        .cert.break { page-break-before: always; }

        /* Placeholder background (TrainingWise blank — to be replaced). */
        .bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }

        .layer { position: absolute; left: 0.9in; right: 0.9in; text-align: center; }
        .org { top: 0.85in; font-size: 19px; font-weight: bold; }
        .title { top: 1.25in; font-size: 40px; letter-spacing: 11px; color: #1f5c3a; }
        .subtitle { top: 1.95in; font-style: italic; font-size: 16px; color: #4b5563; }

        /* Name sits inside the background's green banner (~center). */
        .name { top: 2.55in; font-size: 30px; color: #14532d; }

        .lead { top: 3.5in; font-style: italic; font-size: 13px; color: #4b5563; }
        .cert-title { top: 3.85in; font-size: 18px; font-weight: bold; }
        .body-text { top: 4.3in; font-size: 12px; color: #374151; line-height: 1.4; }
        .body-text p { margin: 0 0 4px; }
        .body-text strong { font-weight: bold; }
        .body-text em { font-style: italic; }

        .footer { position: absolute; left: 1.1in; right: 1.1in; bottom: 0.95in; }
        .footer td { vertical-align: bottom; font-size: 10.5px; }
        .meta-label { color: #4b5563; padding-right: 8px; }
        .sig { font-family: 'GreatVibes', cursive; font-size: 28px; color: #111827; line-height: 1; }
        .sig-line { border-top: 1px solid #6b7280; margin-top: 2px; padding-top: 3px; }
        .sig-caption { font-size: 9px; letter-spacing: 1px; color: #4b5563; }
    </style>
</head>
<body>
@foreach ($certs as $c)
    <div class="cert {{ $loop->first ? '' : 'break' }}">
        <img class="bg" src="{{ resource_path('images/cert_background.png') }}" alt="">

        <div class="layer org">{{ $c['org_name'] }}</div>
        <div class="layer title">CERTIFICATE</div>
        <div class="layer subtitle">of Training</div>
        <div class="layer name">{{ $c['student_name'] }}</div>

        <div class="layer lead">Has successfully fulfilled the training requirements for</div>
        <div class="layer cert-title">{{ $c['cert_title'] }}</div>
        <div class="layer body-text">{!! $c['cert_html'] !!}</div>

        <div class="footer">
            <table width="100%">
                <tr>
                    <td width="55%">
                        <table>
                            <tr><td class="meta-label">Certificate</td><td>{{ $c['cert_id'] }}</td></tr>
                            <tr><td class="meta-label">Expires</td><td>{{ $c['expires'] }}</td></tr>
                            <tr><td class="meta-label">Hours</td><td>{{ $c['hours'] }}</td></tr>
                            <tr><td class="meta-label">Instructor</td><td>{{ $c['trainer'] }}</td></tr>
                        </table>
                    </td>
                    <td width="45%" align="center">
                        @if ($c['show_signature'] && $c['trainer'])
                            <div class="sig">{{ $c['trainer'] }}</div>
                        @else
                            <div style="height: 28px;">&nbsp;</div>
                        @endif
                        <div class="sig-line sig-caption">INSTRUCTOR</div>
                        <div style="margin-top: 12px;">{{ $c['issue_date'] }}</div>
                        <div class="sig-caption">ISSUE DATE</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
@endforeach
</body>
</html>
