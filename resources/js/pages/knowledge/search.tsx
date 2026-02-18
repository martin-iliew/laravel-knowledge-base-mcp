import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import FormSelect from '@/components/form-select';
import Heading from '@/components/heading';
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
        href: '/dashboard',
    },
    {
        title: 'Search',
        href: '/knowledge/search',
    },
];

function formatDate(value: string | null): string {
    if (!value) {
        return 'Unknown';
    }

    return new Date(value).toLocaleString();
}

export default function KnowledgeSearch({
    filters,
    results,
    categoryOptions,
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
    categoryOptions: string[];
}) {
    const [limitValue, setLimitValue] = useState(String(filters.limit));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Knowledge Search" />

            <div className="flex flex-1 flex-col gap-6 rounded-xl p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Knowledge Search"
                        description="Hybrid retrieval over your indexed items."
                    />
                    <Button asChild variant="outline">
                        <Link href="/dashboard">Back to knowledge base</Link>
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Search filters</CardTitle>
                        <CardDescription>
                            Query, category, tags, and optional draft inclusion.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            method="get"
                            action="/knowledge/search"
                            options={{ preserveScroll: true }}
                            className="grid gap-4"
                        >
                            {({ processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="query">Query</Label>
                                        <Input
                                            id="query"
                                            name="query"
                                            defaultValue={filters.query}
                                            placeholder="What are you looking for?"
                                            required
                                        />
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="category">Category</Label>
                                            <Input
                                                id="category"
                                                name="category"
                                                list="search-category-options"
                                                defaultValue={filters.category ?? ''}
                                                placeholder="Optional category"
                                            />
                                            <datalist id="search-category-options">
                                                {categoryOptions.map((category) => (
                                                    <option key={category} value={category} />
                                                ))}
                                            </datalist>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="tags">Tags (comma separated)</Label>
                                            <Input
                                                id="tags"
                                                name="tags"
                                                defaultValue={filters.tags}
                                                placeholder="api, auth"
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="limit">Item limit</Label>
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

                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="include_drafts"
                                                value="1"
                                                defaultChecked={filters.include_drafts}
                                            />
                                            Include drafts
                                        </label>
                                    </div>

                                    <Button type="submit" className="w-fit" disabled={processing}>
                                        Search
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <div className="space-y-4">
                    {results.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-sm text-muted-foreground">
                                No results yet. Run a search to see hybrid retrieval output.
                            </CardContent>
                        </Card>
                    ) : (
                        results.map((result) => (
                            <Card key={result.item.id}>
                                <CardHeader className="space-y-3">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <CardTitle>{result.item.title}</CardTitle>
                                        <Button asChild size="sm" variant="outline">
                                            <Link href={`/knowledge-items/${result.item.id}/edit`}>
                                                Open item
                                            </Link>
                                        </Button>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {result.item.category ? (
                                            <Badge variant="outline">{result.item.category}</Badge>
                                        ) : null}
                                        {result.item.tags.map((tag) => (
                                            <Badge key={`${result.item.id}-${tag}`} variant="secondary">
                                                {tag}
                                            </Badge>
                                        ))}
                                        <span className="text-xs text-muted-foreground">
                                            Updated {formatDate(result.item.updated_at)}
                                        </span>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-5">
                                    <div className="space-y-2">
                                        <h3 className="text-sm font-semibold">Snippets</h3>
                                        <div className="space-y-2">
                                            {result.snippets.map((snippet, index) => (
                                                <div
                                                    key={`${result.item.id}-snippet-${snippet.chunk_index}-${index}`}
                                                    className="rounded-lg border p-3"
                                                >
                                                    <div className="mb-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                        <Badge variant="outline">
                                                            {snippet.source_type}
                                                        </Badge>
                                                        <span>Chunk #{snippet.chunk_index}</span>
                                                        {snippet.score !== null ? (
                                                            <span>
                                                                Score: {snippet.score.toFixed(3)}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    <p className="text-sm whitespace-pre-wrap">
                                                        {snippet.text}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <h3 className="text-sm font-semibold">
                                            Referenced code examples
                                        </h3>
                                        {result.code_examples.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No code examples referenced.
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {result.code_examples.map((example) => (
                                                    <div
                                                        key={example.id}
                                                        className="rounded-lg border p-3"
                                                    >
                                                        <div className="mb-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                            <Badge variant="secondary">
                                                                {example.language}
                                                            </Badge>
                                                            {example.title ? (
                                                                <span>{example.title}</span>
                                                            ) : null}
                                                            {example.filename ? (
                                                                <span>{example.filename}</span>
                                                            ) : null}
                                                        </div>
                                                        <pre className="overflow-x-auto rounded bg-muted p-2 text-xs">
                                                            <code>{example.code}</code>
                                                        </pre>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <h3 className="text-sm font-semibold">Referenced resources</h3>
                                        {result.resources.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No resources referenced.
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {result.resources.map((resource) => (
                                                    <div
                                                        key={resource.id}
                                                        className="rounded-lg border p-3 text-sm"
                                                    >
                                                        <div className="mb-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                            <Badge variant="outline">
                                                                {resource.type}
                                                            </Badge>
                                                            {resource.label ? (
                                                                <span>{resource.label}</span>
                                                            ) : null}
                                                        </div>
                                                        {resource.url ? (
                                                            <a
                                                                className="underline"
                                                                href={resource.url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                            >
                                                                {resource.url}
                                                            </a>
                                                        ) : null}
                                                        {resource.storage_path ? (
                                                            <p>{resource.storage_path}</p>
                                                        ) : null}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
