{{-- Taller top margin hosts the repeating per-page class-info header
     (pdf.partials.sign-in-header). Row capacities in ClassSignInSheet are
     derived from these margins — keep them in step. --}}
@extends('pdf.layout', ['pageSize' => '8.5in 11in', 'pageMargin' => '1.15in 0.75in 0.75in'])

@section('content')
<div class="text-[13px] text-[#111827]">
    <table class="mb-2 w-full">
        <tr>
            <td class="align-top"><span class="text-[20px] font-bold">{{ $org_name }}</span></td>
            <td class="text-right align-top">
                <div class="text-[16px] font-bold">{{ $title }}</div>
                <div class="text-[13px] text-[#4b5563]">{{ $date }}@if ($time) · {{ $time }}@endif</div>
            </td>
        </tr>
    </table>

    <h1 class="my-4 text-center text-[24px] underline">Sign In Sheet</h1>

    <table class="mb-3.5 w-full">
        <tr>
            <td class="w-1/2 align-top leading-relaxed">
                <div><span class="text-[#4b5563]">Location:</span> {{ $location ?: '—' }}</div>
                @if ($address)
                    <div class="whitespace-pre-line text-[#374151]">{{ $address }}</div>
                @endif
            </td>
            <td class="w-1/2 align-top leading-relaxed">
                <div><span class="text-[#4b5563]">Trainer:</span> {{ $trainer ?: '—' }}</div>
                <div><span class="text-[#4b5563]">Length:</span> {{ $length ?: '—' }}</div>
                <div><span class="text-[#4b5563]">Students:</span> {{ $students }}@if ($max_students) of {{ $max_students }}@endif</div>
            </td>
        </tr>
    </table>

    <table class="w-full border-collapse text-[14px] [&_td]:border [&_td]:border-[#9ca3af] [&_th]:border [&_th]:border-[#9ca3af]">
        <thead>
            <tr class="bg-[#f3f4f6] text-left">
                <th class="w-[30px] px-2.5 py-[7px] text-center">#</th>
                <th class="w-[48%] px-2.5 py-[7px]">Employee Name</th>
                <th class="px-2.5 py-[7px]">Signature</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $name)
                <tr>
                    <td class="h-9 px-2.5 py-[5px] text-center text-[#6b7280]">{{ $i + 1 }}</td>
                    <td class="h-9 px-2.5 py-[5px]">{{ $name }}</td>
                    <td class="h-9 px-2.5 py-[5px]"></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
