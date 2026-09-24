<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profil',
        href: editProfile(),
    },
    {
        title: 'Sicherheit',
        href: editSecurity(),
    },
    {
        title: 'Erscheinungsbild',
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <Heading
            as="h1"
            title="Einstellungen"
            description="Verwalte dein Profil und deine Kontoeinstellungen"
        />

        <div class="space-y-6">
            <aside class="w-full">
                <nav
                    class="flex flex-wrap gap-2 border-b pb-4"
                    aria-label="Einstellungen"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'justify-start rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground',
                            {
                                'bg-muted text-foreground':
                                    isCurrentOrParentUrl(item.href),
                            },
                        ]"
                        as-child
                    >
                        <Link
                            :href="item.href"
                            :aria-current="
                                isCurrentOrParentUrl(item.href)
                                    ? 'page'
                                    : undefined
                            "
                        >
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>
            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
