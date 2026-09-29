import { router } from '@inertiajs/vue3';
import { confirm } from '@/routes/password';

/** Laravel's XSRF token from the cookie, for requests made with fetch(). */
export function xsrfToken(): string {
    const token = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return decodeURIComponent(token || '');
}

/** Offer a blob to the browser as a file download. */
export function saveBlob(blob: Blob, filename: string): void {
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

type DownloadOptions = {
    /** Accept header, e.g. "application/pdf, application/json". */
    accept: string;
    /** Validation error keys whose first message is shown, in order. */
    errorKeys: string[];
    /** Message when the server gives no validation message. */
    fallback: string;
};

/**
 * POST JSON and save the response as a file. Throws an Error with a
 * user-facing message if the request fails.
 */
export async function postDownload(
    url: string,
    body: unknown,
    filename: string,
    options: DownloadOptions,
): Promise<void> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: options.accept,
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });
    if (response.status === 423) {
        // The export needs a fresh password confirmation. The server sends
        // the user back to this page afterwards.
        router.visit(confirm());
        throw new Error(
            'Bitte zuerst das Passwort bestätigen und den Export danach erneut starten.',
        );
    }
    if (!response.ok || response.redirected) {
        const failure =
            response.status === 422
                ? ((await response.json()) as {
                      errors?: Record<string, string[]>;
                  })
                : null;
        const message = options.errorKeys
            .map((key) => failure?.errors?.[key]?.[0])
            .find(Boolean);
        throw new Error(message || options.fallback);
    }
    saveBlob(await response.blob(), filename);
}
