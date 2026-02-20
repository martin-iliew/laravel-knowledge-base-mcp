import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

type AppLogoVariant = 'sidebar' | 'header' | 'auth';

type AppLogoProps = {
    className?: string;
    variant?: AppLogoVariant;
};

const fallbackWordmark = 'Knowledge Base MCP';

function resolveWordmark(name: unknown): string {
    if (typeof name !== 'string') {
        return fallbackWordmark;
    }

    const trimmedName = name.trim();

    if (trimmedName.length === 0 || trimmedName.toLowerCase() === 'laravel') {
        return fallbackWordmark;
    }

    return trimmedName;
}

export default function AppLogo({ className, variant = 'sidebar' }: AppLogoProps) {
    const { name } = usePage().props;
    const wordmark = resolveWordmark(name);
    const words = wordmark.split(/\s+/);
    const primaryLine = words.slice(0, 2).join(' ');
    const secondaryLine = words.slice(2).join(' ') || 'Knowledge Workspace';
    const compactWordmark = words
        .map((word) => word.slice(0, 1).toUpperCase())
        .join('')
        .slice(0, 4);

    if (variant === 'auth') {
        return (
            <div className={cn('inline-flex flex-col items-center gap-1 text-center', className)}>
                <span className="text-[0.66rem] font-medium tracking-[0.28em] text-muted-foreground uppercase">
                    {secondaryLine}
                </span>
                <span className="text-lg font-semibold tracking-tight text-foreground">
                    {primaryLine}
                </span>
            </div>
        );
    }

    if (variant === 'header') {
        return (
            <div className={cn('inline-flex flex-col gap-0.5', className)}>
                <span className="text-[0.62rem] font-medium tracking-[0.22em] text-muted-foreground uppercase">
                    {secondaryLine}
                </span>
                <span className="text-sm font-semibold tracking-tight text-foreground">
                    {primaryLine}
                </span>
            </div>
        );
    }

    return (
        <div className={cn('flex min-w-0 items-center group-data-[collapsible=icon]:justify-center', className)}>
            <span className="hidden h-8 min-w-8 items-center justify-center rounded-md border border-sidebar-border/70 bg-sidebar-accent/50 px-1 text-[0.62rem] font-semibold tracking-[0.22em] text-sidebar-foreground uppercase group-data-[collapsible=icon]:inline-flex">
                {compactWordmark}
            </span>
            <div className="ml-1 grid min-w-0 flex-1 text-left leading-tight group-data-[collapsible=icon]:hidden">
                <span className="truncate text-[0.62rem] font-medium tracking-[0.2em] text-sidebar-foreground/70 uppercase">
                    {secondaryLine}
                </span>
                <span className="truncate text-sm font-semibold tracking-tight text-sidebar-foreground">
                    {primaryLine}
                </span>
            </div>
        </div>
    );
}
