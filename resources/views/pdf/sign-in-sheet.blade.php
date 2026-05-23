<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0.75in; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', Arial, sans-serif; color: #111827; font-size: 13px; }

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
            position: fixed; bottom: 0; left: 0; right: 0;
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
                <div class="d">{{ $date }}</div>
            </td>
        </tr>
    </table>

    <h1>Sign In Sheet</h1>

    <table class="info">
        <tr>
            <td>
                <div><span class="label">Location:</span> {{ $location ?: '—' }}</div>
                <div><span class="label">Length:</span> {{ $length ?: '—' }}</div>
                <div><span class="label">Students:</span> {{ $students }}</div>
            </td>
            <td>
                <div><span class="label">Trainer:</span> {{ $trainer ?: '—' }}</div>
                <div><span class="label">Training location:</span> {{ $training_location ?: '—' }}</div>
                @if ($training_address)
                    <div style="white-space: pre-line; color:#374151;">{{ $training_address }}</div>
                @endif
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
