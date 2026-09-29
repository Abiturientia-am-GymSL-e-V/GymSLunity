/**
 * First validation message for a field or any nested key below it, e.g.
 * firstError(form.errors, 'filters.0') also finds "filters.0.value".
 */
export function firstError(errors: object, prefix: string): string | undefined {
    const messages = errors as Record<string, string | undefined>;
    if (messages[prefix]) {
        return messages[prefix];
    }

    return Object.entries(messages).find(
        ([key, message]) => key.startsWith(`${prefix}.`) && message,
    )?.[1];
}
