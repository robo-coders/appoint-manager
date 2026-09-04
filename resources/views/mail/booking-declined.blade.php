<x-mail-layout :subject="$subject" :heading="$heading" :lede="$lede" :preheader="$preheader" :footer="$footer" :from="$tenant->name">
    @include('mail.parts.rows', ['rows' => $rows])
</x-mail-layout>
