<?php

declare(strict_types=1);

return [
    'required' => ':attribute ist erforderlich.',
    'required_if' => ':attribute ist bei dieser Auswahl erforderlich.',
    'present' => ':attribute muss übermittelt werden.',
    'string' => ':attribute muss Text enthalten.',
    'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
    'unique' => ':attribute ist bereits vergeben.',
    'confirmed' => 'Die Bestätigung für :attribute stimmt nicht überein.',
    'current_password' => 'Das aktuelle Passwort ist nicht korrekt.',
    'min' => ['string' => ':attribute muss mindestens :min Zeichen enthalten.', 'numeric' => ':attribute muss mindestens :min sein.', 'array' => ':attribute muss mindestens :min Einträge enthalten.'],
    'max' => ['string' => ':attribute darf höchstens :max Zeichen enthalten.', 'numeric' => ':attribute darf höchstens :max sein.', 'array' => ':attribute darf höchstens :max Einträge enthalten.'],
    'between' => ['numeric' => ':attribute muss zwischen :min und :max liegen.'],
    'in' => 'Diese Auswahl für :attribute ist nicht zulässig.',
    'not_in' => 'Dieser Wert für :attribute ist nicht zulässig.',
    'array' => ':attribute muss eine Liste sein.',
    'boolean' => ':attribute muss Ja oder Nein sein.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'numeric' => ':attribute muss eine Zahl sein.',
    'decimal' => ':attribute enthält zu viele Nachkommastellen.',
    'date_format' => ':attribute muss ein gültiges Datum im Format :format sein.',
    'url' => ':attribute muss eine gültige Webadresse sein.',
    'distinct' => ':attribute enthält doppelte Werte.',
    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss Groß- und Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Ziffer enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => 'Dieses Passwort wurde in einem Datenleck gefunden. Bitte ein anderes wählen.',
    ],
    'attributes' => ['name' => 'Name', 'email' => 'E-Mail-Adresse', 'password' => 'Passwort', 'current_password' => 'Aktuelles Passwort', 'password_confirmation' => 'Passwortbestätigung', 'code' => 'Bestätigungscode', 'roles' => 'Rollen', 'version' => 'Versionsnummer'],
];
