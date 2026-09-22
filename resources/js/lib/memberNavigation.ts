const prefix = 'gymslunity.members.position.v1:';

function key(url: string): string {
    const parsed = new URL(url, window.location.origin);
    parsed.searchParams.sort();
    return prefix + parsed.pathname + parsed.search;
}

export function rememberMemberList(): string {
    const url = window.location.pathname + window.location.search;
    const table = document.querySelector<HTMLElement>('[data-member-scroll]');
    try {
        sessionStorage.setItem(
            key(url),
            JSON.stringify({
                x: window.scrollX,
                y: window.scrollY,
                tableX: table?.scrollLeft ?? 0,
                tableY: table?.scrollTop ?? 0,
                savedAt: Date.now(),
            }),
        );
    } catch {
        /* Position restoration is optional when browser storage is disabled. */
    }
    return url;
}

export function restoreMemberList(): void {
    try {
        const position = JSON.parse(
            sessionStorage.getItem(key(window.location.href)) ?? 'null',
        );
        if (!position || Date.now() - position.savedAt > 60 * 60 * 1000) return;
        requestAnimationFrame(() => {
            const table = document.querySelector<HTMLElement>(
                '[data-member-scroll]',
            );
            table?.scrollTo(position.tableX, position.tableY);
            window.scrollTo(position.x, position.y);
        });
    } catch {
        /* Keep the default scroll position. */
    }
}
