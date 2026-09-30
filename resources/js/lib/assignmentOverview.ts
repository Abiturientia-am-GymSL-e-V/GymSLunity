import { router } from '@inertiajs/vue3';

type QueryValue = string | number | boolean | null | undefined;

/** URL query without empty values; true becomes "1", false is dropped. */
export function compactQuery(
    values: Record<string, QueryValue>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(values)
            .filter(
                ([, value]) =>
                    value !== '' &&
                    value !== null &&
                    value !== undefined &&
                    value !== false,
            )
            .map(([key, value]) => [key, value === true ? '1' : String(value)]),
    );
}

export function withQuery(path: string, values: Record<string, QueryValue>) {
    const query = new URLSearchParams(compactQuery(values)).toString();

    return query ? `${path}?${query}` : path;
}

/** Applies overview filters while keeping them in the URL. */
export function applyOverviewFilters(
    path: string,
    values: Record<string, QueryValue>,
) {
    router.get(path, compactQuery(values), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}
