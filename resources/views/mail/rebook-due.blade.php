<x-mail-layout :subject="$heading" :heading="$heading" :lede="$lede" :preheader="$lede" :footer="$footer" :from="$tenant->name">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr><td style="padding:24px 0 0;">
            @include('mail.parts.action', ['url' => $bookUrl, 'label' => 'Book again'])
        </td></tr>
    </table>
</x-mail-layout>
