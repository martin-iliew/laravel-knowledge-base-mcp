import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import FormSelect from '@/components/form-select';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Knowledge access',
        href: '/settings/knowledge-access',
    },
];

function formatDate(value: string | null): string {
    if (!value) {
        return 'Unknown';
    }

    return new Date(value).toLocaleString();
}

export default function KnowledgeAccessSettings({
    grants,
    receivedAccess,
}: {
    grants: Array<{
        id: number;
        permission: 'viewer' | 'editor';
        created_at: string | null;
        grantee: {
            id: number | null;
            name: string | null;
            email: string | null;
        };
    }>;
    receivedAccess: Array<{
        id: number;
        permission: 'viewer' | 'editor';
        created_at: string | null;
        owner: {
            id: number | null;
            name: string | null;
            email: string | null;
        };
    }>;
}) {
    const [newGrantPermission, setNewGrantPermission] = useState('viewer');
    const [grantPermissions, setGrantPermissions] = useState<
        Record<number, string>
    >(() =>
        grants.reduce<Record<number, string>>((carry, grant) => {
            carry[grant.id] = grant.permission;

            return carry;
        }, {}),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Knowledge Access Settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Knowledge Account Access"
                        description="Grant account-level access so teammates can read or edit your knowledge base."
                    />

                    <Card>
                        <CardHeader>
                            <CardTitle>Grant access</CardTitle>
                            <CardDescription>
                                Access is account-wide. Use viewer for read-only or editor for write access.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                action="/settings/knowledge-access"
                                method="post"
                                options={{ preserveScroll: true }}
                                className="grid gap-4 sm:grid-cols-[1fr_auto_auto]"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="email">User email</Label>
                                            <Input
                                                id="email"
                                                name="email"
                                                type="email"
                                                placeholder="teammate@company.com"
                                                required
                                            />
                                            <InputError message={errors.email} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="permission">Permission</Label>
                                            <FormSelect
                                                id="permission"
                                                name="permission"
                                                value={newGrantPermission}
                                                onValueChange={setNewGrantPermission}
                                                options={[
                                                    {
                                                        value: 'viewer',
                                                        label: 'Viewer',
                                                    },
                                                    {
                                                        value: 'editor',
                                                        label: 'Editor',
                                                    },
                                                ]}
                                            />
                                            <InputError message={errors.permission} />
                                        </div>
                                        <div className="flex items-end">
                                            <Button type="submit" disabled={processing}>
                                                Grant
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Access you granted</CardTitle>
                            <CardDescription>
                                Update or revoke account-level access for teammates.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {grants.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No grants yet.
                                    </p>
                                ) : (
                                    grants.map((grant) => (
                                        <div
                                            key={grant.id}
                                            className="rounded-lg border p-3"
                                        >
                                            <div className="mb-3 space-y-1">
                                                <p className="text-sm font-medium">
                                                    {grant.grantee.name ?? 'Unknown user'}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {grant.grantee.email ?? 'Unknown email'} · Granted{' '}
                                                    {formatDate(grant.created_at)}
                                                </p>
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2">
                                                <Form
                                                    action={`/settings/knowledge-access/${grant.id}`}
                                                    method="patch"
                                                    options={{ preserveScroll: true }}
                                                    className="flex items-center gap-2"
                                                >
                                                    {({ processing }) => (
                                                        <>
                                                            <FormSelect
                                                                name="permission"
                                                                value={
                                                                    grantPermissions[grant.id] ??
                                                                    grant.permission
                                                                }
                                                                onValueChange={(permission) =>
                                                                    setGrantPermissions(
                                                                        (currentPermissions) => ({
                                                                            ...currentPermissions,
                                                                            [grant.id]: permission,
                                                                        }),
                                                                    )
                                                                }
                                                                options={[
                                                                    {
                                                                        value: 'viewer',
                                                                        label: 'Viewer',
                                                                    },
                                                                    {
                                                                        value: 'editor',
                                                                        label: 'Editor',
                                                                    },
                                                                ]}
                                                            />
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={processing}
                                                            >
                                                                Save
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>

                                                <Form
                                                    action={`/settings/knowledge-access/${grant.id}`}
                                                    method="delete"
                                                    onBefore={() =>
                                                        window.confirm(
                                                            'Revoke this access grant?',
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
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Access granted to you</CardTitle>
                            <CardDescription>
                                These owners shared their account knowledge base with you.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                {receivedAccess.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No incoming access grants.
                                    </p>
                                ) : (
                                    receivedAccess.map((grant) => (
                                        <div
                                            key={grant.id}
                                            className="rounded-lg border p-3"
                                        >
                                            <p className="text-sm font-medium">
                                                {grant.owner.name ?? 'Unknown owner'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {grant.owner.email ?? 'Unknown email'} · {grant.permission}{' '}
                                                access
                                            </p>
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
