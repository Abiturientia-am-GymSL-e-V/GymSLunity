<?php

namespace App\Donations;

final class DonationPurposes
{
    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            '52-1' => 'Förderung von Wissenschaft und Forschung',
            '52-2' => 'Förderung der Religion',
            '52-3' => 'Förderung des öffentlichen Gesundheitswesens und der öffentlichen Gesundheitspflege, insbesondere die Verhütung und Bekämpfung von übertragbaren Krankheiten und von Tierseuchen',
            '52-4' => 'Förderung der Jugend- und Altenhilfe',
            '52-5' => 'Förderung von Kunst und Kultur',
            '52-6' => 'Förderung des Denkmalschutzes und der Denkmalpflege',
            '52-7' => 'Förderung der Erziehung, Volks- und Berufsbildung einschließlich der Studentenhilfe',
            '52-8' => 'Förderung des Naturschutzes und der Landschaftspflege, des Umweltschutzes einschließlich des Klimaschutzes, des Küstenschutzes und des Hochwasserschutzes',
            '52-9' => 'Förderung des Wohlfahrtswesens',
            '52-10' => 'Förderung der Hilfe für politisch, rassistisch oder religiös Verfolgte, für Flüchtlinge, Vertriebene, Aussiedler, Spätaussiedler, Kriegsopfer, Kriegshinterbliebene, Kriegsbeschädigte und Kriegsgefangene, Zivilbeschädigte und Behinderte sowie Hilfe für Opfer von Straftaten; Förderung des Andenkens an Verfolgte, Kriegs- und Katastrophenopfer; Förderung des Suchdienstes für Vermisste; Förderung der Hilfe für Menschen, die auf Grund ihrer geschlechtlichen Identität oder ihrer geschlechtlichen Orientierung diskriminiert werden',
            '52-11' => 'Förderung der Rettung aus Lebensgefahr',
            '52-12' => 'Förderung des Feuer-, Arbeits-, Katastrophen- und Zivilschutzes sowie der Unfallverhütung',
            '52-13' => 'Förderung internationaler Gesinnung, der Toleranz und des Völkerverständigungsgedankens',
            '52-14' => 'Förderung des Tierschutzes',
            '52-15' => 'Förderung der Entwicklungszusammenarbeit',
            '52-16' => 'Förderung von Verbraucherberatung und Verbraucherschutz',
            '52-17' => 'Förderung der Fürsorge für Strafgefangene und ehemalige Strafgefangene',
            '52-18' => 'Förderung der Gleichberechtigung von Frauen und Männern',
            '52-19' => 'Förderung des Schutzes von Ehe und Familie',
            '52-20' => 'Förderung der Kriminalprävention',
            '52-21' => 'Förderung des Sports (einschließlich Schach und E-Sport)',
            '52-22' => 'Förderung der Heimatpflege, Heimatkunde und der Ortsverschönerung',
            '52-23' => 'Förderung der Tierzucht, der Pflanzenzucht, der Kleingärtnerei, des traditionellen Brauchtums einschließlich des Karnevals, der Fastnacht und des Faschings, der Soldaten- und Reservistenbetreuung, des Amateurfunkens, des Freifunks, des Modellflugs und des Hundesports',
            '52-24' => 'Allgemeine Förderung des demokratischen Staatswesens',
            '52-25' => 'Förderung des bürgerschaftlichen Engagements zugunsten gemeinnütziger, mildtätiger und kirchlicher Zwecke',
            '52-26' => 'Förderung der Unterhaltung und Pflege von Friedhöfen und Gedenkstätten für nichtbestattungspflichtige Kinder und Föten',
            '52-27' => 'Förderung wohngemeinnütziger Zwecke',
            '53-0' => 'Förderung mildtätiger Zwecke (§ 53 AO)',
            '54-0' => 'Förderung kirchlicher Zwecke (§ 54 AO)',
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function forFrontend(): array
    {
        return array_map(
            fn (string $value, string $label): array => compact('value', 'label'),
            array_keys(self::options()),
            array_values(self::options()),
        );
    }
}
