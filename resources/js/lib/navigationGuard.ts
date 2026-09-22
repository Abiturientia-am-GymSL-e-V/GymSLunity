let guard: ((event: PopStateEvent) => void) | undefined;

// Register before Inertia initializes its history listener. A component-level
// listener runs too late to stop Inertia from restoring a cached page.
export function initializeNavigationGuard(): void {
    if (typeof window !== 'undefined') {
        window.addEventListener('popstate', (event) => guard?.(event), true);
    }
}

export function onBeforeHistoryNavigation(
    callback: (event: PopStateEvent) => void,
): () => void {
    guard = callback;
    return () => {
        if (guard === callback) guard = undefined;
    };
}
