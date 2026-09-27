<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="$subjectLine"
    :preheader="$messageText"
>
<div style="white-space:pre-line">{{ $messageText }}</div>
</x-mail.layout>
