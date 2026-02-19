import { useEffect, useMemo, useRef, useState } from 'react';
import { highlightCode, normalizeCodeLanguage } from '@/lib/highlight';

export default function HighlightCodeBlock({
    code,
    language,
    filename,
}: {
    code: string;
    language: string;
    filename?: string | null;
}) {
    const normalizedLanguage = useMemo(() => normalizeCodeLanguage(language), [language]);
    const highlightedHtml = useMemo(
        () => highlightCode(code, normalizedLanguage),
        [code, normalizedLanguage],
    );
    const [isCopied, setIsCopied] = useState(false);
    const copyResetTimeoutRef = useRef<number | null>(null);

    useEffect(() => {
        return () => {
            if (copyResetTimeoutRef.current !== null) {
                window.clearTimeout(copyResetTimeoutRef.current);
            }
        };
    }, []);

    const copyCode = async (): Promise<void> => {
        if (
            typeof navigator === 'undefined' ||
            typeof navigator.clipboard === 'undefined'
        ) {
            return;
        }

        try {
            await navigator.clipboard.writeText(code);
            setIsCopied(true);

            if (copyResetTimeoutRef.current !== null) {
                window.clearTimeout(copyResetTimeoutRef.current);
            }

            copyResetTimeoutRef.current = window.setTimeout(() => {
                setIsCopied(false);
            }, 1500);
        } catch {
            setIsCopied(false);
        }
    };

    return (
        <div className="overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/40 px-3 py-2 text-xs">
                <span className="truncate font-medium text-foreground">
                    {filename || 'code snippet'}
                </span>
                <button
                    type="button"
                    onClick={() => void copyCode()}
                    className="group shrink-0 whitespace-nowrap lowercase text-muted-foreground transition hover:text-foreground"
                    aria-label={`Copy ${language} code`}
                    title={isCopied ? 'Copied' : 'Copy code'}
                >
                    {isCopied ? (
                        'copied'
                    ) : (
                        <>
                            <span className="group-hover:hidden">{language}</span>
                            <span className="hidden group-hover:inline">copy</span>
                        </>
                    )}
                </button>
            </div>
            <pre className="overflow-x-auto bg-muted/20 p-4 text-sm leading-6">
                <code
                    className={`hljs block min-w-full w-max font-mono language-${normalizedLanguage}`}
                    style={{ backgroundColor: 'transparent', padding: 0 }}
                    dangerouslySetInnerHTML={{ __html: highlightedHtml }}
                />
            </pre>
        </div>
    );
}
