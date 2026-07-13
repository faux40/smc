{{-- Chromium print header (rendered in the @page top margin, repeats on every
     page). Chromium zeroes the header font and ignores page CSS, so everything
     is inline — same constraint as pdf/partials/footer. The sheet reserves a
     taller top margin (see sign-in-sheet.blade.php) to make room. --}}
<div style="width: 100%; font-size: 10px; color: #374151; font-family: sans-serif; padding: 0 0.75in; line-height: 1.5;">
    <div>
        <span style="float: left; font-weight: bold;">{{ $org_name ?? '' }} — {{ $title ?? '' }}</span>
        <span style="float: right;">{{ $date ?? '' }}@if (!empty($time)) · {{ $time }}@endif</span>
    </div>
    <div style="clear: both; color: #6b7280;">
        <span style="float: left;">Location: {{ ($location ?? '') !== '' && $location !== null ? $location : '—' }}</span>
        <span style="float: right;">Trainer: {{ ($trainer ?? '') !== '' && $trainer !== null ? $trainer : '—' }}</span>
    </div>
</div>
