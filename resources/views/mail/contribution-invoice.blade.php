<p>Guten Tag {{ $contribution->account->member->first_name }} {{ $contribution->account->member->last_name }},</p>
<p>anbei erhalten Sie Ihre Beitragsrechnung {{ $contribution->invoice_number }}.</p>
<p>Freundliche Grüße<br>{{ \App\Models\ClubSetting::current()->data['name'] ?? config('app.name') }}</p>
