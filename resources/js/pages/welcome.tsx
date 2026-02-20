import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Bot, Database, Search, ShieldCheck } from 'lucide-react';
import { dashboard, home, login, register } from '@/routes';

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

const featureHighlights = [
    {
        title: 'Semantic Retrieval',
        description:
            'Blend vector search and lexical ranking to surface useful answers fast.',
        icon: Search,
    },
    {
        title: 'MCP-Native Access',
        description:
            'Expose your internal knowledge to AI clients through a secure MCP endpoint.',
        icon: Bot,
    },
    {
        title: 'Role-Aware Controls',
        description:
            'Keep sensitive docs private with account-level access and editor controls.',
        icon: ShieldCheck,
    },
];

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth, name } = usePage().props;
    const wordmark = resolveWordmark(name);

    return (
        <>
            <Head title="Knowledge Workspace">
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link
                    href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700"
                    rel="stylesheet"
                />
            </Head>

            <div className="relative min-h-screen overflow-hidden  text-neutral-950 dark:text-neutral-100">
                <header className="relative z-10 mx-auto flex w-full max-w-6xl items-center justify-between px-6 pt-8">
                    <Link href={home()} className="inline-flex flex-col leading-none">
                        <span className="text-[0.62rem] font-medium tracking-[0.24em] text-neutral-600 uppercase dark:text-neutral-400">
                            AI Knowledge Workspace
                        </span>
                        <span className="text-base font-semibold tracking-tight">
                            {wordmark}
                        </span>
                    </Link>

                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="inline-flex items-center gap-2 rounded-full border border-neutral-900/15 bg-white/80 px-4 py-2 text-sm font-medium text-neutral-900 backdrop-blur-sm transition hover:border-neutral-900/30 hover:bg-white dark:border-neutral-100/20 dark:bg-neutral-900/60 dark:text-neutral-100 dark:hover:border-neutral-100/40"
                            >
                                Open Dashboard
                                <ArrowRight className="h-4 w-4" />
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="rounded-full border border-transparent px-4 py-2 text-sm font-medium text-neutral-700 transition hover:border-neutral-900/15 hover:bg-white/70 dark:text-neutral-200 dark:hover:border-neutral-100/20 dark:hover:bg-neutral-900/60"
                                >
                                    Log in
                                </Link>
                                {canRegister && (
                                    <Link
                                        href={register()}
                                        className="inline-flex items-center gap-2 rounded-full border border-neutral-900/15 bg-white/85 px-4 py-2 text-sm font-medium text-neutral-900 backdrop-blur-sm transition hover:border-neutral-900/30 hover:bg-white dark:border-neutral-100/20 dark:bg-neutral-900/60 dark:text-neutral-100 dark:hover:border-neutral-100/40"
                                    >
                                        Start Free
                                        <ArrowRight className="h-4 w-4" />
                                    </Link>
                                )}
                            </>
                        )}
                    </nav>
                </header>

                <main className="relative z-10 mx-auto w-full max-w-6xl px-6 pb-16 pt-14 lg:pt-20">
                    <section className="grid gap-8 lg:grid-cols-[1.15fr_0.85fr]">
                        <div className="space-y-6">
                            <p className="inline-flex items-center gap-2 rounded-full border border-neutral-900/10 bg-white/70 px-4 py-1 text-xs font-medium tracking-[0.2em] text-neutral-600 uppercase backdrop-blur-sm dark:border-neutral-100/20 dark:bg-neutral-900/50 dark:text-neutral-300">
                                <Database className="h-3.5 w-3.5" />
                                Team + AI Ready
                            </p>

                            <h1 className="max-w-xl text-4xl leading-tight font-semibold tracking-tight text-balance sm:text-5xl">
                                Your internal knowledge layer for people and MCP clients.
                            </h1>

                            <p className="max-w-2xl text-base leading-relaxed text-neutral-700 dark:text-neutral-300">
                                Write and organize docs once. Serve the same knowledge to your
                                team in the web app and to AI tools through an authenticated MCP
                                endpoint.
                            </p>

                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="rounded-2xl border border-neutral-900/10 bg-white/75 px-4 py-3 backdrop-blur-sm dark:border-neutral-100/15 dark:bg-neutral-900/50">
                                    <p className="text-xs font-medium tracking-[0.16em] text-neutral-500 uppercase dark:text-neutral-400">
                                        Search Stack
                                    </p>
                                    <p className="mt-1 text-sm font-semibold">Vector + FTS</p>
                                </div>
                                <div className="rounded-2xl border border-neutral-900/10 bg-white/75 px-4 py-3 backdrop-blur-sm dark:border-neutral-100/15 dark:bg-neutral-900/50">
                                    <p className="text-xs font-medium tracking-[0.16em] text-neutral-500 uppercase dark:text-neutral-400">
                                        Endpoint
                                    </p>
                                    <p className="mt-1 text-sm font-semibold">/mcp/knowledge</p>
                                </div>
                                <div className="rounded-2xl border border-neutral-900/10 bg-white/75 px-4 py-3 backdrop-blur-sm dark:border-neutral-100/15 dark:bg-neutral-900/50">
                                    <p className="text-xs font-medium tracking-[0.16em] text-neutral-500 uppercase dark:text-neutral-400">
                                        Auth
                                    </p>
                                    <p className="mt-1 text-sm font-semibold">Sanctum Tokens</p>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-3xl border border-neutral-900/10 bg-white/80 p-6 shadow-xl backdrop-blur-sm dark:border-neutral-100/15 dark:bg-neutral-900/55 dark:shadow-black/30">
                            <p className="text-[0.66rem] font-medium tracking-[0.24em] text-neutral-500 uppercase dark:text-neutral-400">
                                MCP Endpoint Preview
                            </p>
                            <div className="mt-3 rounded-2xl border border-neutral-900/10 bg-neutral-950 px-4 py-4 text-sm text-neutral-100 dark:border-neutral-100/20">
                                <p className="font-mono">POST /mcp/knowledge</p>
                                <p className="mt-2 font-mono text-xs text-neutral-400">
                                    Authorization: Bearer &lt;token&gt;
                                </p>
                            </div>

                            <div className="mt-5 space-y-3">
                                {featureHighlights.map((feature) => (
                                    <div
                                        key={feature.title}
                                        className="rounded-2xl border border-neutral-900/10 bg-white/70 px-4 py-3 dark:border-neutral-100/15 dark:bg-neutral-900/45"
                                    >
                                        <div className="flex items-start gap-3">
                                            <div className="mt-0.5 rounded-lg border border-neutral-900/10 bg-neutral-50 p-2 dark:border-neutral-100/20 dark:bg-neutral-800">
                                                <feature.icon className="h-4 w-4" />
                                            </div>
                                            <div className="space-y-1">
                                                <h2 className="text-sm font-semibold">
                                                    {feature.title}
                                                </h2>
                                                <p className="text-xs leading-relaxed text-neutral-600 dark:text-neutral-300">
                                                    {feature.description}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>
                </main>
            </div>
        </>
    );
}
