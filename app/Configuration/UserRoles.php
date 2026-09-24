<?php

namespace App\Configuration;

final class UserRoles
{
    public const LABELS = [
        'admin' => 'Administrator', 'vereinsverwaltung' => 'Vereinsverwaltung', 'mv' => 'Mitgliederverwaltung',
        'auditor' => 'Prüfer (Lesezugriff)', 'bh' => 'Buchhaltung', 'bv' => 'Beitragsverwaltung', 'kp' => 'Kassenprüfung',
    ];

    public const DESCRIPTIONS = [
        'admin' => 'Vollzugriff auf alle Verwaltungsbereiche.',
        'vereinsverwaltung' => 'Mitglieder und die allgemeine Vereinsorganisation verwalten.', 'mv' => 'Mitglieder lesen und bearbeiten.',
        'auditor' => 'Mitglieder, Dokumente und Änderungshistorie lesen.',
        'bh' => 'Finanzen und Spenden verwalten sowie Auswertungen einsehen.',
        'beitragsverwaltung' => 'Beiträge festlegen, einziehen und verbuchen.', 'bv' => 'Beiträge festlegen, einziehen und verbuchen.',
        'kp' => 'Buchhaltung und Auswertungen prüfen.',
    ];

    public const AREAS = [
        'admin' => ['Mitglieder', 'Beiträge', 'Auswertungen', 'Buchhaltung', 'Formulare', 'Spenden', 'Inventar', 'Kommunikation', 'Konfiguration'],
        'vereinsverwaltung' => ['Mitglieder', 'Auswertungen', 'Formulare', 'Inventar', 'Kommunikation'],
        'mv' => ['Mitglieder', 'Auswertungen', 'Formulare', 'Kommunikation'],
        'auditor' => ['Mitglieder (Lesen)', 'Auswertungen'],
        'bh' => ['Auswertungen', 'Buchhaltung', 'Spenden'],
        'bv' => ['Beiträge', 'Auswertungen'],
        'kp' => ['Auswertungen', 'Buchhaltung'],
    ];
}
