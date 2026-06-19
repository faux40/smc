{{-- Chromium print footer (rendered in the @page bottom margin, repeats per
     page, never overlaps content). Chromium zeroes the footer font and ignores
     page CSS, so everything is inline. --}}
<div style="width: 100%; font-size: 9px; color: #6b7280; font-family: sans-serif; padding: 0 0.75in;">
    <span style="float: left;">{{ $stamp ?? '' }}</span>
    <span style="float: right;">{{ $title ?? '' }}</span>
</div>
