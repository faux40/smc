<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0.6in 0.7in; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', Arial, sans-serif; color: #111827; font-size: 11px; }

        .org { text-align: right; font-size: 13px; font-weight: bold; color: #374151; }
        h1 { text-align: center; font-size: 17px; margin: 6px 0 12px; }

        table.info { width: 100%; margin-bottom: 14px; }
        table.info td { vertical-align: top; padding: 1px 0; line-height: 1.4; }
        .label { color: #4b5563; width: 90px; }
        .col-gap { width: 36px; }

        .section-title { font-size: 12px; font-weight: bold; margin-bottom: 4px; }
        table.certs { width: 100%; border-collapse: collapse; }
        table.certs th, table.certs td {
            border-bottom: 1px solid #e5e7eb; text-align: left; padding: 5px 6px; font-size: 10px;
        }
        table.certs thead th { border-bottom: 1.5px solid #9ca3af; color: #374151; }
        .num { width: 22px; color: #6b7280; }

        .foot { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #6b7280; }
        .foot .r { float: right; }
    </style>
</head>
<body>
    <div class="org">{{ $org_name }}</div>
    <h1>{{ $title }}</h1>

    <table class="info">
        <tr>
            <td class="label">Start Date</td><td>{{ $start_date ?: '—' }}</td>
            <td class="col-gap"></td>
            <td class="label">Trainer</td><td>{{ $trainer ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">End Date</td><td>{{ $end_date ?: '—' }}</td>
            <td class="col-gap"></td>
            <td class="label">Co. Location</td><td>{{ $location ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Closed Date</td><td>{{ $closed_date ?: '—' }}</td>
            <td class="col-gap"></td>
            <td class="label">Training Location</td><td>{{ $training_location ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Length</td><td>{{ $length ?: '—' }}</td>
            <td class="col-gap"></td>
            <td class="label">Training Address</td>
            <td style="white-space: pre-line;">{{ $training_address ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Certificates</td><td>{{ $certificates }}</td>
            <td class="col-gap"></td>
            <td class="label">Notes</td><td>{{ $notes ?: '—' }}</td>
        </tr>
    </table>

    <div class="section-title">Certificate Issued</div>
    <table class="certs">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Employee Name</th>
                <th>Emp #</th>
                <th>Location</th>
                <th>Certificate</th>
                <th>Issue Date</th>
                <th>Expires</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $r)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $r['name'] }}</td>
                    <td>{{ $r['emp_number'] }}</td>
                    <td>{{ $r['location'] }}</td>
                    <td>{{ $r['cert_id'] }}</td>
                    <td>{{ $r['issue_date'] }}</td>
                    <td>{{ $r['expires'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center; color:#6b7280; padding:14px;">No certificates issued.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="foot">
        {{ now()->format('M j, Y g:i A') }}<span class="r">{{ $title }}</span>
    </div>
</body>
</html>
