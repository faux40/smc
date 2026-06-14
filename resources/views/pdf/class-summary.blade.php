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

        .org { text-align: right; font-size: 16px; font-weight: bold; color: #374151; }
        h1 { text-align: center; font-size: 22px; margin: 8px 0 16px; }

        table.info { width: 100%; margin-bottom: 18px; }
        table.info td { vertical-align: top; padding: 2px 0; line-height: 1.6; font-size: 13px; }
        .label { color: #4b5563; width: 115px; }
        .col-gap { width: 40px; }

        .section-title { font-size: 15px; font-weight: bold; margin: 4px 0 6px; }
        ul.trainings { margin: 0 0 16px 18px; padding: 0; }
        ul.trainings li { font-size: 13px; line-height: 1.6; }
        ul.trainings .meta { color: #4b5563; }
        table.certs { width: 100%; border-collapse: collapse; }
        table.certs th, table.certs td {
            border-bottom: 1px solid #e5e7eb; text-align: left; padding: 7px 7px; font-size: 12px;
        }
        table.certs thead th { border-bottom: 1.5px solid #9ca3af; color: #374151; font-size: 12px; }
        .num { width: 26px; color: #6b7280; }

        .foot { position: fixed; bottom: 0.4in; left: 0.75in; right: 0.75in; font-size: 9px; color: #6b7280; }
        .foot .r { float: right; }
    </style>
</head>
<body>
    <div class="org">{{ $org_name }}</div>
    <h1>{{ $title }}</h1>

    <table class="info">
        <tr>
            <td class="label">Date</td><td>{{ $start_date ?: '—' }}</td>
            <td class="col-gap"></td>
            <td class="label">Trainer</td><td>{{ $trainer ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Time</td><td>{{ $time ?: '—' }}</td>
            <td class="col-gap"></td>
            <td class="label">Location</td><td>{{ $location ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Closed Date</td><td>{{ $closed_date ?: '—' }}</td>
            <td class="col-gap"></td>
            <td class="label">Address</td>
            <td style="white-space: pre-line;">{{ $address ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Length</td><td>{{ $length ?: '—' }}</td>
            <td class="col-gap"></td>
            <td class="label">Notes</td><td>{{ $notes ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Certificates</td><td>{{ $certificates }}</td>
            <td class="col-gap"></td>
            <td></td><td></td>
        </tr>
    </table>

    <div class="section-title">Trainings</div>
    @if (count($trainings))
        <ul class="trainings">
            @foreach ($trainings as $t)
                <li>
                    {{ $t['name'] }}
                    <span class="meta">— {{ $t['hours'] }}@if ($t['frequency']) · {{ $t['frequency'] }}@endif</span>
                </li>
            @endforeach
        </ul>
    @else
        <p style="color:#6b7280; margin-bottom:16px;">No trainings on this class.</p>
    @endif

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
        {{ $generated_at }}<span class="r">{{ $title }}</span>
    </div>
</body>
</html>
