#!/usr/bin/env node
// Erzeugt die Screenshots des Anwenderhandbuchs (docs/handbuch/bilder/) oder
// mit --website die hochauflösenden Bilder der Produktwebsite (website/).
//
// Erwartet eine laufende Demo-Instanz (DEMO_MODE=true, frisch mit
// `php artisan demo:reset` befüllt). Am einfachsten über
// scripts/handbuch-screenshots.sh, das eine Wegwerf-Instanz startet.
//
//   node scripts/handbuch-screenshots.mjs [--base URL] [--out DIR] [--only TEIL] [--website]
//
// --only beschränkt den Lauf auf Bilder, deren Dateiname TEIL enthält.
// --website nimmt die Bilder der Produktwebsite mit doppelter Pixeldichte auf,
// jeweils hell und dunkel.
// Playwright wird aus dem Projekt oder der globalen npm-Installation geladen.
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);
function loadPlaywright() {
    try {
        return require('playwright');
    } catch {
        const globalRoot = execSync('npm root -g', { encoding: 'utf8' }).trim();
        return require(path.join(globalRoot, 'playwright'));
    }
}
const { chromium } = loadPlaywright();

const args = process.argv.slice(2);
const option = (name, fallback) => {
    const index = args.indexOf(name);
    return index === -1 ? fallback : args[index + 1];
};
const base = option('--base', 'http://127.0.0.1:8123').replace(/\/$/, '');
const website = args.includes('--website');
const out = option(
    '--out',
    website ? 'website/assets/bilder' : 'docs/handbuch/bilder',
);
const defaultWidth = website ? 1440 : 1280;
const only = option('--only', '');
// Aufnahmen mit afterSetup brauchen Daten, die andere Bilder verändern würden,
// etwa ein zusätzliches Benutzerkonto. Sie laufen in einem zweiten Durchgang.
const afterSetup = args.includes('--after-setup');
const password = option('--password', 'Demo-Passwort-2026');

const ACCOUNTS = {
    admin: 'admin@example.org',
    vorstand: 'vorstand@example.org',
    zweifaktor: 'zweifaktor@example.org',
};
const MEMBER_EMAIL = 'mitglied@example.org';

// Demo-Hinweise ausblenden, Animationen abschalten, Cursor verstecken.
const STYLE = `
    [data-demo-hint] { display: none !important; }
    *, *::before, *::after { transition: none !important; animation: none !important; caret-color: transparent !important; }
`;

/**
 * Jede Aufnahme: file, as (Konto oder 'member' oder null), url,
 * optional run(page) für Klicks, clip (CSS-Selektor eines Elements),
 * full (ganze Seite), width/height (Viewport), dark.
 */
const shots = [
    // Erste Schritte
    { file: 'anmeldung.png', as: null, url: '/login', height: 760 },
    { file: 'startseite-oeffentlich.png', as: null, url: '/', height: 760 },
    {
        file: 'zwei-faktor-code.png',
        as: 'zweifaktor',
        url: '/two-factor-challenge',
        height: 760,
        afterSetup: true,
    },
    { file: 'uebersicht.png', as: 'admin', url: '/dashboard' },
    {
        file: 'benutzermenue.png',
        as: 'admin',
        url: '/dashboard',
        run: (p) =>
            p
                .getByRole('button', { name: /Demo-Administration/ })
                .last()
                .click(),
    },
    { file: 'profil.png', as: 'admin', url: '/settings/profile', full: true },
    {
        file: 'sicherheit.png',
        as: 'admin',
        url: '/settings/security',
        full: true,
    },
    {
        file: 'uebersicht-dunkel.png',
        as: 'admin',
        url: '/dashboard',
        dark: true,
    },
    {
        file: 'uebersicht-mobil.png',
        as: 'admin',
        url: '/dashboard',
        width: 390,
    },

    // Mitglieder
    { file: 'mitglieder-verzeichnis.png', as: 'admin', url: '/mitglieder' },
    {
        file: 'mitglieder-spalten.png',
        as: 'admin',
        url: '/mitglieder',
        run: (p) => p.getByRole('button', { name: 'Spalten' }).click(),
    },
    {
        file: 'mitglieder-auswahl.png',
        as: 'admin',
        url: '/mitglieder',
        run: async (p) => {
            const boxes = p.locator(
                'tbody input[type="checkbox"], tbody button[role="checkbox"]',
            );
            for (const i of [0, 1, 2]) await boxes.nth(i).click();
            await p.mouse.wheel(0, 250);
        },
    },
    { file: 'mitglied-akte.png', as: 'admin', url: '/mitglieder/1002' },
    {
        file: 'mitglied-akte-zuordnungen.png',
        as: 'admin',
        url: '/mitglieder/1002',
        clip: 'text=Abteilungen, Ämter & Ehrungen >> xpath=ancestor::*[contains(@class,"rounded")][1]',
    },
    {
        file: 'mitglied-bearbeiten.png',
        as: 'admin',
        url: '/mitglieder/1002',
        run: (p) =>
            p.getByRole('button', { name: 'Bearbeiten' }).first().click(),
    },
    { file: 'mitglied-anlegen.png', as: 'admin', url: '/mitglieder/anlegen' },
    {
        file: 'mitglieder-import.png',
        as: 'admin',
        url: '/mitglieder/importieren',
    },
    {
        file: 'mitglieder-zuordnungen-import.png',
        as: 'admin',
        url: '/mitglieder/importieren/zuordnungen',
    },
    {
        file: 'mitglieder-antraege.png',
        as: 'admin',
        url: '/mitglieder/antraege',
    },

    // Ämter, Abteilungen, Ehrungen
    { file: 'aemter-aktuell.png', as: 'admin', url: '/aemter' },
    { file: 'aemter-verlauf.png', as: 'admin', url: '/aemter/verlauf' },
    { file: 'abteilungen.png', as: 'admin', url: '/abteilungen' },
    { file: 'ehrungen-chronik.png', as: 'admin', url: '/ehrungen' },
    { file: 'ehrungen-jubilaeen.png', as: 'admin', url: '/ehrungen/jubilaeen' },

    // Beiträge
    { file: 'beitraege-uebersicht.png', as: 'admin', url: '/beitraege' },
    {
        file: 'beitraege-anlegen.png',
        as: 'admin',
        url: '/beitraege/anlegen',
        full: true,
    },
    {
        file: 'beitraege-rechnungen.png',
        as: 'admin',
        url: '/beitraege/rechnungen',
    },
    {
        file: 'beitraege-mahnwesen.png',
        as: 'admin',
        url: '/beitraege/mahnwesen',
    },
    {
        file: 'beitraege-sepa-export.png',
        as: 'admin',
        url: '/beitraege/sepa-export',
    },
    {
        file: 'beitraege-bankimport.png',
        as: 'admin',
        url: '/beitraege/bankimport',
    },
    {
        file: 'beitraege-manuell-buchen.png',
        as: 'admin',
        url: '/beitraege/manuell-buchen',
    },

    // Buchhaltung
    {
        file: 'buchhaltung-rechnungsbuch.png',
        as: 'admin',
        url: '/buchhaltung/rechnungen',
    },
    {
        file: 'buchhaltung-rechnung-anlegen.png',
        as: 'admin',
        url: '/buchhaltung/rechnungen/anlegen',
        full: true,
    },
    {
        file: 'buchhaltung-sepa-export.png',
        as: 'admin',
        url: '/buchhaltung/rechnungen/sepa-export',
    },

    // Spenden
    { file: 'spenden-uebersicht.png', as: 'admin', url: '/spenden' },
    {
        file: 'spenden-anlegen.png',
        as: 'admin',
        url: '/spenden/anlegen',
        full: true,
    },

    // Formulare
    { file: 'formulare.png', as: 'admin', url: '/formulare' },
    {
        file: 'quittung-anlegen.png',
        as: 'admin',
        url: '/formulare/quittungen/anlegen',
        full: true,
    },
    { file: 'quittungsbuch.png', as: 'admin', url: '/formulare/quittungen' },
    { file: 'sepa-mandate.png', as: 'admin', url: '/formulare/sepa-mandate' },
    {
        file: 'unterschriftslisten.png',
        as: 'admin',
        url: '/formulare/unterschriftslisten',
    },

    // Inventar
    { file: 'inventar.png', as: 'admin', url: '/inventar' },
    {
        file: 'inventar-anlegen.png',
        as: 'admin',
        url: '/inventar/inventarisieren',
        full: true,
    },
    { file: 'inventar-detail.png', as: 'admin', url: '/inventar/INV-000003' },

    // Kalender und Buchungen
    { file: 'kalender.png', as: 'admin', url: '/kalender' },
    {
        file: 'kalender-termin.png',
        as: 'admin',
        url: '/kalender',
        run: (p) => p.getByRole('button', { name: 'Termin anlegen' }).click(),
    },
    {
        file: 'kalender-verwalten.png',
        as: 'admin',
        url: '/kalender',
        run: (p) =>
            p
                .getByRole('button', { name: 'Kalender verwalten' })
                .first()
                .click(),
    },
    { file: 'buchungen-belegung.png', as: 'admin', url: '/buchungen' },
    { file: 'buchungen-anfragen.png', as: 'admin', url: '/buchungen/anfragen' },
    {
        file: 'buchungen-ressourcen.png',
        as: 'admin',
        url: '/buchungen/ressourcen',
    },
    {
        file: 'buchungen-ressource-anlegen.png',
        as: 'admin',
        url: '/buchungen/ressourcen/anlegen',
        full: true,
    },
    { file: 'buchungen-anlegen.png', as: 'admin', url: '/buchungen/anlegen' },

    // Kommunikation und Auswertungen
    {
        file: 'kommunikation-serienmail.png',
        as: 'admin',
        url: '/kommunikation/serienmails',
        full: true,
    },
    {
        file: 'kommunikation-serienbrief.png',
        as: 'admin',
        url: '/kommunikation/serienbriefe',
    },
    {
        file: 'kommunikation-verlauf.png',
        as: 'admin',
        url: '/kommunikation/verlauf',
    },
    {
        file: 'auswertungen-uebersicht.png',
        as: 'admin',
        url: '/auswertungen',
        full: true,
    },
    {
        file: 'auswertungen-mitglieder.png',
        as: 'admin',
        url: '/auswertungen/mitglieder',
    },
    {
        file: 'auswertungen-finanzen.png',
        as: 'admin',
        url: '/auswertungen/finanzen',
    },
    {
        file: 'auswertungen-datenqualitaet.png',
        as: 'admin',
        url: '/auswertungen/datenqualitaet',
    },
    { file: 'auditlog.png', as: 'admin', url: '/auditlog' },

    // Konfiguration
    {
        file: 'konfiguration-startseite.png',
        as: 'admin',
        url: '/konfiguration/startseite',
    },
    {
        file: 'konfiguration-verein.png',
        as: 'admin',
        url: '/konfiguration/verein',
    },
    {
        file: 'konfiguration-mitgliedsfelder.png',
        as: 'admin',
        url: '/konfiguration/mitgliedsfelder',
    },
    {
        file: 'konfiguration-mitgliedsfeld-bearbeiten.png',
        as: 'admin',
        url: '/konfiguration/mitgliedsfelder',
        run: (p) =>
            p.getByRole('button', { name: 'Bearbeiten' }).last().click(),
    },
    {
        file: 'konfiguration-benutzer.png',
        as: 'admin',
        url: '/konfiguration/benutzer',
    },
    {
        file: 'konfiguration-benutzer-anlegen.png',
        as: 'admin',
        url: '/konfiguration/benutzer',
        run: (p) => p.getByRole('button', { name: 'Benutzer anlegen' }).click(),
    },
    {
        file: 'konfiguration-module.png',
        as: 'admin',
        url: '/konfiguration/softwaremodule',
        full: true,
    },
    {
        file: 'konfiguration-email.png',
        as: 'admin',
        url: '/konfiguration/email',
    },
    {
        file: 'konfiguration-selfservice.png',
        as: 'admin',
        url: '/konfiguration/selfservice',
    },
    {
        file: 'konfiguration-spenden.png',
        as: 'admin',
        url: '/konfiguration/spenden',
    },
    {
        file: 'konfiguration-buchhaltung.png',
        as: 'admin',
        url: '/konfiguration/buchhaltung',
        full: true,
    },
    {
        file: 'konfiguration-system.png',
        as: 'admin',
        url: '/konfiguration/system',
    },

    // Rechte einer anderen Rolle
    { file: 'uebersicht-vorstand.png', as: 'vorstand', url: '/dashboard' },

    // Mitgliederbereich
    {
        file: 'mitgliederbereich-zugang.png',
        as: null,
        url: '/selfservice/zugang',
        height: 760,
    },
    {
        file: 'mitgliederbereich-beitritt.png',
        as: null,
        url: '/selfservice/mitglied-werden',
        height: 760,
    },
    { file: 'mitgliederbereich-portal.png', as: 'member', url: '/selfservice' },
    {
        file: 'mitgliederbereich-angaben.png',
        as: 'member',
        url: '/selfservice',
        clip: 'form:has(h2:has-text("Meine Angaben"))',
    },
    {
        file: 'mitgliederbereich-buchungen.png',
        as: 'member',
        url: '/selfservice/buchungen',
    },
    {
        file: 'mitgliederbereich-portal-mobil.png',
        as: 'member',
        url: '/selfservice',
        width: 390,
        height: 844,
    },
];

// Bilder der Produktwebsite: wenige, aussagekräftige Ansichten in 2x.
// Jede Aufnahme entsteht zusätzlich als dunkle Variante (Suffix -dunkel).
const websiteShots = [
    { file: 'uebersicht.png', as: 'admin', url: '/dashboard' },
    { file: 'mitglieder.png', as: 'admin', url: '/mitglieder' },
    { file: 'mitglied-akte.png', as: 'admin', url: '/mitglieder/1002' },
    { file: 'beitraege.png', as: 'admin', url: '/beitraege' },
    {
        file: 'buchhaltung.png',
        as: 'admin',
        url: '/buchhaltung/rechnungen',
    },
    { file: 'spenden.png', as: 'admin', url: '/spenden' },
    {
        file: 'kommunikation.png',
        as: 'admin',
        url: '/kommunikation/serienmails',
    },
    { file: 'kalender.png', as: 'admin', url: '/kalender' },
    {
        file: 'auswertungen.png',
        as: 'admin',
        url: '/auswertungen/mitglieder',
    },
    { file: 'formulare.png', as: 'admin', url: '/formulare/quittungen' },
    { file: 'inventar.png', as: 'admin', url: '/inventar' },
    { file: 'auditlog.png', as: 'admin', url: '/auditlog' },
    {
        file: 'module.png',
        as: 'admin',
        url: '/konfiguration/softwaremodule',
    },
    {
        file: 'portal-mobil.png',
        as: 'member',
        url: '/selfservice',
        width: 390,
        height: 844,
        scale: 3,
    },
].flatMap((shot) => [
    shot,
    { ...shot, file: shot.file.replace('.png', '-dunkel.png'), dark: true },
]);

async function settle(page) {
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

async function loginAdmin(page, email) {
    await page.goto(`${base}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL((url) => url.pathname !== '/login', { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function newestMailId(page) {
    await page.goto(
        `${base}/demo/postfach?empfaenger=${encodeURIComponent(MEMBER_EMAIL)}`,
        { waitUntil: 'networkidle' },
    );
    const href = await page.evaluate(
        () =>
            document.querySelector('a[href*="mail="]')?.getAttribute('href') ??
            null,
    );
    return href ? Number(new URL(href, base).searchParams.get('mail')) : 0;
}

async function loginMember(page) {
    const before = await newestMailId(page);
    await page.goto(`${base}/selfservice/zugang`, { waitUntil: 'networkidle' });
    await page.getByLabel('E-Mail-Adresse').fill(MEMBER_EMAIL);
    await page.getByRole('button', { name: 'E-Mail-Link anfordern' }).click();
    await settle(page);
    // Der Anmeldelink steht im Demo-Postfach; die Mail wird nach der Antwort verschickt.
    let id = 0;
    for (let attempt = 0; attempt < 20 && id <= before; attempt++) {
        await page.waitForTimeout(500);
        id = await newestMailId(page);
    }
    if (id <= before)
        throw new Error('Keine neue Anmeldemail im Demo-Postfach gefunden.');
    await page.goto(`${base}/demo/postfach/${id}/html`, {
        waitUntil: 'networkidle',
    });
    const link = await page.evaluate(() => {
        const text = document.body.innerHTML;
        const match = text.match(
            /https?:\/\/[^\s"'<>]*\/selfservice\/[^\s"'<>]*/g,
        );
        return match
            ? (match.find((url) => !url.endsWith('/selfservice/zugang')) ??
                  match[0])
            : null;
    });
    if (!link) throw new Error('Kein Anmeldelink in der Mail gefunden.');
    await page.goto(link.replaceAll('&amp;', '&'), {
        waitUntil: 'networkidle',
    });
    const confirm = page.getByRole('button', { name: 'Zugang bestätigen' });
    if (await confirm.isVisible().catch(() => false)) {
        await confirm.click();
        await page.waitForURL(
            (url) => !url.pathname.startsWith('/selfservice/zugang'),
            { timeout: 15000 },
        );
    }
}

(async () => {
    mkdirSync(out, { recursive: true });
    const browser = await chromium.launch({ args: ['--lang=de-DE'] });
    const contexts = new Map();
    let failures = 0;

    async function pageFor(shot) {
        const scale = shot.scale ?? (website ? 2 : 1);
        const key = `${shot.as ?? 'gast'}|${shot.width ?? defaultWidth}|${scale}|${shot.dark ? 'dunkel' : 'hell'}`;
        if (contexts.has(key)) return contexts.get(key);
        const context = await browser.newContext({
            viewport: { width: shot.width ?? defaultWidth, height: 900 },
            deviceScaleFactor: scale,
            colorScheme: shot.dark ? 'dark' : 'light',
            locale: 'de-DE',
            timezoneId: 'Europe/Berlin',
        });
        await context.addInitScript((css) => {
            document.addEventListener('DOMContentLoaded', () => {
                const style = document.createElement('style');
                style.textContent = css;
                document.head.append(style);
            });
        }, STYLE);
        const page = await context.newPage();
        page.on('pageerror', (error) =>
            console.log(`[pageerror] ${error.message}`),
        );
        if (shot.as === 'member') await loginMember(page);
        else if (shot.as) await loginAdmin(page, ACCOUNTS[shot.as]);
        contexts.set(key, page);
        return page;
    }

    for (const shot of website ? websiteShots : shots) {
        if (only && !shot.file.includes(only)) continue;
        if (Boolean(shot.afterSetup) !== afterSetup) continue;
        try {
            const page = await pageFor(shot);
            await page.setViewportSize({
                width: shot.width ?? defaultWidth,
                height: shot.height ?? 900,
            });
            await page.goto(`${base}${shot.url}`, { waitUntil: 'networkidle' });
            await settle(page);
            if (shot.run) {
                await shot.run(page);
                await settle(page);
            }
            const file = path.join(out, shot.file);
            if (shot.clip) {
                await page
                    .locator(shot.clip)
                    .first()
                    .screenshot({ path: file });
            } else {
                await page.screenshot({
                    path: file,
                    fullPage: shot.full ?? false,
                });
            }
            console.log(`[ok] ${shot.file}`);
        } catch (error) {
            failures++;
            console.log(
                `[fehler] ${shot.file}: ${error.message.split('\n')[0]}`,
            );
        }
    }
    await browser.close();
    process.exit(failures ? 1 : 0);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
