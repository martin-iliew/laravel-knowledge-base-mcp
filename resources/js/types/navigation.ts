import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type AppHref = NonNullable<InertiaLinkProps['href']>;

export type BreadcrumbItem = {
    title: string;
    href: AppHref;
};

export type NavItem = {
    title: string;
    href: AppHref;
    icon?: LucideIcon | null;
    isActive?: boolean;
};
