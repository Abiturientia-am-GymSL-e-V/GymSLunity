import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            clubName: string | null;
            logoUrl: string | null;
            auth: Auth;
            can: {
                viewMembers: boolean;
                createMembers: boolean;
                manageConfiguration: boolean;
                viewPayments: boolean;
                viewStatistics: boolean;
                viewFinance: boolean;
                viewForms: boolean;
                viewDonations: boolean;
                viewInventory: boolean;
                viewCalendar: boolean;
                viewBookings: boolean;
                viewCommunication: boolean;
            };
            defaultCountry: string;
            formOfAddress: 'du' | 'sie';
            sidebarOpen: boolean;
            navigationBreadcrumb?: {
                title: string;
                href: string;
            };
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
        $address: (informal: string, formal: string) => string;
    }
}
