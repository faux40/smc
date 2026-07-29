{{-- One outcome section's body (Failed / Incomplete) on the class summary:
     per-training groups in the same shape as the certificate groups, minus the
     issue/expire dates and with the instructor's close-out note. Only rendered
     when the section has somebody in it.
     Expects: $groups, plus $groupHeader / $rosterTable / $rosterHead from the
     parent view so both sections and the certificate list stay in step. --}}
@foreach ($groups as $g)
    <div class="mb-2.5">
        <div class="{{ $groupHeader }}">
            <span class="text-[14px] font-semibold text-[#111827]">{{ $g['training'] }}</span>
        </div>
        <table class="{{ $rosterTable }}">
            <thead>
                <tr class="{{ $rosterHead }}">
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
@endforeach
