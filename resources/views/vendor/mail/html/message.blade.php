<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('company.website')" />
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('company.name') }}. All rights reserved.<br>
[{{ preg_replace('#^https?://#', '', rtrim(config('company.website'), '/')) }}]({{ config('company.website') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
