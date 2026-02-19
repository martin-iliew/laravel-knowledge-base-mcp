import { useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Textarea } from '@/components/ui/textarea';
import { highlightCode, normalizeCodeLanguage } from '@/lib/highlight';

const TAB_SPACES = '    ';

function focusSelection(position: number, textarea: HTMLTextAreaElement | null): void {
    if (!textarea) {
        return;
    }

    textarea.focus();
    textarea.setSelectionRange(position, position);
}

export default function HighlightCodeEditor({
    name,
    value,
    language,
    filename = null,
    onChange,
    rows = 12,
    minHeightPx = 320,
    disabled = false,
}: {
    name: string;
    value: string;
    language: string;
    filename?: string | null;
    onChange: (value: string) => void;
    rows?: number;
    minHeightPx?: number;
    disabled?: boolean;
}) {
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);
    const highlightedRef = useRef<HTMLPreElement | null>(null);
    const [editorHeightPx, setEditorHeightPx] = useState<number>(
        Math.max(minHeightPx, rows * 24),
    );
    const normalizedLanguage = useMemo(
        () => normalizeCodeLanguage(language),
        [language],
    );
    const minimumHeightPx = Math.max(minHeightPx, rows * 24);
    const dynamicHeight = `${editorHeightPx}px`;
    const displayFilename =
        typeof filename === 'string' && filename.trim() !== ''
            ? filename.trim()
            : 'code snippet';

    const highlightedHtml = useMemo(() => {
        if (value === '') {
            return '<span class="text-muted-foreground">Write or paste code here...</span>';
        }

        return highlightCode(value, normalizedLanguage);
    }, [normalizedLanguage, value]);

    useLayoutEffect(() => {
        const textarea = textareaRef.current;

        if (!textarea) {
            return;
        }

        textarea.style.height = '0px';
        const nextHeight = Math.max(minimumHeightPx, textarea.scrollHeight);
        textarea.style.height = `${nextHeight}px`;
        setEditorHeightPx(nextHeight);
    }, [minimumHeightPx, value]);

    return (
        <div className="overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/40 px-3 py-2 text-xs">
                <span className="truncate font-medium text-foreground">{displayFilename}</span>
                <span className="shrink-0 lowercase text-muted-foreground">{language}</span>
            </div>
            <div className="relative">
                <pre
                    ref={highlightedRef}
                    aria-hidden="true"
                    className="m-0 overflow-auto bg-muted/20 p-4 text-sm leading-6 [tab-size:4]"
                    style={{ minHeight: `${minimumHeightPx}px`, height: dynamicHeight }}
                >
                    <code
                        className={`hljs block min-w-full w-max font-mono language-${normalizedLanguage}`}
                        style={{ backgroundColor: 'transparent', padding: 0 }}
                        dangerouslySetInnerHTML={{ __html: highlightedHtml }}
                    />
                </pre>
                <Textarea
                    ref={textareaRef}
                    name={name}
                    value={value}
                    rows={rows}
                    wrap="off"
                    disabled={disabled}
                    spellCheck={false}
                    onChange={(event) => {
                        onChange(event.target.value);

                        const textarea = textareaRef.current;

                        if (!textarea) {
                            return;
                        }

                        textarea.style.height = '0px';
                        const nextHeight = Math.max(minimumHeightPx, textarea.scrollHeight);
                        textarea.style.height = `${nextHeight}px`;
                        setEditorHeightPx(nextHeight);
                    }}
                    onKeyDown={(event) => {
                        if (event.key !== 'Tab' || disabled) {
                            return;
                        }

                        event.preventDefault();

                        const target = event.currentTarget;
                        const start = target.selectionStart;
                        const end = target.selectionEnd;
                        const nextValue = `${value.slice(0, start)}${TAB_SPACES}${value.slice(end)}`;

                        onChange(nextValue);

                        window.requestAnimationFrame(() => {
                            focusSelection(start + TAB_SPACES.length, textareaRef.current);

                            const textarea = textareaRef.current;

                            if (!textarea) {
                                return;
                            }

                            textarea.style.height = '0px';
                            const nextHeight = Math.max(minimumHeightPx, textarea.scrollHeight);
                            textarea.style.height = `${nextHeight}px`;
                            setEditorHeightPx(nextHeight);
                        });
                    }}
                    onScroll={(event) => {
                        if (!highlightedRef.current) {
                            return;
                        }

                        highlightedRef.current.scrollTop = event.currentTarget.scrollTop;
                        highlightedRef.current.scrollLeft = event.currentTarget.scrollLeft;
                    }}
                    className="absolute inset-0 m-0 w-full resize-none overflow-auto border-0 bg-transparent p-4 font-mono text-sm leading-6 text-transparent caret-foreground outline-none [tab-size:4] [-webkit-text-fill-color:transparent] selection:bg-ring/25 focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-60"
                    style={{ minHeight: `${minimumHeightPx}px`, height: dynamicHeight }}
                    aria-label={`Code editor for ${language}`}
                />
            </div>
        </div>
    );
}
