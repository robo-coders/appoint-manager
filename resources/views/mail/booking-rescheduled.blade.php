<x-mail-layout :subject="$subject" :heading="$heading" :lede="$lede" :preheader="$preheader" :footer="$footer" :from="$tenant->name">
    @include('mail.parts.rows', ['rows' => $rows])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr><td style="padding:24px 0 0;">
            @include('mail.parts.action', ['url' => $manageUrl, 'label' => 'Change or cancel'])
        </td></tr>
    </table>
</x-mail-layout>
