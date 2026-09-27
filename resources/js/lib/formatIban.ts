export function formatIban(value: string | null | undefined): string {
    return (value ?? '')
        .replace(/\s+/g, '')
        .toUpperCase()
        .replace(/(.{4})/g, '$1 ')
        .trim();
}
