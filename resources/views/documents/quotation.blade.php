<x-document-layout :reference="$doc->reference" :title="'Quotation '.$doc->reference" :back="auth()->user()->isAdmin() ? route('admin.quotations.show', $doc) : route('portal.quotations.show', $doc)">
    @include('documents.partials.body', ['doc' => $doc, 'kind' => 'Quotation'])
</x-document-layout>
