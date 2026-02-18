import { useMemo } from 'react';
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

    return (
        <div className="overflow-hidden rounded-xl border border-neutral-700/80 bg-neutral-950">
            <div className="flex items-center justify-between border-b border-neutral-700/80 bg-neutral-900 px-3 py-2 text-xs text-neutral-200">
                <span className="font-medium">{filename || 'code snippet'}</span>
                <span className="text-neutral-400">{language}</span>
            </div>
            <pre className="overflow-x-auto p-4 text-sm text-neutral-100">
                <code
                    className={`hljs block language-${normalizedLanguage}`}
                    dangerouslySetInnerHTML={{ __html: highlightedHtml }}
                />
            </pre>
        </div>
    );
}
