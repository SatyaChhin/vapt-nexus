<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { currentUrl, isCurrentUrl } = useCurrentUrl();

/**
 * The page itself or one of its children, e.g. /projects/1/scans/3 for
 * /projects/1, but not /projects/11.
 */
function isWithin(href: NonNullable<InertiaLinkProps['href']>): boolean {
    const path = new URL(toUrl(href), 'http://localhost').pathname;

    return currentUrl.value === path || currentUrl.value.startsWith(`${path}/`);
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarMenu>
            <template v-for="item in items" :key="item.title">
                <Collapsible
                    v-if="item.items?.length"
                    as-child
                    :default-open="true"
                    class="group/collapsible"
                >
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentUrl(item.href)"
                            :tooltip="item.title"
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuAction
                                class="transition-transform group-data-[state=open]/collapsible:rotate-90"
                            >
                                <ChevronRight />
                                <span class="sr-only">
                                    Show or hide {{ item.title }}
                                </span>
                            </SidebarMenuAction>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                <SidebarMenuSubItem
                                    v-for="sub in item.items"
                                    :key="toUrl(sub.href)"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="isWithin(sub.href)"
                                    >
                                        <Link
                                            :href="sub.href"
                                            :title="
                                                sub.hint
                                                    ? `${sub.title} (${sub.hint})`
                                                    : sub.title
                                            "
                                        >
                                            <component
                                                :is="sub.icon"
                                                v-if="sub.icon"
                                            />
                                            <span>{{ sub.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>

                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :is-active="isCurrentUrl(item.href)"
                        :tooltip="item.title"
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
