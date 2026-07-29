{{-- One outcome section's body (Failed / Incomplete) on the class summary:
     per-training groups in the same shape as the certificate groups, minus the
     issue/expire dates and with the instructor's close-out note. Prints an
     explicit "None." so an empty section reads as accounted-for rather than
     forgotten. Expects: $groups --}}
@forelse ($groups as $g)
    <div class="mb-4">
        <div class="mb-1 border-b-[1.5px] border-[#9ca3af] pb-1 text-[14px] font-semibold text-[#111827] [break-inside:avoid]">
            {{ $g['training'] }}
        </div>
        <table class="w-full border-collapse text-[12px] [&_td]:border-b [&_td]:border-[#e5e7eb] [&_td]:px-[7px] [&_td]:py-[7px] [&_td]:text-left">
            <thead>
                <tr class="text-[#374151] [&_th]:border-b [&_th]:border-[#d1d5db] [&_th]:px-[7px] [&_th]:py-[5px] [&_th]:text-left">
                    <th class="w-[26px]">#</th>
                    <th>Employee Name</th>
                    <th>Emp #</th>
                    <th>Location</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($g['rows'] as $i => $r)
                    <tr>
                        <td class="text-[#6b7280]">{{ $i + 1 }}</td>
                        <td>{{ $r['name'] }}</td>
                        <td>{{ $r['emp_number'] }}</td>
                        <td>{{ $r['location'] }}</td>
                        <td class="whitespace-pre-line">{{ $r['notes'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@empty
    <p class="mb-4 text-[#6b7280]">None.</p>
@endforelse
