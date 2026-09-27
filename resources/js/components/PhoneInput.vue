<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import countries from '../../data/countries.json';

defineOptions({ inheritAttrs: false });
const model = defineModel<string>({ required: true });
const props = withDefaults(
    defineProps<{
        id: string;
        defaultCountry?: string;
        disabled?: boolean;
    }>(),
    {
        defaultCountry: 'DE',
        disabled: false,
    },
);

const codes: Record<string, string> = {
    AF: '+93',
    EG: '+20',
    AX: '+358',
    AL: '+355',
    DZ: '+213',
    AS: '+1684',
    VI: '+1340',
    AD: '+376',
    AO: '+244',
    AI: '+1264',
    AQ: '+672',
    AG: '+1268',
    GQ: '+240',
    AR: '+54',
    AM: '+374',
    AW: '+297',
    AZ: '+994',
    ET: '+251',
    AU: '+61',
    BS: '+1242',
    BH: '+973',
    BD: '+880',
    BB: '+1246',
    BY: '+375',
    BE: '+32',
    BZ: '+501',
    BJ: '+229',
    BM: '+1441',
    BT: '+975',
    BO: '+591',
    BA: '+387',
    BW: '+267',
    BV: '+47',
    BR: '+55',
    VG: '+1284',
    IO: '+246',
    BN: '+673',
    BG: '+359',
    BF: '+226',
    BI: '+257',
    CV: '+238',
    CL: '+56',
    CN: '+86',
    CK: '+682',
    CR: '+506',
    CI: '+225',
    CW: '+599',
    DK: '+45',
    DE: '+49',
    DM: '+1767',
    DO: '+1809',
    DJ: '+253',
    EC: '+593',
    SV: '+503',
    ER: '+291',
    EE: '+372',
    SZ: '+268',
    FK: '+500',
    FO: '+298',
    FJ: '+679',
    FI: '+358',
    FR: '+33',
    GF: '+594',
    PF: '+689',
    TF: '+262',
    GA: '+241',
    GM: '+220',
    GE: '+995',
    GH: '+233',
    GI: '+350',
    GD: '+1473',
    GR: '+30',
    GL: '+299',
    GP: '+590',
    GU: '+1671',
    GT: '+502',
    GG: '+44',
    GN: '+224',
    GW: '+245',
    GY: '+592',
    HT: '+509',
    HM: '+672',
    HN: '+504',
    IN: '+91',
    ID: '+62',
    IQ: '+964',
    IR: '+98',
    IE: '+353',
    IS: '+354',
    IM: '+44',
    IL: '+972',
    IT: '+39',
    JM: '+1876',
    JP: '+81',
    YE: '+967',
    JE: '+44',
    JO: '+962',
    KY: '+1345',
    KH: '+855',
    CM: '+237',
    CA: '+1',
    BQ: '+599',
    KZ: '+7',
    QA: '+974',
    KE: '+254',
    KG: '+996',
    KI: '+686',
    CC: '+61',
    CO: '+57',
    KM: '+269',
    CG: '+242',
    CD: '+243',
    HR: '+385',
    CU: '+53',
    KW: '+965',
    LA: '+856',
    LS: '+266',
    LV: '+371',
    LB: '+961',
    LR: '+231',
    LY: '+218',
    LI: '+423',
    LT: '+370',
    LU: '+352',
    MG: '+261',
    MW: '+265',
    MY: '+60',
    MV: '+960',
    ML: '+223',
    MT: '+356',
    MA: '+212',
    MH: '+692',
    MQ: '+596',
    MR: '+222',
    MU: '+230',
    YT: '+262',
    MX: '+52',
    FM: '+691',
    MC: '+377',
    MN: '+976',
    ME: '+382',
    MS: '+1664',
    MZ: '+258',
    MM: '+95',
    NA: '+264',
    NR: '+674',
    NP: '+977',
    NC: '+687',
    NZ: '+64',
    NI: '+505',
    NL: '+31',
    NE: '+227',
    NG: '+234',
    NU: '+683',
    KP: '+850',
    MP: '+1670',
    MK: '+389',
    NF: '+672',
    NO: '+47',
    OM: '+968',
    AT: '+43',
    PK: '+92',
    PS: '+970',
    PW: '+680',
    PA: '+507',
    PG: '+675',
    PY: '+595',
    PE: '+51',
    PH: '+63',
    PN: '+64',
    PL: '+48',
    PT: '+351',
    PR: '+1787',
    MD: '+373',
    RE: '+262',
    RW: '+250',
    RO: '+40',
    RU: '+7',
    SB: '+677',
    ZM: '+260',
    WS: '+685',
    SM: '+378',
    ST: '+239',
    SA: '+966',
    SE: '+46',
    CH: '+41',
    SN: '+221',
    RS: '+381',
    SC: '+248',
    SL: '+232',
    ZW: '+263',
    SG: '+65',
    SX: '+1721',
    SK: '+421',
    SI: '+386',
    SO: '+252',
    HK: '+852',
    MO: '+853',
    ES: '+34',
    SJ: '+47',
    LK: '+94',
    BL: '+590',
    SH: '+290',
    KN: '+1869',
    LC: '+1758',
    MF: '+590',
    PM: '+508',
    VC: '+1784',
    ZA: '+27',
    SD: '+249',
    GS: '+500',
    KR: '+82',
    SS: '+211',
    SR: '+597',
    SY: '+963',
    TJ: '+992',
    TW: '+886',
    TZ: '+255',
    TH: '+66',
    TL: '+670',
    TG: '+228',
    TK: '+690',
    TO: '+676',
    TT: '+1868',
    TD: '+235',
    CZ: '+420',
    TN: '+216',
    TR: '+90',
    TM: '+993',
    TC: '+1649',
    TV: '+688',
    UG: '+256',
    UA: '+380',
    HU: '+36',
    UY: '+598',
    UZ: '+998',
    VU: '+678',
    VA: '+39',
    VE: '+58',
    AE: '+971',
    US: '+1',
    GB: '+44',
    VN: '+84',
    WF: '+681',
    CX: '+61',
    EH: '+212',
    CF: '+236',
    CY: '+357',
};
const flag = (code: string) =>
    [...code]
        .map((letter) => String.fromCodePoint(letter.charCodeAt(0) + 127397))
        .join('');
const entries = Object.entries(codes)
    .map(([country, prefix]) => ({
        country,
        prefix,
        label: (countries as Record<string, string>)[country] ?? country,
    }))
    .sort((a, b) => a.label.localeCompare(b.label, 'de'));

function initialParts(value: string) {
    const trimmed = value.trim();
    const found = [...entries]
        .sort((a, b) => b.prefix.length - a.prefix.length)
        .find((entry) => trimmed.startsWith(entry.prefix));
    return {
        country: found?.country ?? props.defaultCountry,
        number: found ? trimmed.slice(found.prefix.length).trim() : trimmed,
    };
}
const initial = initialParts(model.value);
const country = ref(initial.country);
const number = ref(initial.number);
const selected = computed(
    () =>
        entries.find((entry) => entry.country === country.value) ?? entries[0],
);
const countryOptions = entries.map((entry) => ({
    value: entry.country,
    label: entry.label,
    icon: flag(entry.country),
    suffix: entry.prefix,
    search: `${entry.label} ${entry.country} ${entry.prefix}`,
}));

function nationalNumber(value: string) {
    return value.trim().replace(/^0+/, '');
}

function combinedValue() {
    const normalized = nationalNumber(number.value);

    return normalized ? `${selected.value.prefix} ${normalized}` : '';
}

function emitValue() {
    model.value = combinedValue();
}
function choose(value: string) {
    country.value = value;
    emitValue();
}
watch(number, emitValue);
watch(model, (value) => {
    if (value === combinedValue()) return;
    const parsed = initialParts(value);
    country.value = parsed.country;
    number.value = parsed.number;
});
</script>

<template>
    <div class="flex">
        <SearchableDropdown
            :id="`${id}-country-code`"
            root-class="shrink-0"
            :model-value="country"
            :options="countryOptions"
            :disabled="disabled"
            aria-label="Ländervorwahl auswählen"
            search-placeholder="Land oder Vorwahl suchen"
            empty-text="Kein Land oder keine Vorwahl gefunden"
            trigger-class="h-9 rounded-l-md border border-input border-r-0 bg-muted px-3 shadow-xs focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            dropdown-class="w-72 max-w-[calc(100vw-2rem)]"
            @update:model-value="choose"
        >
            <template #trigger="{ option }">
                <span aria-hidden="true">{{ option?.icon }}</span>
                <span>{{ option?.suffix }}</span>
            </template>
        </SearchableDropdown>
        <input
            v-bind="$attrs"
            :id="id"
            v-model="number"
            type="tel"
            autocomplete="tel-national"
            placeholder="Mobilnummer"
            :disabled="disabled"
            class="h-9 min-w-0 flex-1 rounded-r-md border border-input bg-background px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
        />
    </div>
</template>
