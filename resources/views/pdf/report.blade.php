@extends('pdf.layout', ['pageSize' => '11in 8.5in', 'pageMargin' => '0.5in'])

{{-- Generic tabular report: a header (org + title + optional subtitle/filters)
     and a bordered table driven by $columns (key+label) and $rows (assoc).
     Shared by every Reports export (training record, user transcript, org
     completion report). --}}
@section('content')
<div class="text-[12px] text-[#111827]">
    <table class="mb-3 w-full">
        <tr>
            <td class="align-top"><span class="text-[18px] font-bold">{{ $org_name }}</span></td>
            <td class="text-right align-top">
                <div class="text-[15px] font-bold">{{ $title }}</div>
                @if (!empty($subtitle))
                    <div class="text-[12px] text-[#4b5563]">{{ $subtitle }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if (!empty($filters))
        <div class="mb-2 text-[11px] text-[#4b5563]">{{ $filters }}</div>
    @endif

    {{-- No status colour banding: this report prints in black & white. The
         expiry status is still conveyed by the textual "Status" column. The
         `_band` key remains on each row for the on-screen list's badge. --}}
    <table class="w-full border-collapse text-[12px] [&_td]:border [&_td]:border-[#9ca3af] [&_th]:border [&_th]:border-[#9ca3af]">
        <thead>
            <tr class="bg-[#f3f4f6] text-left">
                @foreach ($columns as $col)
                    <th class="px-2 py-1">{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @php($items = !empty($groups) ? $groups : array_map(fn ($r) => ['type' => 'row', 'data' => $r], $rows))
            @forelse ($items as $item)
                @if ($item['type'] === 'group')
                    {{-- Group header band: full-width, indented by nesting level. --}}
                    <tr>
                        <td class="bg-[#f3f4f6] px-2 py-1 font-bold" colspan="{{ count($columns) }}"
                            style="padding-left: {{ 0.5 + $item['level'] * 0.75 }}rem">
                            {{ $item['label'] }} ({{ $item['count'] }})
                        </td>
                    </tr>
                @else
                    <tr>
                        @foreach ($columns as $col)
                            <td class="px-2 py-1 align-top">{{ $item['data'][$col['key']] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endif
            @empty
                <tr>
                    <td class="px-2 py-2 text-center text-[#6b7280]" colspan="{{ count($columns) }}">
                        No records.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (!empty($capped))
        <div class="mt-2 text-[11px] text-[#b91c1c]">
            Showing the first {{ $cap }} rows — narrow your filters to see the rest.
        </div>
    @endif
</div>
@endsection
