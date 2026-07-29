@extends('pdf.layout', ['pageSize' => '8.5in 11in', 'pageMargin' => '0.75in'])

@section('content')
{{-- Section headings sit on a rule spanning the page, so the reader can tell a
     section (Trainings / Certificates Issued / Failed / Incomplete) from the
     lighter per-training divider inside one. --}}
@php($section = 'mb-2 mt-7 border-b-[3px] border-[#111827] pb-1 text-[16px] font-bold uppercase tracking-wide')
<div class="text-[13px] text-[#111827]">
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

    <div class="{{ $section }}">Trainings</div>
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

    <div class="{{ $section }}">Certificates Issued</div>
    @forelse ($groups as $g)
        <div class="mb-4">
            {{-- Per-training header: issue + expire are the same for everyone
                 in this training, so they live here once instead of per row. --}}
            <div class="mb-1 flex items-baseline justify-between border-b-[1.5px] border-[#9ca3af] pb-1 [break-inside:avoid]">
                <span class="text-[14px] font-semibold text-[#111827]">{{ $g['training'] }}</span>
                <span class="text-[12px] text-[#4b5563]">
                    Issued: {{ $g['issue_date'] ?: '—' }} &nbsp;·&nbsp; Expires: {{ $g['expires'] }}
                </span>
            </div>
            <table class="w-full border-collapse text-[12px] [&_td]:border-b [&_td]:border-[#e5e7eb] [&_td]:px-[7px] [&_td]:py-[7px] [&_td]:text-left">
                <thead>
                    <tr class="text-[#374151] [&_th]:border-b [&_th]:border-[#d1d5db] [&_th]:px-[7px] [&_th]:py-[5px] [&_th]:text-left">
                        <th class="w-[26px]">#</th>
                        <th>Employee Name</th>
                        <th>Emp #</th>
                        <th>Location</th>
                        <th>Certificate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($g['rows'] as $i => $r)
                        <tr>
                            <td class="text-[#6b7280]">{{ $i + 1 }}</td>
                            <td>{{ $r['name'] }}</td>
                            <td>{{ $r['emp_number'] }}</td>
                            <td>{{ $r['location'] }}</td>
                            <td>{{ $r['cert_id'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="text-[#6b7280]">No certificates issued.</p>
    @endforelse

    {{-- Who earned nothing, and why — the roster's other half. --}}
    <div class="{{ $section }}">Failed</div>
    @include('pdf.partials.outcome-groups', ['groups' => $failed_groups])

    <div class="{{ $section }}">Incomplete</div>
    @include('pdf.partials.outcome-groups', ['groups' => $incomplete_groups])
</div>
@endsection
