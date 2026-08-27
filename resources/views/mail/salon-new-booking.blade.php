<x-mail-layout :subject="$subject" :heading="$heading" :lede="$lede" :preheader="$preheader" :footer="$footer">
    @include('mail.parts.rows', ['rows' => $rows])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr><td style="padding:24px 0 0;">
            @include('mail.parts.action', ['url' => $diaryUrl, 'label' => 'Open the diary'])
        </td></tr>
    </table>
</x-mail-layout>
