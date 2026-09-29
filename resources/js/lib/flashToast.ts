import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        toast[data.type](data.message);
    });

    // Validation errors are shown next to their fields where a form has
    // them; this makes sure none stays invisible, e.g. for nested keys.
    router.on('error', (event) => {
        const errors = (event as CustomEvent).detail?.errors as
            | Record<string, string>
            | undefined;
        const first = errors ? Object.values(errors).find(Boolean) : undefined;
        if (first) {
            toast.error('Bitte die Eingaben prüfen.', { description: first });
        }
    });
}
