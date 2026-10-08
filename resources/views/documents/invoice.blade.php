<x-document-layout :reference="$doc->reference" :title="'Invoice '.$doc->reference" :back="auth()->user()->isAdmin() ? route('admin.invoices.show', $doc) : route('portal.invoices.show', $doc)">
    @include('documents.partials.body', ['doc' => $doc, 'kind' => 'Invoice'])
</x-document-layout>
