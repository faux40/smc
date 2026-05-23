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
            height: 7.2in;            /* stays within one landscape Letter page */
            padding: 0.45in;
            overflow: hidden;
        }
        .cert.break { page-break-before: always; }

        /* Decorative double frame (original; not the TrainingWise artwork). */
        .frame-outer {
            position: absolute;
            top: 0.3in; left: 0.3in; right: 0.3in; bottom: 0.3in;
            border: 6px solid #15803d;
        }
        .frame-inner {
            position: absolute;
            top: 0.42in; left: 0.42in; right: 0.42in; bottom: 0.42in;
            border: 1.5px solid #15803d;
        }
        .corner {
            position: absolute; color: #15803d; font-size: 26px; line-height: 1;
        }
        .corner.tl { top: 0.3in; left: 0.34in; }
        .corner.tr { top: 0.3in; right: 0.34in; }
        .corner.bl { bottom: 0.34in; left: 0.34in; }
        .corner.br { bottom: 0.34in; right: 0.34in; }

        .content { position: relative; text-align: center; padding: 0.7in 0.9in 0; }
        .org { font-size: 20px; font-weight: bold; letter-spacing: 0.5px; }
        .title {
            font-size: 46px; letter-spacing: 12px; color: #166534;
            margin-top: 10px; font-weight: normal;
        }
        .subtitle { font-style: italic; font-size: 18px; color: #4b5563; margin-top: 2px; }
        .name-band {
            background: #ecfdf5; border-top: 1px solid #a7f3d0; border-bottom: 1px solid #a7f3d0;
            margin: 22px auto 0; padding: 10px 0; width: 80%;
        }
        .name { font-size: 34px; color: #14532d; }
        .lead { font-style: italic; font-size: 14px; color: #4b5563; margin-top: 18px; }
        .cert-title { font-size: 20px; font-weight: bold; margin-top: 8px; }
        .cert-text { font-size: 12.5px; color: #374151; margin-top: 8px; line-height: 1.4; }

        .footer { position: absolute; left: 0.9in; right: 0.9in; bottom: 0.85in; }
        .footer td { vertical-align: bottom; font-size: 11px; }
        .meta-label { color: #6b7280; padding-right: 8px; }
        .sig { font-family: 'GreatVibes', cursive; font-size: 30px; color: #111827; line-height: 1; }
        .sig-line { border-top: 1px solid #9ca3af; margin-top: 2px; padding-top: 3px; }
        .sig-caption { font-size: 9px; letter-spacing: 1px; color: #6b7280; }
    </style>
</head>
<body>
@foreach ($certs as $c)
    <div class="cert {{ $loop->first ? '' : 'break' }}">
        <div class="frame-outer"></div>
        <div class="frame-inner"></div>
        <div class="corner tl">&#10070;</div>
        <div class="corner tr">&#10070;</div>
        <div class="corner bl">&#10070;</div>
        <div class="corner br">&#10070;</div>

        <div class="content">
            <div class="org">{{ $c['org_name'] }}</div>
            <div class="title">CERTIFICATE</div>
            <div class="subtitle">of Training</div>

            <div class="name-band"><div class="name">{{ $c['student_name'] }}</div></div>

            <div class="lead">Has successfully fulfilled the training requirements for</div>
            <div class="cert-title">{{ $c['cert_title'] }}</div>
            @foreach ($c['text_lines'] as $line)
                <div class="cert-text">{{ $line }}</div>
            @endforeach
        </div>

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
                            <div style="height: 30px;">&nbsp;</div>
                        @endif
                        <div class="sig-line sig-caption">INSTRUCTOR</div>
                        <div style="margin-top: 14px;">{{ $c['issue_date'] }}</div>
                        <div class="sig-caption">ISSUE DATE</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
@endforeach
</body>
</html>
