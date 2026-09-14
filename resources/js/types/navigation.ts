import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavSubItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    /** Shown as a tooltip, e.g. the project code. */
    hint?: string;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Renders a collapsible sub-menu under the item. */
    items?: NavSubItem[];
};

/** Shared on every page for the sidebar's "Projects" sub-menu. */
export type SidebarProject = {
    id: number;
    code: string;
    name: string;
};
