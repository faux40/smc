@extends('pdf.layout', ['pageSize' => '11in 8.5in'])

@section('content')
@foreach ($certs as $c)
    <div
        class="relative h-[8.5in] w-[11in] overflow-hidden bg-cover bg-no-repeat font-serif text-[#1f2937] {{ $loop->first ? '' : 'break-before-page' }}"
        @if (! empty($background)) style="background-image: url('{{ $background }}'); background-size: 100% 100%;" @endif
    >
        <div class="px-[1.15in] pt-[1.05in] text-center">
            <div class="mt-[0.5in] text-[22px] font-bold tracking-[1px]">{{ $c['org_name'] }}</div>
            <div class="mx-auto mt-2 w-[90px] border-b-2 border-[#b08d57]"></div>

            <div class="mt-[0.25in] whitespace-nowrap text-[42px] tracking-[6px] text-[#1f5c3a]">
                CERTIFICATE OF TRAINING
            </div>

            <div class="mt-[0.15in] text-[13px] italic text-[#4b5563]">This certifies that</div>
            <div class="mt-[22px] text-[40px] text-[#0a311a]">{{ $c['student_name'] }}</div>
            <div class="mt-[0.35in] text-[15px] text-[#374151]">
                Has successfully fulfilled the training requirements for
            </div>
            <div class="mt-[0.25in]"></div>

            <div class="mt-[0.01in] h-[1in] overflow-hidden">
                <div class="text-[32px] font-bold">{{ $c['cert_title'] }}</div>
                <div class="mt-2.5 text-[15px] leading-[1.5] text-[#374151] [&_em]:italic [&_ol]:ml-5 [&_ol]:list-decimal [&_ol]:text-left [&_p]:mb-1 [&_strong]:font-bold [&_ul]:ml-5 [&_ul]:list-disc [&_ul]:text-left">
                    {!! $c['cert_html'] !!}
                </div>
            </div>

            <div class="mt-[0.5in] px-[0.5in]">
                <table class="w-full">
                    <tr>
                        <td class="w-[55%] text-left align-bottom text-[10.5px]">
                            <table>
                                <tr><td class="pr-2 text-[12px] font-bold tracking-[1px] text-[#4b5563]">Certificate</td><td>{{ $c['cert_id'] }}</td></tr>
                                <tr><td class="pr-2 text-[12px] font-bold tracking-[1px] text-[#4b5563]">Expires</td><td>{{ $c['expires'] }}</td></tr>
                                <tr><td class="pr-2 text-[12px] font-bold tracking-[1px] text-[#4b5563]">Hours</td><td>{{ $c['hours'] }}</td></tr>
                                <tr><td class="pr-2 text-[12px] font-bold tracking-[1px] text-[#4b5563]">Instructor</td><td>{{ $c['trainer'] }}</td></tr>
                            </table>
                        </td>
                        <td class="w-[45%] text-center align-bottom text-[10.5px]">
                            @if ($c['show_signature'] && $c['trainer'])
                                <div class="text-[28px] leading-none text-[#111827]" style="font-family: 'GreatVibes', cursive;">{{ $c['trainer'] }}</div>
                            @else
                                <div class="h-[28px]">&nbsp;</div>
                            @endif
                            <div class="mt-0.5 border-t border-[#6b7280] pt-[3px] text-[9px] tracking-[1px] text-[#4b5563]">INSTRUCTOR</div>
                            <div class="mt-3">Issued: {{ $c['issue_date'] }}</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
@endforeach
@endsection
