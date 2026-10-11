@props(['url'])
{{-- Code Canvas wordmark, linking to the company website. Emails need an absolute image URL; the alt text shows when images are blocked. --}}
<tr>
<td class="header">
<a href="{{ config('company.website') }}" style="display: inline-block;">
<img src="{{ asset('images/brand/logo-email.png') }}" width="240" alt="{{ config('company.name') }}" style="display: block; width: 240px; max-width: 100%; height: auto; border: 0;">
</a>
</td>
</tr>
