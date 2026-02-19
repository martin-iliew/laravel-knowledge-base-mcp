import { Head, Link } from '@inertiajs/react';
import { BookOpenText, KeyRound, Plus, ShieldCheck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { show as knowledgeBaseShow } from '@/routes/knowledge-base';
import { create as createKnowledgeItem } from '@/routes/knowledge-items';
import settings from '@/routes/settings';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];

type DashboardSummary = {
    total_items: number;
    draft_items: number;
    published_items: number;
    archived_items: number;
};

type DashboardRecentItem = {
    id: number;
    title: string;
    category: string | null;
    updated_at: string | null;
};

type DashboardAccount = {
    user_name: string;
    user_email: string;
    app_name: string;
};

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Unknown';
    }

    return new Date(value).toLocaleString();
}

export default function Dashboard({
    summary,
    recentItems,
    account,
}: {
    summary: DashboardSummary;
    recentItems: DashboardRecentItem[];
    account: DashboardAccount;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <section className="rounded-2xl border bg-gradient-to-br from-neutral-50 via-white to-neutral-100 p-6 dark:from-neutral-950 dark:via-neutral-900 dark:to-neutral-950">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="space-y-2">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                {account.app_name}
                            </p>
                            <h1 className="text-3xl font-semibold tracking-tight">Knowledge Base Dashboard</h1>
                            <p className="text-sm text-muted-foreground">
                                Signed in as {account.user_name} ({account.user_email}).
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="outline">
                                <Link href={settings.knowledgeAccess.index()}>
                                    <ShieldCheck className="mr-2 h-4 w-4" />
                                    Grant Access
                                </Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={settings.mcpToken.index()}>
                                    <KeyRound className="mr-2 h-4 w-4" />
                                    MCP Tokens
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href={createKnowledgeItem()}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    New Knowledge Item
                                </Link>
                            </Button>
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Total Articles</CardDescription>
                            <CardTitle className="text-3xl">{summary.total_items}</CardTitle>
                        </CardHeader>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Draft</CardDescription>
                            <CardTitle className="text-3xl">{summary.draft_items}</CardTitle>
                        </CardHeader>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Live Items</CardDescription>
                            <CardTitle className="text-3xl">{summary.published_items}</CardTitle>
                        </CardHeader>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Archived</CardDescription>
                            <CardTitle className="text-3xl">{summary.archived_items}</CardTitle>
                        </CardHeader>
                    </Card>
                </section>

                <section className="grid gap-4 ">
                    <Card>
                        <CardHeader>
                            <CardTitle>Recently Updated</CardTitle>
                            <CardDescription>Latest items available in your access scope.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {recentItems.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No knowledge items yet.</p>
                            ) : (
                                <div className="space-y-3">
                                    {recentItems.map((item) => (
                                        <div
                                            key={item.id}
                                            className="flex flex-col gap-2 rounded-lg border p-3 md:flex-row md:items-center md:justify-between"
                                        >
                                            <div className="space-y-1">
                                                <p className="font-medium leading-tight">{item.title}</p>
                                                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                    {item.category ? <Badge variant="outline">{item.category}</Badge> : null}
                                                    <span>Updated {formatDateTime(item.updated_at)}</span>
                                                </div>
                                            </div>

                                            <Button asChild size="sm" variant="outline">
                                                <Link href={knowledgeBaseShow(item.id)}>
                                                    <BookOpenText className="mr-2 h-4 w-4" />
                                                    Open
                                                </Link>
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </section>
            </div>
        </AppLayout>
    );
}
