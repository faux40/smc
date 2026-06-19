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

        /* One landscape Letter page per certificate (11in × 8.5in). Text-only
           for now — no frame/border (a background image comes later). Content
           flows from the top so the whole block sits in the upper portion of
           the page; the body is a fixed, clipped track so an over-long cert
           body can never push the footer off the page or onto a second one.
           (DomPDF resolves height:100% against the parent border-box, so all
           sizing here is explicit rather than percentage-based.) */
        .cert {
            position: relative;
            width: 10.4in;
            height: 8.0in;
            margin: 0 auto;
            overflow: hidden;
        }
        .content {
            padding: 1.05in 0.85in 0;
            text-align: center;
        }

        .org { font-size: 18px; font-weight: bold; letter-spacing: 1px; }
        .rule { width: 90px; border-bottom: 2px solid #b08d57; margin: 8px auto 0; }
        .title { font-size: 42px; letter-spacing: 12px; color: #1f5c3a; margin-top: 14px; }
        .subtitle { font-style: italic; font-size: 16px; color: #4b5563; margin-top: 4px; }
        .lead { font-style: italic; font-size: 13px; color: #4b5563; margin-top: 18px; }
        .name { font-size: 32px; color: #14532d; margin-top: 8px; }
        .name-rule { width: 60%; border-bottom: 1px solid #9ca3af; margin: 6px auto 0; }

        /* Fixed, clipped body track. */
        .body { height: 1.6in; overflow: hidden; margin-top: 0.65in; }
        .cert-title { font-size: 26px; font-weight: bold; }
        .body-text { font-size: 15px; color: #374151; line-height: 1.5; margin-top: 10px; }
        .body-text p { margin: 0 0 4px; }
        .body-text strong { font-weight: bold; }
        .body-text em { font-style: italic; }
        .body-text ul, .body-text ol { margin: 0 0 4px 1.4em; text-align: left; display: inline-block; }

        /* Footer flows right under the body (no longer pinned to the page
           bottom) so it reads as part of the certificate block. */
        .footer { margin-top: 0.85in; }
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
        <div class="content">
            <div class="org">{{ $c['org_name'] }}</div>
            <div class="rule"></div>
            <div class="title">CERTIFICATE</div>
            <div class="subtitle">of Training</div>
            <div class="lead">This certifies that</div>
            <div class="name">{{ $c['student_name'] }}</div>
            <div class="name-rule"></div>

            <div class="body">
                <div class="cert-title">{{ $c['cert_title'] }}</div>
                <div class="body-text">{!! $c['cert_html'] !!}</div>
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
    </div>
@endforeach
</body>
</html>
