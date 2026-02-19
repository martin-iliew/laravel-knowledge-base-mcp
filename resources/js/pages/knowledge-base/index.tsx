import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, FolderTree, Plus, Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as knowledgeBaseIndex, show as knowledgeBaseShow } from '@/routes/knowledge-base';
import { create as createKnowledgeItem } from '@/routes/knowledge-items';
import type { BreadcrumbItem } from '@/types';

const ALL_CATEGORIES_VALUE = '__all__';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Knowledge Base',
        href: knowledgeBaseIndex(),
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
}: {
    filters: {
        query: string;
        category: string | null;
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
}) {
    const [queryValue, setQueryValue] = useState(filters.query);
    const [categoryValue, setCategoryValue] = useState(
        filters.category ?? ALL_CATEGORIES_VALUE,
    );

    const applyFilters = (overrides?: {
        query?: string;
        category?: string;
    }): void => {
        const nextQuery = (overrides?.query ?? queryValue).trim();
        const nextCategory = overrides?.category ?? categoryValue;

        const requestFilters: Record<string, string | number> = {
            include_drafts: 0,
            limit: filters.limit,
        };

        if (nextQuery !== '') {
            requestFilters.query = nextQuery;
        }

        if (nextCategory !== ALL_CATEGORIES_VALUE) {
            requestFilters.category = nextCategory;
        }

        router.get(knowledgeBaseIndex.url(), requestFilters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const hasActiveSearch = filters.query.trim() !== '';
    const hasCategoryFilter =
        typeof filters.category === 'string' && filters.category.trim() !== '';
    const hasActiveFilters = hasActiveSearch || hasCategoryFilter;

    const cards = hasActiveSearch
        ? results.map((result) => {
              const preview = result.snippets[0]?.text ?? '';

              return {
                  id: result.item.id,
                  title: result.item.title,
                  category: result.item.category,
                  tags: result.item.tags,
                  updated_at: result.item.updated_at,
                  preview,
              };
          })
        : latestItems.map((item) => ({
              id: item.id,
              title: item.title,
              category: item.category,
              tags: item.tags,
              updated_at: item.updated_at,
              preview: '',
          }));

    const cardsTitle = hasActiveSearch
        ? 'Search Results'
        : hasCategoryFilter
          ? `Category: ${filters.category}`
          : 'Knowledge Library';

    const cardsDescription = hasActiveSearch
        ? `${cards.length} matching knowledge item${cards.length === 1 ? '' : 's'}`
        : `${cards.length} knowledge item${cards.length === 1 ? '' : 's'} available`;

    const handleSearchSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        applyFilters();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Knowledge Base" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <section className="rounded-3xl border border-neutral-200/70 bg-gradient-to-br from-neutral-50 via-white to-neutral-100 p-6 shadow-sm dark:border-neutral-800/70 dark:from-neutral-950 dark:via-neutral-900 dark:to-neutral-950">
                    <div className="mx-auto grid max-w-6xl gap-6 lg:grid-cols-[1.45fr_1fr]">
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <p className="text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                    Knowledge Base
                                </p>
                                <h1 className="text-3xl font-semibold tracking-tight md:text-4xl">
                                    Find knowledge fast
                                </h1>
                                <p className="text-sm text-muted-foreground md:text-base">
                                    Search content and filter by category without switching pages.
                                </p>
                            </div>

                            <form onSubmit={handleSearchSubmit} className="space-y-3 rounded-2xl border bg-background/80 p-4 shadow-xs">
                                <Label htmlFor="query-filter">Search</Label>
                                <div className="relative">
                                    <Input
                                        id="query-filter"
                                        value={queryValue}
                                        onChange={(event) =>
                                            setQueryValue(event.target.value)
                                        }
                                        placeholder="Search knowledge content"
                                        className="h-11 pr-12"
                                    />
                                    <Button
                                        type="submit"
                                        size="icon"
                                        className="absolute top-1/2 right-1.5 h-8 w-8 -translate-y-1/2 rounded-md"
                                    >
                                        <Search className="h-4 w-4" />
                                    </Button>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            setQueryValue('');
                                            applyFilters({ query: '' });
                                        }}
                                        disabled={queryValue.trim() === ''}
                                    >
                                        Clear search
                                    </Button>
                                </div>
                            </form>
                        </div>

                        <div className="space-y-3 rounded-2xl border bg-background/80 p-4 shadow-xs">
                            <div className="flex items-center gap-2">
                                <FolderTree className="h-4 w-4 text-muted-foreground" />
                                <h2 className="text-sm font-semibold">Category Filter</h2>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setCategoryValue(ALL_CATEGORIES_VALUE);
                                        applyFilters({
                                            category: ALL_CATEGORIES_VALUE,
                                        });
                                    }}
                                    className={cn(
                                        'rounded-full border px-3 py-1.5 text-xs font-medium transition',
                                        categoryValue === ALL_CATEGORIES_VALUE
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border bg-background text-muted-foreground hover:border-foreground/20 hover:text-foreground',
                                    )}
                                >
                                    All categories
                                </button>
                                {categoryOptions.map((category) => (
                                    <button
                                        key={category}
                                        type="button"
                                        onClick={() => {
                                            setCategoryValue(category);
                                            applyFilters({ category });
                                        }}
                                        className={cn(
                                            'rounded-full border px-3 py-1.5 text-xs font-medium transition',
                                            categoryValue === category
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-border bg-background text-muted-foreground hover:border-foreground/20 hover:text-foreground',
                                        )}
                                    >
                                        {category}
                                    </button>
                                ))}
                            </div>

                            {hasActiveFilters ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        setQueryValue('');
                                        setCategoryValue(ALL_CATEGORIES_VALUE);
                                        router.get(
                                            knowledgeBaseIndex.url(),
                                            {},
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                                replace: true,
                                            },
                                        );
                                    }}
                                    className="mt-1"
                                >
                                    Reset all filters
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </section>

                <main className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-xl font-semibold">{cardsTitle}</h2>
                            <p className="text-sm text-muted-foreground">
                                {cardsDescription}
                            </p>
                        </div>
                        <Button asChild>
                            <Link href={createKnowledgeItem()}>
                                <Plus className="mr-2 h-4 w-4" />
                                New item
                            </Link>
                        </Button>
                    </div>

                    {cards.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-sm text-muted-foreground">
                                {hasActiveFilters
                                    ? 'No knowledge items match the current filters.'
                                    : 'No knowledge items yet. Create your first one.'}
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
                                                <Badge
                                                    key={`${card.id}-${tag}`}
                                                    variant="secondary"
                                                >
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
                                        <Button
                                            asChild
                                            variant="ghost"
                                            className="-ml-3 px-3"
                                        >
                                            <Link href={knowledgeBaseShow(card.id)}>
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
        </AppLayout>
    );
}
