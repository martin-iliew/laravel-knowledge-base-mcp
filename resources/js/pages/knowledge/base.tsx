import { Form, Head, Link } from '@inertiajs/react';
import { ArrowRight, FolderTree, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import FormSelect from '@/components/form-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Knowledge Base',
        href: '/knowledge-base',
    },
];

function formatDate(value: string | null): string {
    if (!value) {
        return 'Unknown';
    }

    return new Date(value).toLocaleDateString();
}


export default function KnowledgeBasePage({
    filters,
    results,
    latestItems,
    categoryOptions,
    tagOptions,
    categoryTree,
}: {
    filters: {
        query: string;
        category: string | null;
        tags: string;
        include_drafts: boolean;
        limit: number;
    };
    results: Array<{
        item: {
            id: number;
            slug: string;
            title: string;
            category: string | null;
            tags: string[];
            updated_at: string | null;
        };
        snippets: Array<{
            source_type: 'article' | 'code' | 'resource';
            source_id: number | null;
            chunk_kind: 'markdown' | 'code' | 'text';
            chunk_index: number;
            meta: Record<string, unknown>;
            score: number | null;
            text: string;
        }>;
        code_examples: Array<{
            id: number;
            title: string | null;
            language: string;
            filename: string | null;
            code: string;
        }>;
        resources: Array<{
            id: number;
            type: 'link' | 'file';
            label: string | null;
            url: string | null;
            storage_path: string | null;
            mime: string | null;
            size: number | null;
        }>;
    }>;
    latestItems: Array<{
        id: number;
        slug: string;
        title: string;
        category: string | null;
        tags: string[];
        status: 'draft' | 'published' | 'archived';
        updated_at: string | null;
        published_at: string | null;
    }>;
    categoryOptions: string[];
    tagOptions: string[];
    categoryTree: Array<{
        name: string;
        count: number;
        children: Array<{
            name: string;
            count: number;
        }>;
    }>;
}) {
    const [limitValue, setLimitValue] = useState(String(filters.limit));

    const hasActiveSearch = filters.query.trim() !== '';
    const cards = hasActiveSearch
        ? results.map((result) => {
              const preview = result.snippets[0]?.text ?? '';

              return {
                  id: result.item.id,
                  title: result.item.title,
                  category: result.item.category,
                  tags: result.item.tags,
                  updated_at: result.item.updated_at,
                  published_at: null,
                  status: null,
                  preview,
              };
          })
        : latestItems.map((item) => ({
              id: item.id,
              title: item.title,
              category: item.category,
              tags: item.tags,
              updated_at: item.updated_at,
              published_at: item.published_at,
              status: item.status,
              preview: '',
          }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Knowledge Base" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <section className="rounded-2xl border bg-gradient-to-br from-neutral-50 via-white to-neutral-100 p-6 dark:from-neutral-950 dark:via-neutral-900 dark:to-neutral-950">
                    <div className="mx-auto max-w-4xl space-y-3 text-center">
                        <h1 className="text-3xl font-semibold tracking-tight md:text-4xl">
                            How can we help you?
                        </h1>
                        <p className="text-sm text-muted-foreground md:text-base">
                            Search your knowledge base and open articles in reader mode.
                        </p>
                    </div>

                    <Form
                        method="get"
                        action="/knowledge-base"
                        options={{ preserveScroll: true, preserveState: true }}
                        className="mx-auto mt-6 max-w-4xl space-y-3"
                    >
                        {({ processing }) => (
                            <>
                                <div className="relative">
                                    <Input
                                        name="query"
                                        defaultValue={filters.query}
                                        placeholder="Ask AI or search..."
                                        className="h-12 rounded-xl pr-12 text-base"
                                    />
                                    <Button
                                        type="submit"
                                        size="icon"
                                        className="absolute top-1/2 right-1.5 h-9 w-9 -translate-y-1/2 rounded-lg"
                                        disabled={processing}
                                    >
                                        <Search className="h-4 w-4" />
                                    </Button>
                                </div>

                                <details className="rounded-xl border bg-background/70 px-4 py-3">
                                    <summary className="cursor-pointer text-sm font-medium">
                                        Search filters
                                    </summary>
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="category">Category</Label>
                                            <Input
                                                id="category"
                                                name="category"
                                                list="knowledge-base-category-options"
                                                defaultValue={filters.category ?? ''}
                                                placeholder="Optional category"
                                            />
                                            <datalist id="knowledge-base-category-options">
                                                {categoryOptions.map((option) => (
                                                    <option key={option} value={option} />
                                                ))}
                                            </datalist>
                                        </div>

                                        <div className="grid gap-1.5">
                                            <Label htmlFor="tags">Tags (comma separated)</Label>
                                            <Input
                                                id="tags"
                                                name="tags"
                                                list="knowledge-base-tag-options"
                                                defaultValue={filters.tags}
                                                placeholder="api, onboarding"
                                            />
                                            <datalist id="knowledge-base-tag-options">
                                                {tagOptions.map((option) => (
                                                    <option key={option} value={option} />
                                                ))}
                                            </datalist>
                                        </div>

                                        <label className="inline-flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="include_drafts"
                                                value="1"
                                                defaultChecked={filters.include_drafts}
                                            />
                                            Include drafts
                                        </label>

                                        <div className="grid gap-1.5">
                                            <Label htmlFor="limit">Result limit</Label>
                                            <FormSelect
                                                id="limit"
                                                name="limit"
                                                value={limitValue}
                                                onValueChange={setLimitValue}
                                                options={[
                                                    { value: '3', label: '3' },
                                                    { value: '5', label: '5' },
                                                    { value: '10', label: '10' },
                                                ]}
                                            />
                                        </div>
                                    </div>
                                </details>
                            </>
                        )}
                    </Form>
                </section>
                <div>
                    <main className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-semibold">
                                    {hasActiveSearch ? 'Search Results' : 'Latest Articles'}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {hasActiveSearch
                                        ? `${cards.length} matching article${cards.length === 1 ? '' : 's'}`
                                        : 'Recently updated knowledge items'}
                                </p>
                            </div>
                            <Button asChild>
                                <Link href="/knowledge-items/create">
                                    <Plus className="mr-2 h-4 w-4" />
                                    New item
                                </Link>
                            </Button>
                        </div>

                        {cards.length === 0 ? (
                            <Card>
                                <CardContent className="py-8 text-sm text-muted-foreground">
                                    {hasActiveSearch
                                        ? 'No matching knowledge items found for this query.'
                                        : 'No knowledge items yet. Create your first article.'}
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2">
                                {cards.map((card) => (
                                    <Card key={card.id} className="group rounded-2xl">
                                        <CardHeader className="space-y-3">
                                            <div className="flex items-start justify-between gap-3">
                                                <CardTitle className="text-lg leading-tight">
                                                    {card.title}
                                                </CardTitle>
                                                {card.status ? (
                                                    <Badge
                                                        variant={
                                                            card.status === 'published'
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {card.status}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            <CardDescription>
                                                Updated {formatDate(card.updated_at)}
                                            </CardDescription>
                                            <div className="flex flex-wrap gap-1.5">
                                                {card.category ? (
                                                    <Badge variant="outline">
                                                        {card.category}
                                                    </Badge>
                                                ) : null}
                                                {card.tags.map((tag) => (
                                                    <Badge key={`${card.id}-${tag}`} variant="secondary">
                                                        {tag}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            {card.preview !== '' ? (
                                                <p className="line-clamp-3 text-sm text-muted-foreground">
                                                    {card.preview}
                                                </p>
                                            ) : null}
                                            <Button asChild variant="ghost" className="-ml-3 px-3">
                                                <Link href={`/knowledge-base/${card.id}`}>
                                                    Open article
                                                    <ArrowRight className="ml-2 h-4 w-4 transition group-hover:translate-x-0.5" />
                                                </Link>
                                            </Button>
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </main>
                </div>
            </div>
        </AppLayout>
    );
}
