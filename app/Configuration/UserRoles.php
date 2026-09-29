<?php

declare(strict_types=1);

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
        'bv' => 'Beiträge festlegen, einziehen und verbuchen.',
        'kp' => 'Buchhaltung und Auswertungen prüfen.',
    ];

    public const AREAS = [
        'admin' => ['Mitglieder', 'Beiträge', 'Auswertungen', 'Buchhaltung', 'Formulare', 'Spenden', 'Inventar', 'Kalender', 'Buchungen', 'Kommunikation', 'Auditlog', 'Konfiguration'],
        'vereinsverwaltung' => ['Mitglieder', 'Auswertungen', 'Formulare', 'Inventar', 'Kalender', 'Buchungen', 'Kommunikation'],
        'mv' => ['Mitglieder', 'Auswertungen', 'Formulare', 'Kalender', 'Buchungen', 'Kommunikation'],
        'auditor' => ['Mitglieder (Lesen)', 'Auswertungen', 'Auditlog'],
        'bh' => ['Auswertungen', 'Buchhaltung', 'Spenden'],
        'bv' => ['Beiträge', 'Auswertungen'],
        'kp' => ['Auswertungen', 'Buchhaltung', 'Auditlog'],
    ];
}
