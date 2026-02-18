import { Head, Link } from '@inertiajs/react';
import { ExternalLink, FileText, Link2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import HighlightCodeBlock from '@/components/highlight-code-block';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { highlightMarkdownCodeBlocks } from '@/lib/highlight';
import type { BreadcrumbItem } from '@/types';

type ReaderTab = 'content' | 'code-examples' | 'resources';

function formatDate(value: string | null): string {
    if (!value) {
        return 'Unknown';
    }

    return new Date(value).toLocaleString();
}

export default function KnowledgeReader({
    item,
    codeExamples,
    resources,
    permissions,
}: {
    item: {
        id: number;
        slug: string;
        title: string;
        content_html: string;
        category: string | null;
        tags: string[];
        status: 'draft' | 'published' | 'archived';
        published_at: string | null;
        updated_at: string | null;
        owner: {
            id: number | null;
            name: string | null;
            email: string | null;
        };
    };
    codeExamples: Array<{
        id: number;
        sort_order: number;
        title: string | null;
        language: string;
        filename: string | null;
        description: string | null;
        code: string;
        updated_at: string | null;
    }>;
    resources: Array<{
        id: number;
        sort_order: number;
        type: 'link' | 'file';
        label: string | null;
        url: string | null;
        storage_path: string | null;
        mime: string | null;
        size: number | null;
        extracted_text: string | null;
        extracted_at: string | null;
        extract_attempts: number;
        extract_error: string | null;
        updated_at: string | null;
    }>;
    permissions: {
        can_update: boolean;
        can_delete: boolean;
        access_level: 'owner' | 'viewer' | 'editor';
    };
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Knowledge Base',
            href: '/knowledge-base',
        },
        {
            title: item.title,
            href: `/knowledge-base/${item.id}`,
        },
    ];

    const [activeTab, setActiveTab] = useState<ReaderTab>('content');
    const articleRef = useRef<HTMLElement | null>(null);
    const authorName = item.owner.name ?? item.owner.email ?? 'Unknown';
    const authorInitial = authorName.slice(0, 1).toUpperCase();

    useEffect(() => {
        if (activeTab !== 'content') {
            return;
        }

        highlightMarkdownCodeBlocks(articleRef.current);
    }, [activeTab, item.content_html]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={item.title} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <Card className="overflow-hidden rounded-3xl border border-neutral-200/70 bg-gradient-to-br from-neutral-50 via-neutral-100 to-neutral-200/70 dark:from-neutral-950 dark:via-neutral-900 dark:to-neutral-950 shadow-sm dark:border-neutral-800/70 dark:bg-neutral-950/70">
                    <CardHeader className="relative space-y-5">
                        <div className="pointer-events-none absolute -top-16 -right-20 h-40 w-40 rounded-full bg-neutral-400/15 blur-3xl dark:bg-neutral-500/20" />
                        <div className="relative flex flex-wrap items-start justify-between gap-4">
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        variant={
                                            item.status === 'published'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {item.status}
                                    </Badge>
                                    <Badge variant="outline">
                                        {permissions.access_level}
                                    </Badge>
                                    {item.category ? (
                                        <Badge variant="outline">{item.category}</Badge>
                                    ) : null}
                                </div>
                                <CardTitle className="text-3xl leading-tight">
                                    {item.title}
                                </CardTitle>
                                <CardDescription>
                                    Structured internal knowledge article
                                </CardDescription>
                            </div>
                            {permissions.can_update ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="border-neutral-400/50 bg-background/60 backdrop-blur"
                                >
                                    <Link href={`/knowledge-items/${item.id}/edit`}>Edit</Link>
                                </Button>
                            ) : null}
                        </div>
                        <div className="relative flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full border border-neutral-300 bg-background/80 text-sm font-semibold dark:border-neutral-700">
                                {authorInitial}
                            </div>
                            <div>
                                <p className="font-medium text-foreground">
                                    Written by {authorName}
                                </p>
                                <p>Last updated {formatDate(item.updated_at)}</p>
                            </div>
                        </div>
                        {item.tags.length > 0 ? (
                            <div className="relative flex flex-wrap gap-1.5">
                                {item.tags.map((tag) => (
                                    <Badge key={`${item.id}-${tag}`} variant="secondary">
                                        {tag}
                                    </Badge>
                                ))}
                            </div>
                        ) : null}
                    </CardHeader>
                </Card>

                <div className="flex flex-wrap gap-2 rounded-xl border bg-muted/25 p-1.5">
                    <button
                        type="button"
                        onClick={() => setActiveTab('content')}
                        className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                            activeTab === 'content'
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        }`}
                    >
                        Content
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('code-examples')}
                        className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                            activeTab === 'code-examples'
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        }`}
                    >
                        Code Examples
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('resources')}
                        className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                            activeTab === 'resources'
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        }`}
                    >
                        Resources
                    </button>
                </div>

                {activeTab === 'content' ? (
                    <section className="kb-reader-article rounded-3xl border border-neutral-200/70 bg-card/90 p-6 shadow-sm md:p-10 dark:border-neutral-800/70 dark:bg-neutral-950/60">
                        <article
                            ref={articleRef}
                            className="kb-markdown mx-auto max-w-3xl"
                            dangerouslySetInnerHTML={{ __html: item.content_html }}
                        />
                    </section>
                ) : null}

                {activeTab === 'code-examples' ? (
                    <div className="space-y-4">
                        {codeExamples.length === 0 ? (
                            <Card>
                                <CardContent className="py-8 text-sm text-muted-foreground">
                                    No code examples available.
                                </CardContent>
                            </Card>
                        ) : (
                            codeExamples.map((example) => (
                                <Card key={example.id} className="rounded-2xl">
                                    <CardHeader className="space-y-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge variant="secondary">
                                                {example.language}
                                            </Badge>
                                            {example.title ? (
                                                <CardTitle className="text-base">
                                                    {example.title}
                                                </CardTitle>
                                            ) : null}
                                        </div>
                                        {example.description ? (
                                            <CardDescription>
                                                {example.description}
                                            </CardDescription>
                                        ) : null}
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        <HighlightCodeBlock
                                            code={example.code}
                                            language={example.language}
                                            filename={example.filename}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Updated {formatDate(example.updated_at)}
                                        </p>
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </div>
                ) : null}

                {activeTab === 'resources' ? (
                    <Card className="rounded-3xl border border-neutral-200/70 bg-card/90 shadow-sm dark:border-neutral-800/70 dark:bg-neutral-950/60">
                        <CardHeader>
                            <CardTitle className="text-2xl">
                                Recommended references
                            </CardTitle>
                            <CardDescription>
                                Quick links and files attached to this knowledge item.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {resources.length === 0 ? (
                                <p className="py-6 text-sm text-muted-foreground">
                                    No resources linked.
                                </p>
                            ) : (
                                <ul className="kb-resource-list space-y-4">
                                    {resources.map((resource) => {
                                        const label =
                                            resource.label ||
                                            (resource.type === 'link'
                                                ? resource.url
                                                : resource.storage_path) ||
                                            'Untitled resource';

                                        return (
                                            <li key={resource.id} className="kb-resource-item">
                                                <span className="mt-2 h-1.5 w-1.5 rounded-full bg-neutral-400 dark:bg-neutral-500" />
                                                <div className="min-w-0 flex-1 space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {resource.type === 'link' &&
                                                        resource.url ? (
                                                            <a
                                                                href={resource.url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="kb-resource-pill"
                                                            >
                                                                <Link2 className="h-3.5 w-3.5" />
                                                                {label}
                                                                <ExternalLink className="h-3.5 w-3.5 opacity-70" />
                                                            </a>
                                                        ) : (
                                                            <span className="kb-resource-pill kb-resource-pill-muted">
                                                                <FileText className="h-3.5 w-3.5" />
                                                            </span>
                                                        )}
                                                    </div>

                                                    {resource.type === 'file' ? (
                                                        <p className="text-sm text-muted-foreground">
                                                            {resource.storage_path ||
                                                                'File preview/download is not available yet.'}
                                                            {resource.mime
                                                                ? ` • ${resource.mime}`
                                                                : ''}
                                                            {resource.size !== null
                                                                ? ` • ${resource.size} bytes`
                                                                : ''}
                                                        </p>
                                                    ) : null}

                                                    {resource.type === 'link' &&
                                                    resource.url ? (
                                                        <p className="truncate text-sm text-muted-foreground">
                                                            {resource.url}
                                                        </p>
                                                    ) : null}

                                                    {resource.extracted_text ? (
                                                        <div className="rounded-lg border border-neutral-200/70 bg-muted/40 p-3 text-sm dark:border-neutral-700">
                                                            <p className="mb-1 text-xs font-medium text-muted-foreground">
                                                                Extracted notes
                                                            </p>
                                                            <p className="line-clamp-4 whitespace-pre-wrap">
                                                                {resource.extracted_text}
                                                            </p>
                                                        </div>
                                                    ) : null}

                                                    <p className="text-xs text-muted-foreground">
                                                        Updated {formatDate(resource.updated_at)}
                                                    </p>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </AppLayout>
    );
}
