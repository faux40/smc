{{-- Chromium print footer (rendered in the @page bottom margin, repeats per
     page, never overlaps content). Chromium zeroes the footer font and ignores
     page CSS, so everything is inline. --}}
{{-- Opt-in page number (class="pageNumber" is substituted by Chromium).
     Deliberately no "of N" — a blank back page can simply be skipped. --}}
<div style="width: 100%; font-size: 9px; color: #6b7280; font-family: sans-serif; padding: 0 0.75in; position: relative;">
    <span style="float: left;">{{ $stamp ?? '' }}</span>
    @if ($pageNumber ?? false)
        <span style="position: absolute; left: 0; right: 0; text-align: center;">Page <span class="pageNumber"></span></span>
    @endif
    <span style="float: right;">{{ $title ?? '' }}</span>
</div>
