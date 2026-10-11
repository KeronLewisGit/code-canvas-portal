@props(['sendable' => false, 'sendLabel', 'confirm', 'cancel', 'saveLabel' => 'Save changes', 'field' => 'send', 'draftValue' => '0', 'sendValue' => '1'])
{{--
    Form footer for documents the client receives. While unsent: "Save draft" plus "Save and send" (which emails the client).
    "Save draft" comes first in the markup so pressing Enter in a field never emails anyone by accident.
--}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
    @if ($sendable)
        <button name="{{ $field }}" value="{{ $draftValue }}" class="btn btn-light">Save draft</button>
        <button name="{{ $field }}" value="{{ $sendValue }}" class="btn btn-primary" data-confirm="{{ $confirm }}">{{ $sendLabel }}</button>
    @else
        <button class="btn btn-primary">{{ $saveLabel }}</button>
    @endif
    <a href="{{ $cancel }}" class="btn btn-light">Cancel</a>
</div>
