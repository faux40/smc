{{-- Tight top margin (the Chromium header is empty); the bottom keeps room for
     the generated-at footer. --}}
@extends('pdf.layout', ['pageSize' => '8.5in 11in', 'pageMargin' => '0.45in 0.75in 0.75in'])

@section('content')
{{-- Shared roster styling: section headings sit on a heavy rule so the reader
     can tell a section (Trainings / Certificates Issued / Failed / Incomplete)
     from the lighter per-training divider inside one. The table strings are
     defined once here and handed to the outcome-groups partial so a spacing
     tweak lands in a single place. --}}
@php($section = 'mb-1 mt-3 border-b-[3px] border-[#111827] pb-0.5 text-[15px] font-bold uppercase tracking-wide')
@php($groupHeader = 'mb-0.5 flex items-baseline justify-between border-b-[1.5px] border-[#9ca3af] pb-0.5 [break-inside:avoid]')
@php($rosterTable = 'w-full border-collapse text-[12px] [&_td]:border-b [&_td]:border-[#e5e7eb] [&_td]:px-[7px] [&_td]:py-[2px] [&_td]:text-left')
@php($rosterHead = 'text-[#374151] [&_th]:border-b [&_th]:border-[#d1d5db] [&_th]:px-[7px] [&_th]:py-[2px] [&_th]:text-left')
<div class="text-[13px] text-[#111827]">
    <div class="text-right text-[16px] font-bold text-[#374151]">{{ $org_name }}</div>
    <h1 class="mb-1.5 mt-0.5 text-center text-[22px]">{{ $title }}</h1>

    <table class="mb-1 w-full text-[13px] [&_td]:py-0 [&_td]:align-top [&_td]:leading-snug">
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
        <ul class="ml-[18px] list-disc">
            @foreach ($trainings as $t)
                <li class="text-[13px] leading-snug">
                    {{ $t['name'] }}
                    <span class="text-[#4b5563]">— {{ $t['hours'] }}@if ($t['frequency']) · {{ $t['frequency'] }}@endif</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-[#6b7280]">No trainings on this class.</p>
    @endif

    <div class="{{ $section }}">Certificates Issued</div>
    @forelse ($groups as $g)
        <div class="mb-2.5">
            {{-- Per-training header: issue + expire are the same for everyone
                 in this training, so they live here once instead of per row. --}}
            <div class="{{ $groupHeader }}">
                <span class="text-[14px] font-semibold text-[#111827]">{{ $g['training'] }}</span>
                <span class="text-[12px] text-[#4b5563]">
                    Issued: {{ $g['issue_date'] ?: '—' }} &nbsp;·&nbsp; Expires: {{ $g['expires'] }}
                </span>
            </div>
            <table class="{{ $rosterTable }}">
                <thead>
                    <tr class="{{ $rosterHead }}">
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

    {{-- Who earned nothing, and why — the roster's other half. A section only
         appears when somebody is in it. --}}
    @if ($failed_groups)
        <div class="{{ $section }}">Failed</div>
        @include('pdf.partials.outcome-groups', ['groups' => $failed_groups])
    @endif

    @if ($incomplete_groups)
        <div class="{{ $section }}">Incomplete</div>
        @include('pdf.partials.outcome-groups', ['groups' => $incomplete_groups])
    @endif
</div>
@endsection
