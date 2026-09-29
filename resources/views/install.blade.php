<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Administratorkonto – GymSLunity</title>
    <style nonce="{{ Vite::cspNonce() }}">
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; background: #f5f5f4; color: #1c1917; }
        main { width: min(620px, calc(100% - 2rem)); margin: 3rem auto; padding: 2rem; box-sizing: border-box; border: 1px solid #d6d3d1; border-radius: .75rem; background: white; }
        h1 { margin-top: 0; } form, label { display: grid; gap: .75rem; } label { gap: .4rem; font-size: .9rem; }
        input, button { box-sizing: border-box; min-height: 2.5rem; border: 1px solid #a8a29e; border-radius: .4rem; padding: .55rem .7rem; font: inherit; background: white; color: inherit; }
        button { border-color: #1c1917; background: #1c1917; color: white; font-weight: 600; cursor: pointer; }
        .errors { border: 1px solid #dc2626; border-radius: .4rem; padding: .75rem 1rem; color: #991b1b; background: #fef2f2; }
        .check { display: flex; align-items: flex-start; } .check input { min-height: auto; margin-top: .25rem; } small { color: #57534e; }
    </style>
</head>
<body>
<main>
    <h1>Administratorkonto anlegen</h1>
    <p>Die Serverkonfiguration wurde gespeichert. Jetzt werden Datenbanktabellen und das erste Administratorkonto angelegt.</p>
    @if ($errors->any())
        <div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form method="post" action="{{ route('install.store') }}">
        @csrf
        <label>Einrichtungscode <input name="setup_token" required autocomplete="off" spellcheck="false" value="{{ old('setup_token') }}"><small>Derselbe Code wie im ersten Schritt, aus der Datei <code>storage/app/setup-token</code>.</small></label>
        <label>Name <input name="name" required maxlength="255" autocomplete="name" value="{{ old('name') }}"></label>
        <label>E-Mail-Adresse <input name="email" type="email" required maxlength="255" autocomplete="email" value="{{ old('email') }}"></label>
        <label>Passwort <input name="password" type="password" required maxlength="72" autocomplete="new-password"><small>Mindestens 12 Zeichen; Groß-/Kleinbuchstaben, Zahl und Sonderzeichen.</small></label>
        <label>Passwort wiederholen <input name="password_confirmation" type="password" required maxlength="72" autocomplete="new-password"></label>
        <label class="check"><input name="accept" type="checkbox" value="1" required> Ich bestätige, dass ich für sicheren Betrieb, Datenschutz, Backups und die Prüfung rechtlicher Textbausteine verantwortlich bin.</label>
        <button type="submit">Installation abschließen</button>
    </form>
</main>
</body>
</html>
