@props(['title', 'reference', 'back' => null])
@php($company = config('company'))
@php($embed = request()->boolean('embed'))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $company['short'] }}</title>
    <meta name="robots" content="noindex">
    <link rel="icon" href="{{ asset('images/brand/mark.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/document.js'])
    {{-- Page footer text comes from config, so it is passed to Paged.js as inline page CSS. --}}
    <script type="text/css" id="doc-page-css">
        @page {
            @bottom-left { content: "{{ addslashes($company['document_footer']) }}"; font-family: 'Nunito', system-ui, sans-serif; font-size: 8pt; color: #4e4b66; vertical-align: top; padding-top: 6px; border-top: 1px solid #e3e3e3; width: 70%; }
            @bottom-right { content: "{{ addslashes($reference) }} · Page " counter(page) " of " counter(pages); font-family: 'Nunito', system-ui, sans-serif; font-size: 8pt; color: #4e4b66; white-space: nowrap; vertical-align: top; padding-top: 6px; border-top: 1px solid #e3e3e3; text-align: right; }
        }
    </script>
</head>
<body class="min-h-screen {{ $embed ? 'bg-transparent' : 'bg-[#dedee3]' }}">
    @unless ($embed)
    <div class="no-print sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-line bg-white px-4 py-3 text-sm">
        <div class="flex items-center gap-3">@if ($back)<a href="{{ $back }}" class="btn btn-light btn-sm">&larr; Back</a>@endif<span class="font-semibold">{{ $title }}</span><span id="doc-pages" class="text-muted">Laying out pages…</span></div>
        <button onclick="window.print()" class="btn btn-primary btn-sm">Print or save as PDF</button>
    </div>
    @endunless
    <noscript><style>#doc-source[hidden] { display: block; }</style></noscript>
    <div id="doc-source" hidden>{{ $slot }}</div>
    <main id="doc-stage" data-css="{{ Vite::asset('resources/css/document.css') }}" class="overflow-x-auto"></main>
</body>
</html>
