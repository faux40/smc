@extends('pdf.layout', ['pageSize' => '8.5in 11in'])

@section('content')
<div class="p-[0.75in] text-[13px] text-[#111827]">
    <div class="text-right text-[16px] font-bold text-[#374151]">{{ $org_name }}</div>
    <h1 class="mb-4 mt-2 text-center text-[22px]">{{ $title }}</h1>

    <table class="mb-[18px] w-full text-[13px] [&_td]:py-0.5 [&_td]:align-top [&_td]:leading-relaxed">
        <tr>
            <td class="w-[115px] text-[#4b5563]">Date</td><td>{{ $start_date ?: '—' }}</td>
            <td class="w-[40px]"></td>
            <td class="w-[115px] text-[#4b5563]">Trainer</td><td>{{ $trainer ?: '—' }}</td>
        </tr>
        <tr>
            <td class="text-[#4b5563]">Time</td><td>{{ $time ?: '—' }}</td>
            <td></td>
            <td class="text-[#4b5563]">Location</td><td>{{ $location ?: '—' }}</td>
        </tr>
        <tr>
            <td class="text-[#4b5563]">Closed Date</td><td>{{ $closed_date ?: '—' }}</td>
            <td></td>
            <td class="text-[#4b5563]">Address</td>
            <td class="whitespace-pre-line">{{ $address ?: '—' }}</td>
        </tr>
        <tr>
            <td class="text-[#4b5563]">Length</td><td>{{ $length ?: '—' }}</td>
            <td></td>
            <td class="text-[#4b5563]">Notes</td><td>{{ $notes ?: '—' }}</td>
        </tr>
        <tr>
            <td class="text-[#4b5563]">Certificates</td><td>{{ $certificates }}</td>
            <td></td>
            <td></td><td></td>
        </tr>
    </table>

    <div class="mb-1.5 mt-1 text-[15px] font-bold">Trainings</div>
    @if (count($trainings))
        <ul class="mb-4 ml-[18px] list-disc">
            @foreach ($trainings as $t)
                <li class="text-[13px] leading-relaxed">
                    {{ $t['name'] }}
                    <span class="text-[#4b5563]">— {{ $t['hours'] }}@if ($t['frequency']) · {{ $t['frequency'] }}@endif</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mb-4 text-[#6b7280]">No trainings on this class.</p>
    @endif

    <div class="mb-1.5 mt-1 text-[15px] font-bold">Certificate Issued</div>
    <table class="w-full border-collapse text-[12px] [&_td]:border-b [&_td]:border-[#e5e7eb] [&_td]:px-[7px] [&_td]:py-[7px] [&_td]:text-left">
        <thead>
            <tr class="text-[#374151] [&_th]:border-b-[1.5px] [&_th]:border-[#9ca3af] [&_th]:px-[7px] [&_th]:py-[7px] [&_th]:text-left">
                <th class="w-[26px]">#</th>
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
                    <td class="text-[#6b7280]">{{ $i + 1 }}</td>
                    <td>{{ $r['name'] }}</td>
                    <td>{{ $r['emp_number'] }}</td>
                    <td>{{ $r['location'] }}</td>
                    <td>{{ $r['cert_id'] }}</td>
                    <td>{{ $r['issue_date'] }}</td>
                    <td>{{ $r['expires'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-3.5 text-center text-[#6b7280]">No certificates issued.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="fixed inset-x-[0.75in] bottom-[0.4in] text-[9px] text-[#6b7280]">
        {{ $generated_at }}<span class="float-right">{{ $title }}</span>
    </div>
</div>
@endsection
