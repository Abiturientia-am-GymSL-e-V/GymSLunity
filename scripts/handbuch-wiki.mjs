#!/usr/bin/env node
// Überträgt das Anwenderhandbuch aus docs/handbuch/ in ein ausgechecktes
// GitHub-Wiki-Repository.
//
//   node scripts/handbuch-wiki.mjs ZIELVERZEICHNIS [OWNER/REPO]
//
// Die Seitennamen im Wiki ergeben sich aus der ersten Überschrift jeder
// Datei, README.md wird zur Startseite Home. Links auf andere .md-Dateien
// werden auf Wiki-Seiten umgeschrieben, Bilder auf ihre Adresse im Wiki-
// Repository. Alle übrigen Seiten und Bilder im Ziel werden ersetzt, damit
// das Wiki immer genau dem Stand im Repository entspricht.
import {
    copyFileSync,
    existsSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import path from 'node:path';

const source = 'docs/handbuch';
const target = process.argv[2];
const repository =
    process.argv[3] ??
    process.env.GITHUB_REPOSITORY ??
    'Abiturientia-am-GymSL-e-V/GymSLunity';
if (!target || !existsSync(path.join(target, '.git'))) {
    console.error(
        'Aufruf: node scripts/handbuch-wiki.mjs ZIELVERZEICHNIS [OWNER/REPO] (Ziel muss ein Git-Checkout sein)',
    );
    process.exit(2);
}

const imageBase = `https://raw.githubusercontent.com/wiki/${repository}/bilder/`;
const files = readdirSync(source).filter((file) => file.endsWith('.md'));

/** Wiki-Seitenname: erste Überschrift ohne Satzzeichen, Leerzeichen als Bindestrich. */
function pageName(file) {
    if (file === 'README.md') return 'Home';
    if (file.startsWith('_')) return file.slice(0, -3);
    const heading = readFileSync(path.join(source, file), 'utf8').match(
        /^# (.+)$/m,
    );
    if (!heading)
        throw new Error(`${file} hat keine Überschrift erster Ebene.`);
    return heading[1]
        .replace(/[^\p{L}\p{N} -]/gu, '')
        .trim()
        .replace(/ +/g, '-');
}

const pages = new Map(files.map((file) => [file, pageName(file)]));

function convert(file, text) {
    let result = text;
    // Das Wiki zeigt den Seitennamen bereits als Titel.
    if (file !== 'README.md') result = result.replace(/^# .+\n+/, '');
    result = result.replace(
        /\]\(([\w-]+\.md)(#[^)]*)?\)/g,
        (match, link, anchor = '') => {
            if (!pages.has(link))
                throw new Error(`${file}: Link auf unbekannte Seite ${link}`);
            return `](${pages.get(link)}${anchor})`;
        },
    );
    result = result.replace(/(\]\(|src=")bilder\//g, `$1${imageBase}`);
    return result;
}

for (const entry of readdirSync(target)) {
    if (entry === '.git') continue;
    rmSync(path.join(target, entry), { recursive: true, force: true });
}

for (const [file, name] of pages) {
    writeFileSync(
        path.join(target, `${name}.md`),
        convert(file, readFileSync(path.join(source, file), 'utf8')),
    );
}
writeFileSync(
    path.join(target, '_Footer.md'),
    `Dieses Wiki wird automatisch aus [docs/handbuch](https://github.com/${repository}/tree/main/docs/handbuch) erzeugt. Änderungen bitte dort per Pull Request vorschlagen.\n`,
);

mkdirSync(path.join(target, 'bilder'));
for (const image of readdirSync(path.join(source, 'bilder'))) {
    copyFileSync(
        path.join(source, 'bilder', image),
        path.join(target, 'bilder', image),
    );
}

console.log(
    `${pages.size} Seiten und ${readdirSync(path.join(target, 'bilder')).length} Bilder nach ${target} übertragen.`,
);
