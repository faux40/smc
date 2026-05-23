<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* @page margins are unreliable in this DomPDF build, so the 0.75in
           "margin" is body padding (the page is 8.5x11 via setPaper). */
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Helvetica', Arial, sans-serif; color: #111827;
            font-size: 13px; padding: 0.75in;
        }

        .head { width: 100%; margin-bottom: 8px; }
        .head td { vertical-align: top; }
        .org { font-size: 20px; font-weight: bold; }
        .head-right { text-align: right; }
        .head-right .t { font-size: 16px; font-weight: bold; }
        .head-right .d { font-size: 13px; color: #4b5563; }

        h1 { text-align: center; font-size: 24px; margin: 14px 0 16px; text-decoration: underline; }

        .info { width: 100%; margin-bottom: 14px; }
        .info td { vertical-align: top; width: 50%; line-height: 1.6; font-size: 13px; }
        .info .label { color: #4b5563; }

        table.roster { width: 100%; border-collapse: collapse; }
        table.roster th, table.roster td { border: 1px solid #9ca3af; }
        table.roster thead th {
            background: #f3f4f6; text-align: left; padding: 7px 10px; font-size: 14px;
        }
        .num { width: 30px; text-align: center; color: #6b7280; }
        .name-col { width: 48%; }
        .roster td { height: 36px; padding: 5px 10px; font-size: 14px; }
        .roster .name-val { font-weight: normal; }

        .foot {
            position: fixed; bottom: 0.4in; left: 0.75in; right: 0.75in;
            font-size: 9px; color: #6b7280;
        }
        .foot .l { float: left; }
        .foot .r { float: right; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td><span class="org">{{ $org_name }}</span></td>
            <td class="head-right">
                <div class="t">{{ $title }}</div>
                <div class="d">{{ $date }}@if ($time) · {{ $time }}@endif</div>
            </td>
        </tr>
    </table>

    <h1>Sign In Sheet</h1>

    <table class="info">
        <tr>
            <td>
                <div><span class="label">Location:</span> {{ $location ?: '—' }}</div>
                @if ($address)
                    <div style="white-space: pre-line; color:#374151;">{{ $address }}</div>
                @endif
            </td>
            <td>
                <div><span class="label">Trainer:</span> {{ $trainer ?: '—' }}</div>
                <div><span class="label">Length:</span> {{ $length ?: '—' }}</div>
                <div><span class="label">Students:</span> {{ $students }}</div>
            </td>
        </tr>
    </table>

    <table class="roster">
        <thead>
            <tr>
                <th class="num">#</th>
                <th class="name-col">Employee Name</th>
                <th>Signature</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $name)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td class="name-val">{{ $name }}</td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="foot">
        <span class="l">{{ now()->format('M j, Y g:i A') }}</span>
        <span class="r">{{ $title }}</span>
    </div>
</body>
</html>
