import { useLayoutEffect, useMemo, useRef, useState } from 'react';
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
    onChange,
    rows = 12,
    minHeightPx = 320,
    disabled = false,
}: {
    name: string;
    value: string;
    language: string;
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

    const highlightedHtml = useMemo(() => {
        if (value === '') {
            return '<span class="kb-code-placeholder">Write or paste code here...</span>';
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
        <div className="kb-code-editor">
            <pre
                ref={highlightedRef}
                aria-hidden="true"
                className="kb-code-editor-highlight"
                style={{ minHeight: `${minimumHeightPx}px`, height: dynamicHeight }}
            >
                <code
                    className={`hljs block language-${normalizedLanguage}`}
                    dangerouslySetInnerHTML={{ __html: highlightedHtml }}
                />
            </pre>
            <textarea
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
                className="kb-code-editor-input"
                style={{ minHeight: `${minimumHeightPx}px`, height: dynamicHeight }}
                aria-label="Code editor"
            />
        </div>
    );
}
