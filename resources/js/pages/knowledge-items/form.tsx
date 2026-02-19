import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head, Link } from '@inertiajs/react';
import { type MutableRefObject, useMemo, useRef, useState } from 'react';
import { z } from 'zod';
import ConfirmActionDialog from '@/components/confirm-action-dialog';
import FormSelect from '@/components/form-select';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import HighlightCodeBlock from '@/components/knowledge/code-block';
import HighlightCodeEditor from '@/components/knowledge/code-editor';
import KnowledgeMarkdownEditor from '@/components/knowledge/markdown-editor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { index as knowledgeBaseIndex } from '@/routes/knowledge-base';
import {
    create as createKnowledgeItem,
    edit as editKnowledgeItem,
    store as storeKnowledgeItem,
    update as updateKnowledgeItem,
} from '@/routes/knowledge-items';
import {
    destroy as destroyCodeExample,
    store as storeCodeExample,
    update as updateCodeExample,
} from '@/routes/knowledge-items/code-examples';
import {
    destroy as destroyKnowledgeResource,
    store as storeKnowledgeResource,
    update as updateKnowledgeResource,
} from '@/routes/knowledge-items/resources';
import type { BreadcrumbItem } from '@/types';

const toText = (value: unknown): string => {
    return typeof value === 'string' ? value : '';
};

const codeLanguageOptions: string[] = [
    'php',
    'javascript',
    'typescript',
    'tsx',
    'jsx',
    'json',
    'yaml',
    'bash',
    'sql',
    'html',
    'css',
    'python',
];

const knowledgeItemSchema = z.object({
    title: z.preprocess(
        (value) => toText(value).trim(),
        z
            .string()
            .min(1, 'Please provide a title.')
            .max(500, 'Title must be 500 characters or fewer.'),
    ),
    content_markdown: z.preprocess(
        (value) => toText(value).trim(),
        z.string().min(1, 'Content is required.'),
    ),
    category: z.preprocess(
        (value) => toText(value).trim(),
        z.string().max(120, 'Category must be 120 characters or fewer.'),
    ),
    tags: z.preprocess(
        (value) =>
            toText(value)
                .split(',')
                .map((tag) => tag.trim())
                .filter((tag) => tag.length > 0),
        z.array(z.string().max(60, 'Each tag must be 60 characters or fewer.')),
    ),
});

const codeExampleCreateSchema = z.object({
    title: z.preprocess(
        (value) => toText(value).trim(),
        z.string().max(255, 'Title must be 255 characters or fewer.'),
    ),
    language: z.preprocess(
        (value) => toText(value).trim(),
        z
            .string()
            .min(1, 'Please choose a language.')
            .max(50, 'Language must be 50 characters or fewer.'),
    ),
    filename: z.preprocess(
        (value) => toText(value).trim(),
        z.string().max(255, 'Filename must be 255 characters or fewer.'),
    ),
    description: z.preprocess((value) => toText(value).trim(), z.string()),
    code: z.preprocess(
        (value) => toText(value).trim(),
        z.string().min(1, 'Code content is required.'),
    ),
});

const knowledgeResourceCreateSchema = z
    .object({
        type: z.literal('link'),
        label: z.preprocess(
            (value) => toText(value).trim(),
            z.string().max(255, 'Label must be 255 characters or fewer.'),
        ),
        url: z.preprocess((value) => toText(value).trim(), z.string()),
        extracted_text: z.preprocess((value) => toText(value), z.string()),
    })
    .superRefine((data, context) => {
        if (data.url === '') {
            context.addIssue({
                code: z.ZodIssueCode.custom,
                message: 'A URL is required.',
                path: ['url'],
            });
        }

        if (data.url !== '') {
            const urlCheck = z.string().url().safeParse(data.url);

            if (!urlCheck.success) {
                context.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Please enter a valid URL.',
                    path: ['url'],
                });
            }
        }
    });

const applyZodErrors = (form: FormComponentRef, error: z.ZodError): void => {
    const fieldErrors: Record<string, string> = {};

    for (const issue of error.issues) {
        const path = issue.path[0];

        if (typeof path !== 'string') {
            continue;
        }

        if (fieldErrors[path]) {
            continue;
        }

        fieldErrors[path] = issue.message;
    }

    if (Object.keys(fieldErrors).length > 0) {
        form.setError(fieldErrors);
    }
};

const validateFormWithSchema = (
    schema: z.ZodType<unknown>,
    formRef: MutableRefObject<FormComponentRef | null>,
): boolean => {
    const form = formRef.current;

    if (!form) {
        return true;
    }

    form.clearErrors();

    const validation = schema.safeParse(form.getData());

    if (validation.success) {
        return true;
    }

    applyZodErrors(form, validation.error);

    return false;
};

const validateKnowledgeItemForm = (
    formRef: MutableRefObject<FormComponentRef | null>,
    contentMarkdown: string,
): boolean => {
    const form = formRef.current;

    if (!form) {
        return true;
    }

    form.clearErrors();

    const validation = knowledgeItemSchema.safeParse({
        ...form.getData(),
        content_markdown: contentMarkdown,
    });

    if (validation.success) {
        return true;
    }

    applyZodErrors(form, validation.error);

    return false;
};

type KnowledgeFormStep = 'details' | 'enhancements';

export default function KnowledgeForm({
    mode,
    step,
    item,
    codeExamples,
    resources,
    categoryOptions,
    permissions,
}: {
    mode: 'create' | 'edit';
    step: KnowledgeFormStep;
    item: {
        id: number;
        slug: string;
        title: string;
        content_markdown: string;
        category: string | null;
        tags: string[];
        status: 'draft' | 'published' | 'archived';
        published_at: string | null;
        updated_at: string | null;
        owner?: {
            id: number | null;
            name: string | null;
            email: string | null;
        };
    } | null;
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
    categoryOptions: string[];
    permissions: {
        can_update: boolean;
        can_delete: boolean;
        access_level: 'owner' | 'viewer' | 'editor';
    };
}) {
    const isEditing = mode === 'edit' && item !== null;
    const activeStep: KnowledgeFormStep =
        isEditing && step === 'enhancements' ? 'enhancements' : 'details';
    const canUpdate = permissions?.can_update ?? !isEditing;
    const canDelete = permissions?.can_delete ?? false;
    const accessLevel =
        permissions?.access_level ?? (isEditing ? 'viewer' : 'owner');
    const knowledgeItemFormRef = useRef<FormComponentRef | null>(null);
    const createCodeExampleFormRef = useRef<FormComponentRef | null>(null);
    const createKnowledgeResourceFormRef = useRef<FormComponentRef | null>(null);
    const [markdownContent, setMarkdownContent] = useState<string>(
        item?.content_markdown ?? '',
    );
    const [newCodeExampleDraft, setNewCodeExampleDraft] = useState<{
        language: string;
        filename: string;
        code: string;
    }>({
        language: 'php',
        filename: '',
        code: '',
    });
    const [existingCodeExampleDrafts, setExistingCodeExampleDrafts] = useState<
        Record<number, { language: string; filename: string; code: string }>
    >(
        () =>
            codeExamples.reduce<Record<number, { language: string; filename: string; code: string }>>(
                (drafts, example) => {
                    drafts[example.id] = {
                        language: example.language,
                        filename: example.filename ?? '',
                        code: example.code,
                    };

                    return drafts;
                },
                {},
            ),
    );

    const sortedCodeExamples = useMemo(
        () => [...codeExamples].sort((left, right) => left.sort_order - right.sort_order),
        [codeExamples],
    );
    const linkResources = useMemo(
        () =>
            [...resources]
                .filter((resource) => resource.type === 'link')
                .sort((left, right) => left.sort_order - right.sort_order),
        [resources],
    );
    const nonLinkResourcesCount = resources.length - linkResources.length;
    const detailsStepHref =
        isEditing && item
            ? editKnowledgeItem(item.id, { query: { step: 'details' } })
            : createKnowledgeItem();
    const enhancementsStepHref =
        isEditing && item
            ? editKnowledgeItem(item.id, { query: { step: 'enhancements' } })
            : null;
    const knowledgeItemFormRoute =
        isEditing && item
            ? updateKnowledgeItem.form(item.id, { query: { step: 'details' } })
            : storeKnowledgeItem.form();

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Knowledge Base',
            href: knowledgeBaseIndex(),
        },
        {
            title: isEditing ? 'Edit item' : 'New item',
            href: isEditing && item ? editKnowledgeItem(item.id) : createKnowledgeItem(),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={isEditing ? 'Edit Knowledge Item' : 'Create Knowledge Item'} />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4">
                <Card className="overflow-hidden rounded-3xl border border-neutral-200/70 shadow-sm dark:border-neutral-800/70 bg-gradient-to-br from-neutral-50 via-neutral-100 to-neutral-200/70 dark:from-neutral-950 dark:via-neutral-900 dark:to-neutral-950">
                    <CardHeader className="relative ">
                        <div className="pointer-events-none absolute -top-20 -right-20 h-40 w-40 rounded-full bg-neutral-500/15 blur-3xl" />
                        <div className="relative flex flex-wrap items-end justify-between gap-3">
                            <Heading
                                title={isEditing ? 'Edit Knowledge Item' : 'Create Knowledge Item'}
                                description={
                                    canUpdate
                                        ? 'Write with a visual editor. Raw markdown remains available when needed.'
                                        : 'Read-only access: this item was shared with your account.'
                                }
                            />
                            <div className="flex items-center gap-2">
                                <Badge variant="outline">{accessLevel}</Badge>
                                <Button asChild variant="outline">
                                    <Link href={knowledgeBaseIndex()}>Back to list</Link>
                                </Button>
                            </div>
                        </div>
                        <div className="relative mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            {isEditing ? (
                                <Button
                                    asChild
                                    variant={activeStep === 'details' ? 'default' : 'outline'}
                                    size="sm"
                                    className="rounded-full"
                                >
                                    <Link href={detailsStepHref}>01 Details + markdown</Link>
                                </Button>
                            ) : (
                                <span className="inline-flex h-8 items-center rounded-full border bg-primary px-3 font-medium text-primary-foreground">
                                    01 Details + markdown
                                </span>
                            )}
                            {isEditing && enhancementsStepHref ? (
                                <Button
                                    asChild
                                    variant={activeStep === 'enhancements' ? 'default' : 'outline'}
                                    size="sm"
                                    className="rounded-full"
                                >
                                    <Link href={enhancementsStepHref}>
                                        02 Code + resources (optional)
                                    </Link>
                                </Button>
                            ) : (
                                <span className="inline-flex h-8 items-center rounded-full border px-3">
                                    02 Code + resources (optional)
                                </span>
                            )}
                        </div>
                    </CardHeader>
                </Card>

                {activeStep === 'details' ? (
                    <Card className="rounded-3xl border border-neutral-200/70 bg-card/95 shadow-sm dark:border-neutral-800/70 dark:bg-neutral-950/60">
                        <CardHeader>
                            <CardTitle>Step 1: Details and markdown</CardTitle>
                            <CardDescription>
                                Start with the core knowledge entry. You can add optional code
                                examples and resources in the next step.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                ref={knowledgeItemFormRef}
                                {...knowledgeItemFormRoute}
                                options={{ preserveScroll: true }}
                                onBefore={() =>
                                    canUpdate &&
                                    validateKnowledgeItemForm(
                                        knowledgeItemFormRef,
                                        markdownContent,
                                    )
                                }
                                className="grid gap-5"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="grid gap-2 md:col-span-2">
                                                <Label htmlFor="title">Title</Label>
                                                <Input
                                                    id="title"
                                                    name="title"
                                                    defaultValue={item?.title ?? ''}
                                                    maxLength={500}
                                                    disabled={!canUpdate}
                                                    required
                                                    className="h-12 text-base"
                                                    placeholder="Write a clear, searchable title"
                                                />
                                                <InputError message={errors.title} />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="category">Category</Label>
                                                <Input
                                                    id="category"
                                                    name="category"
                                                    list="knowledge-category-options"
                                                    defaultValue={item?.category ?? ''}
                                                    maxLength={120}
                                                    disabled={!canUpdate}
                                                    placeholder="Engineering, Support, Finance..."
                                                />
                                                <datalist id="knowledge-category-options">
                                                    {categoryOptions.map((category) => (
                                                        <option key={category} value={category} />
                                                    ))}
                                                </datalist>
                                                <InputError message={errors.category} />
                                            </div>

                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="tags">Tags (comma separated)</Label>
                                            <Input
                                                id="tags"
                                                name="tags"
                                                defaultValue={item?.tags.join(', ') ?? ''}
                                                maxLength={1000}
                                                disabled={!canUpdate}
                                                placeholder="api, onboarding, troubleshooting"
                                            />
                                            <InputError message={errors.tags} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="content_markdown">Content</Label>
                                            <Input
                                                type="hidden"
                                                name="content_markdown"
                                                value={markdownContent}
                                                readOnly
                                            />
                                            <KnowledgeMarkdownEditor
                                                value={markdownContent}
                                                onChange={setMarkdownContent}
                                                disabled={!canUpdate}
                                            />
                                            <InputError message={errors.content_markdown} />
                                        </div>

                                        <div className="flex flex-wrap items-center gap-2">
                                            {canUpdate ? (
                                                <Button type="submit" disabled={processing}>
                                                    {isEditing
                                                        ? 'Save details'
                                                        : 'Save and continue'}
                                                </Button>
                                            ) : null}
                                            {isEditing && enhancementsStepHref && canUpdate ? (
                                                <Button asChild variant="outline">
                                                    <Link href={enhancementsStepHref}>
                                                        Next: code + resources
                                                    </Link>
                                                </Button>
                                            ) : null}
                                            {!isEditing ? (
                                                <p className="text-xs text-muted-foreground">
                                                    Next step is optional and can be skipped.
                                                </p>
                                            ) : null}
                                            {isEditing && !canDelete ? (
                                                <p className="text-xs text-muted-foreground">
                                                    Only the owner can delete this item.
                                                </p>
                                            ) : null}
                                            {item?.slug ? (
                                                <Badge variant="outline">Slug: {item.slug}</Badge>
                                            ) : null}
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                ) : null}

                {isEditing && activeStep === 'enhancements' && canUpdate ? (
                    <>
                        <Card className="rounded-2xl border border-neutral-200/70 bg-card/95 dark:border-neutral-800/70">
                            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                                <div className="space-y-1.5">
                                    <CardTitle>Step 2: Optional code and resources</CardTitle>
                                    <CardDescription>
                                        Add snippets and links now, or skip and come back any time.
                                    </CardDescription>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={detailsStepHref}>Back to details</Link>
                                    </Button>
                                    <Button asChild size="sm">
                                        <Link href={knowledgeBaseIndex()}>Skip for now</Link>
                                    </Button>
                                </div>
                            </CardHeader>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Code examples</CardTitle>
                                <CardDescription>
                                    Add runnable snippets with live syntax-highlighted previews.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <Form
                                    ref={createCodeExampleFormRef}
                                    {...storeCodeExample.form(item.id)}
                                    options={{ preserveScroll: true }}
                                    onBefore={() =>
                                        validateFormWithSchema(
                                            codeExampleCreateSchema,
                                            createCodeExampleFormRef,
                                        )
                                    }
                                    onSuccess={() =>
                                        setNewCodeExampleDraft({
                                            language: 'php',
                                            filename: '',
                                            code: '',
                                        })
                                    }
                                    resetOnSuccess
                                    className="grid gap-4 rounded-xl border border-neutral-200/80 bg-muted/20 p-4 dark:border-neutral-800/80"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <p className="text-sm font-medium">
                                                Add new code example
                                            </p>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <Input
                                                    name="title"
                                                    placeholder="Title (optional)"
                                                    maxLength={255}
                                                />
                                                <FormSelect
                                                    name="language"
                                                    value={newCodeExampleDraft.language}
                                                    onValueChange={(language) =>
                                                        setNewCodeExampleDraft((draft) => ({
                                                            ...draft,
                                                            language,
                                                        }))
                                                    }
                                                    options={codeLanguageOptions.map((language) => ({
                                                        value: language,
                                                        label: language,
                                                    }))}
                                                />
                                                <Input
                                                    name="filename"
                                                    placeholder="Filename (optional)"
                                                    maxLength={255}
                                                    onChange={(event) =>
                                                        setNewCodeExampleDraft((draft) => ({
                                                            ...draft,
                                                            filename: event.target.value,
                                                        }))
                                                    }
                                                />
                                            </div>
                                            <Input
                                                name="description"
                                                placeholder="Description (optional)"
                                            />
                                            <HighlightCodeEditor
                                                name="code"
                                                value={newCodeExampleDraft.code}
                                                language={newCodeExampleDraft.language}
                                                filename={newCodeExampleDraft.filename}
                                                minHeightPx={360}
                                                onChange={(code) =>
                                                    setNewCodeExampleDraft((draft) => ({
                                                        ...draft,
                                                        code,
                                                    }))
                                                }
                                            />
                                            <InputError message={errors.language || errors.code} />
                                            <Button type="submit" disabled={processing}>
                                                Add code example
                                            </Button>
                                        </>
                                    )}
                                </Form>

                                <div className="space-y-4">
                                    {codeExamples.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No code examples yet.
                                        </p>
                                    ) : (
                                        sortedCodeExamples.map((example) => {
                                            const selectedLanguage =
                                                existingCodeExampleDrafts[
                                                    example.id
                                                ]?.language ?? example.language;
                                            const languageOptions = [
                                                ...(codeLanguageOptions.includes(selectedLanguage)
                                                    ? []
                                                    : [selectedLanguage]),
                                                ...codeLanguageOptions,
                                            ];

                                            return (
                                                <div
                                                    key={example.id}
                                                    className="rounded-xl border border-neutral-200/80 p-4 dark:border-neutral-800/80"
                                                >
                                                <Form
                                                    {...updateCodeExample.form([item.id, example.id])}
                                                    options={{ preserveScroll: true }}
                                                    className="grid gap-3"
                                                >
                                                    {({ processing, errors }) => (
                                                        <>
                                                            <div className="grid gap-3 sm:grid-cols-2">
                                                                <Input
                                                                    name="title"
                                                                    defaultValue={example.title ?? ''}
                                                                    placeholder="Title"
                                                                />
                                                                <FormSelect
                                                                    name="language"
                                                                    value={selectedLanguage}
                                                                    onValueChange={(language) =>
                                                                        setExistingCodeExampleDrafts(
                                                                            (drafts) => ({
                                                                                ...drafts,
                                                                                [example.id]: {
                                                                                    language,
                                                                                    filename:
                                                                                        drafts[
                                                                                            example
                                                                                                .id
                                                                                        ]
                                                                                            ?.filename ??
                                                                                        '',
                                                                                    code:
                                                                                        drafts[
                                                                                            example
                                                                                                .id
                                                                                        ]?.code ??
                                                                                        example.code,
                                                                                },
                                                                            }),
                                                                        )
                                                                    }
                                                                    options={languageOptions.map(
                                                                        (language) => ({
                                                                            value: language,
                                                                            label: language,
                                                                        }),
                                                                    )}
                                                                />
                                                                <Input
                                                                    name="filename"
                                                                    defaultValue={example.filename ?? ''}
                                                                    placeholder="Filename"
                                                                    onChange={(event) =>
                                                                        setExistingCodeExampleDrafts(
                                                                            (drafts) => ({
                                                                                ...drafts,
                                                                                [example.id]: {
                                                                                    language:
                                                                                        drafts[
                                                                                            example
                                                                                                .id
                                                                                        ]?.language ??
                                                                                        example.language,
                                                                                    filename:
                                                                                        event.target
                                                                                            .value,
                                                                                    code:
                                                                                        drafts[
                                                                                            example
                                                                                                .id
                                                                                        ]?.code ??
                                                                                        example.code,
                                                                                },
                                                                            }),
                                                                        )
                                                                    }
                                                                />
                                                            </div>
                                                            <Input
                                                                name="description"
                                                                defaultValue={example.description ?? ''}
                                                                placeholder="Description"
                                                            />
                                                            <HighlightCodeEditor
                                                                name="code"
                                                                value={
                                                                    existingCodeExampleDrafts[
                                                                        example.id
                                                                    ]?.code ?? example.code
                                                                }
                                                                language={
                                                                    existingCodeExampleDrafts[
                                                                        example.id
                                                                    ]?.language ?? example.language
                                                                }
                                                                filename={
                                                                    existingCodeExampleDrafts[
                                                                        example.id
                                                                    ]?.filename ??
                                                                    example.filename
                                                                }
                                                                minHeightPx={360}
                                                                onChange={(code) =>
                                                                    setExistingCodeExampleDrafts(
                                                                        (drafts) => ({
                                                                            ...drafts,
                                                                            [example.id]: {
                                                                                language:
                                                                                    drafts[
                                                                                        example.id
                                                                                    ]?.language ??
                                                                                    example.language,
                                                                                filename:
                                                                                    drafts[
                                                                                        example.id
                                                                                    ]?.filename ??
                                                                                    example.filename ??
                                                                                    '',
                                                                                code,
                                                                            },
                                                                        }),
                                                                    )
                                                                }
                                                            />
                                                            <InputError
                                                                message={errors.language || errors.code}
                                                            />
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={processing}
                                                                className="w-fit"
                                                            >
                                                                Save
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>
                                                <ConfirmActionDialog
                                                    form={destroyCodeExample.form([item.id, example.id])}
                                                    title="Delete code example?"
                                                    description="This code example will be permanently removed from this knowledge item."
                                                    triggerLabel="Delete"
                                                    confirmLabel="Delete"
                                                    className="mt-2"
                                                />
                                                </div>
                                            );
                                        })
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Resources</CardTitle>
                                <CardDescription>
                                    Link resources only. Keep references lightweight and searchable.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <Form
                                    ref={createKnowledgeResourceFormRef}
                                    {...storeKnowledgeResource.form(item.id)}
                                    options={{ preserveScroll: true }}
                                    onBefore={() =>
                                        validateFormWithSchema(
                                            knowledgeResourceCreateSchema,
                                            createKnowledgeResourceFormRef,
                                        )
                                    }
                                    resetOnSuccess
                                    className="grid gap-3 rounded-lg border p-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <p className="text-sm font-medium">Add link resource</p>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <Input type="hidden" name="type" value="link" />
                                                <Input
                                                    name="label"
                                                    placeholder="Label (optional)"
                                                    maxLength={255}
                                                />
                                                <Input
                                                    name="url"
                                                    type="url"
                                                    placeholder="https://..."
                                                    required
                                                />
                                            </div>
                                            <Textarea
                                                name="extracted_text"
                                                rows={4}
                                                className="min-h-24"
                                                placeholder="Extracted text (optional)"
                                            />
                                            <InputError message={errors.type || errors.url} />
                                            <Button type="submit" disabled={processing}>
                                                Add resource
                                            </Button>
                                        </>
                                    )}
                                </Form>

                                <div className="space-y-4">
                                    {linkResources.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No link resources yet.
                                        </p>
                                    ) : (
                                        linkResources.map((resource) => (
                                            <div key={resource.id} className="rounded-lg border p-4">
                                                <Form
                                                    {...updateKnowledgeResource.form([item.id, resource.id])}
                                                    options={{ preserveScroll: true }}
                                                    className="grid gap-3"
                                                >
                                                    {({ processing, errors }) => (
                                                        <>
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <Badge variant="outline">
                                                                    {resource.type}
                                                                </Badge>
                                                                <p className="text-xs text-muted-foreground">
                                                                    Extraction attempts:{' '}
                                                                    {resource.extract_attempts}
                                                                </p>
                                                                <p className="text-xs text-muted-foreground">
                                                                    Extracted:{' '}
                                                                    {resource.extracted_at
                                                                        ? new Date(
                                                                              resource.extracted_at,
                                                                          ).toLocaleString()
                                                                        : 'No'}
                                                                </p>
                                                            </div>

                                                            <div className="grid gap-3 sm:grid-cols-2">
                                                                <Input
                                                                    type="hidden"
                                                                    name="type"
                                                                    value="link"
                                                                />
                                                                <Input
                                                                    name="label"
                                                                    defaultValue={resource.label ?? ''}
                                                                    placeholder="Label"
                                                                />
                                                                <Input
                                                                    name="url"
                                                                    defaultValue={resource.url ?? ''}
                                                                    type="url"
                                                                    placeholder="https://..."
                                                                    required
                                                                />
                                                            </div>

                                                            <Textarea
                                                                name="extracted_text"
                                                                rows={5}
                                                                defaultValue={resource.extracted_text ?? ''}
                                                                className="min-h-24"
                                                            />

                                                            {resource.extract_error ? (
                                                                <p className="text-sm text-red-600 dark:text-red-400">
                                                                    {resource.extract_error}
                                                                </p>
                                                            ) : null}

                                                            <InputError message={errors.type || errors.url} />

                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={processing}
                                                                className="w-fit"
                                                            >
                                                                Save
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>
                                                <ConfirmActionDialog
                                                    form={destroyKnowledgeResource.form([item.id, resource.id])}
                                                    title="Delete resource?"
                                                    description="This resource link will be permanently removed from this knowledge item."
                                                    triggerLabel="Delete"
                                                    confirmLabel="Delete"
                                                    className="mt-2"
                                                />
                                            </div>
                                        ))
                                    )}
                                </div>
                                {nonLinkResourcesCount > 0 ? (
                                    <p className="text-xs text-muted-foreground">
                                        {nonLinkResourcesCount} legacy file resource(s) exist and
                                        are read-only in this links-only editor.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>
                    </>
                ) : null}

                {isEditing && activeStep === 'enhancements' && !canUpdate ? (
                    <>
                        <Card className="rounded-2xl border border-neutral-200/70 bg-card/95 dark:border-neutral-800/70">
                            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                                <div className="space-y-1.5">
                                    <CardTitle>Step 2: Optional code and resources</CardTitle>
                                    <CardDescription>
                                        Read-only access for shared item attachments.
                                    </CardDescription>
                                </div>
                                <Button asChild variant="outline" size="sm">
                                    <Link href={detailsStepHref}>Back to details</Link>
                                </Button>
                            </CardHeader>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Code examples</CardTitle>
                                <CardDescription>
                                    Read-only mode for shared item access.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {codeExamples.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No code examples linked.
                                        </p>
                                    ) : (
                                        codeExamples.map((example) => (
                                            <div key={example.id} className="rounded-lg border p-4">
                                                <div className="mb-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                    <Badge variant="secondary">
                                                        {example.language}
                                                    </Badge>
                                                    {example.title ? <span>{example.title}</span> : null}
                                                    {example.filename ? (
                                                        <span>{example.filename}</span>
                                                    ) : null}
                                                </div>
                                                {example.description ? (
                                                    <p className="mb-2 text-sm text-muted-foreground">
                                                        {example.description}
                                                    </p>
                                                ) : null}
                                                <HighlightCodeBlock
                                                    code={example.code}
                                                    language={example.language}
                                                    filename={example.filename}
                                                />
                                            </div>
                                        ))
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Resources</CardTitle>
                                <CardDescription>
                                    Read-only mode for shared item access.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {linkResources.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No resources linked.
                                        </p>
                                    ) : (
                                        linkResources.map((resource) => (
                                            <div key={resource.id} className="rounded-lg border p-4">
                                                <div className="mb-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                    <Badge variant="outline">{resource.type}</Badge>
                                                    {resource.label ? (
                                                        <span>{resource.label}</span>
                                                    ) : null}
                                                </div>
                                                {resource.url ? (
                                                    <a
                                                        href={resource.url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="text-sm underline"
                                                    >
                                                        {resource.url}
                                                    </a>
                                                ) : null}
                                                {resource.extracted_text ? (
                                                    <p className="mt-2 text-sm text-muted-foreground whitespace-pre-wrap">
                                                        {resource.extracted_text}
                                                    </p>
                                                ) : null}
                                            </div>
                                        ))
                                    )}
                                </div>
                                {nonLinkResourcesCount > 0 ? (
                                    <p className="mt-3 text-xs text-muted-foreground">
                                        {nonLinkResourcesCount} legacy file resource(s) are hidden
                                        in this links-only view.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>
                    </>
                ) : null}
            </div>
        </AppLayout>
    );
}
