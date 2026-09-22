<?php

namespace App\Configuration;

final class UserRoles
{
    public const LABELS = [
        'admin' => 'Administrator', 'vereinsverwaltung' => 'Vereinsverwaltung', 'mv' => 'Mitgliederverwaltung',
        'auditor' => 'Prüfer (Lesezugriff)', 'bh' => 'Buchhaltung', 'bv' => 'Beitragsverwaltung', 'kp' => 'Kassenprüfung',
    ];

    public const DESCRIPTIONS = [
        'admin' => 'Mitglieder lesen und bearbeiten, Vereinsdaten, Felder und Benutzer verwalten.',
        'vereinsverwaltung' => 'Mitglieder lesen und bearbeiten.', 'mv' => 'Mitglieder lesen und bearbeiten.',
        'auditor' => 'Mitglieder, Dokumente und Änderungshistorie lesen.',
        'bh' => 'Bestehende Fachrolle; derzeit ohne eigenen freigeschalteten Bereich.',
        'beitragsverwaltung' => 'Beiträge festlegen, einziehen, als bezahlt markieren, etc.', 'bv' => 'Beiträge festlegen, einziehen, als bezahlt markieren, etc.',
        'kp' => 'Bestehende Fachrolle; derzeit ohne eigenen freigeschalteten Bereich.',
    ];
}
