<p>Guten Tag,</p>
<p>im Anhang erhältst du {{ $edition === 'original' ? 'das Original' : 'die Kopie' }} der Quittung {{ $receipt->receipt_number }}.</p>
<p>Bei Rückfragen wende dich bitte an {{ $receipt->snapshot['club']['name'] ?? 'den Verein' }} ({{ $receipt->snapshot['club']['email'] ?? '' }}).</p>
