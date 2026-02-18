import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Token settings',
        href: '/settings/mcp-token',
    },
];

function formatDate(value: string | null): string {
    if (!value) {
        return 'Never';
    }

    return new Date(value).toLocaleString();
}

export default function McpTokenSettings({
    tokens,
    plainTextToken,
    createdTokenName,
}: {
    tokens: Array<{
        id: number;
        name: string;
        created_at: string | null;
        last_used_at: string | null;
    }>;
    plainTextToken: string | null;
    createdTokenName: string | null;
}) {
    const [copiedText, copy] = useClipboard();
    const [showToken, setShowToken] = useState(true);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="MCP Token Settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="MCP / Claude Code Token"
                        description="Create or revoke personal access tokens for the MCP server."
                    />

                    <Card>
                        <CardHeader>
                            <CardTitle>Generate token</CardTitle>
                            <CardDescription>
                                The plaintext token is shown once. Store it securely.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                action="/settings/mcp-token"
                                method="post"
                                options={{ preserveScroll: true }}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">Token name</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                defaultValue="Claude Code"
                                                placeholder="Claude Code"
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <Button type="submit" disabled={processing}>
                                            Generate token
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    {plainTextToken && showToken ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    New token{createdTokenName ? `: ${createdTokenName}` : ''}
                                </CardTitle>
                                <CardDescription>
                                    Copy this now. It will not be shown again.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <code className="block overflow-x-auto rounded-md border bg-muted px-3 py-2 text-xs">
                                    {plainTextToken}
                                </code>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => copy(plainTextToken)}
                                    >
                                        {copiedText === plainTextToken
                                            ? 'Copied'
                                            : 'Copy token'}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setShowToken(false)}
                                    >
                                        Hide
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card>
                        <CardHeader>
                            <CardTitle>Existing tokens</CardTitle>
                            <CardDescription>
                                Use as a Bearer token for MCP requests protected by
                                auth:sanctum.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {tokens.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No personal access tokens found.
                                    </p>
                                ) : (
                                    tokens.map((token) => (
                                        <div
                                            key={token.id}
                                            className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="space-y-1">
                                                <p className="text-sm font-medium">
                                                    {token.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Created {formatDate(token.created_at)}. Last used{' '}
                                                    {formatDate(token.last_used_at)}.
                                                </p>
                                            </div>
                                            <Form
                                                action={`/settings/mcp-token/${token.id}`}
                                                method="delete"
                                                onBefore={() =>
                                                    window.confirm(
                                                        'Revoke this token? Clients using it will lose access immediately.',
                                                    )
                                                }
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="destructive"
                                                        disabled={processing}
                                                    >
                                                        Revoke
                                                    </Button>
                                                )}
                                            </Form>
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
