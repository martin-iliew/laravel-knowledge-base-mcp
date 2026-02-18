import {
    Bold,
    Code2,
    Eye,
    Heading1,
    Heading2,
    Heading3,
    Italic,
    Link2,
    List,
    ListOrdered,
    Quote,
    Redo2,
    SeparatorHorizontal,
    Undo2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { highlightMarkdownCodeBlocks } from '@/lib/highlight';

type EditorMode = 'visual' | 'raw' | 'preview';

const EMPTY_PREVIEW_HTML =
    '<p class="text-sm text-muted-foreground">Start writing content to see a formatted preview.</p>';

function markdownTableFromElement(table: HTMLTableElement): string {
    const rows = Array.from(table.querySelectorAll('tr')).map((row) =>
        Array.from(row.querySelectorAll('th,td')).map((cell) =>
            (cell.textContent ?? '').trim(),
        ),
    );

    if (rows.length === 0) {
        return '';
    }

    const header = rows[0] ?? [];
    const body = rows.slice(1);
    const separator = header.map(() => '---');

    const lines = [
        `| ${header.join(' | ')} |`,
        `| ${separator.join(' | ')} |`,
        ...body.map((row) => `| ${row.join(' | ')} |`),
    ];

    return `${lines.join('\n')}\n\n`;
}

function inlineNodesToMarkdown(nodes: Node[]): string {
    return nodes.map((node) => inlineNodeToMarkdown(node)).join('');
}

function inlineNodeToMarkdown(node: Node): string {
    if (node.nodeType === Node.TEXT_NODE) {
        return (node.textContent ?? '').replace(/\u00a0/g, ' ');
    }

    if (!(node instanceof HTMLElement)) {
        return '';
    }

    const children = inlineNodesToMarkdown(Array.from(node.childNodes));
    const tag = node.tagName.toLowerCase();

    if (tag === 'br') {
        return '\n';
    }

    if (tag === 'strong' || tag === 'b') {
        return `**${children.trim()}**`;
    }

    if (tag === 'em' || tag === 'i') {
        return `*${children.trim()}*`;
    }

    if (tag === 'code') {
        if (node.parentElement?.tagName.toLowerCase() === 'pre') {
            return children;
        }

        return `\`${children.trim()}\``;
    }

    if (tag === 'a') {
        const href = (node.getAttribute('href') ?? '').trim();
        const label = children.trim() || href;

        if (href === '') {
            return label;
        }

        return `[${label}](${href})`;
    }

    return children;
}

function blockNodeToMarkdown(node: Node): string {
    if (node.nodeType === Node.TEXT_NODE) {
        return (node.textContent ?? '').trim();
    }

    if (!(node instanceof HTMLElement)) {
        return '';
    }

    const tag = node.tagName.toLowerCase();
    const childNodes = Array.from(node.childNodes);
    const hasNestedBlocks = childNodes.some((child) => {
        if (!(child instanceof HTMLElement)) {
            return false;
        }

        return [
            'p',
            'div',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'ul',
            'ol',
            'pre',
            'blockquote',
            'table',
            'hr',
        ].includes(child.tagName.toLowerCase());
    });

    if (tag === 'pre') {
        const codeElement = node.querySelector('code');
        const classMatch = codeElement?.className.match(/(?:lang|language)-([a-z0-9_+-]+)/i);
        const language = classMatch?.[1] ?? '';
        const code = (codeElement?.textContent ?? node.textContent ?? '')
            .replace(/\u00a0/g, ' ')
            .replace(/\n+$/, '');

        return `\`\`\`${language}\n${code}\n\`\`\`\n\n`;
    }

    if (tag === 'blockquote') {
        const quoteContent = childNodes.map((child) => blockNodeToMarkdown(child)).join('');
        const quoted = quoteContent
            .trim()
            .split('\n')
            .map((line) => (line.trim() === '' ? '>' : `> ${line}`))
            .join('\n');

        return `${quoted}\n\n`;
    }

    if (tag === 'ul' || tag === 'ol') {
        const items = Array.from(node.children).filter(
            (child): child is HTMLElement => child.tagName.toLowerCase() === 'li',
        );

        const lines = items.map((item, index) => {
            const line = inlineNodesToMarkdown(Array.from(item.childNodes)).trim();

            if (tag === 'ol') {
                return `${index + 1}. ${line}`;
            }

            return `- ${line}`;
        });

        return `${lines.join('\n')}\n\n`;
    }

    if (tag === 'table') {
        return markdownTableFromElement(node as HTMLTableElement);
    }

    if (tag === 'hr') {
        return '\n---\n\n';
    }

    if (/^h[1-6]$/.test(tag)) {
        const level = Number.parseInt(tag.slice(1), 10);
        const heading = inlineNodesToMarkdown(childNodes).trim();

        return `${'#'.repeat(level)} ${heading}\n\n`;
    }

    if (tag === 'p' || tag === 'div') {
        if (hasNestedBlocks) {
            return childNodes.map((child) => blockNodeToMarkdown(child)).join('');
        }

        const text = inlineNodesToMarkdown(childNodes).trim();

        if (text === '') {
            return '';
        }

        return `${text}\n\n`;
    }

    const fallback = inlineNodesToMarkdown(childNodes).trim();

    if (fallback === '') {
        return '';
    }

    return `${fallback}\n\n`;
}

function htmlToMarkdown(html: string): string {
    if (html.trim() === '') {
        return '';
    }

    const parser = new DOMParser();
    const document = parser.parseFromString(`<div>${html}</div>`, 'text/html');
    const root = document.body.firstElementChild;

    if (!root) {
        return '';
    }

    return Array.from(root.childNodes)
        .map((node) => blockNodeToMarkdown(node))
        .join('')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

export default function KnowledgeMarkdownEditor({
    value,
    onChange,
    disabled = false,
}: {
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
}) {
    const [mode, setMode] = useState<EditorMode>(disabled ? 'preview' : 'visual');
    const [previewHtml, setPreviewHtml] = useState<string>(EMPTY_PREVIEW_HTML);
    const [isLoadingPreview, setIsLoadingPreview] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [isVisualFocused, setIsVisualFocused] = useState(false);
    const rawTextareaRef = useRef<HTMLTextAreaElement | null>(null);
    const previewRef = useRef<HTMLDivElement | null>(null);
    const visualEditorRef = useRef<HTMLDivElement | null>(null);
    const savedRangeRef = useRef<Range | null>(null);
    const hasVisualInputRef = useRef(false);

    const csrfToken = useMemo(() => {
        if (typeof document === 'undefined') {
            return '';
        }

        const tokenElement = document.querySelector('meta[name="csrf-token"]');

        if (!tokenElement) {
            return '';
        }

        return tokenElement.getAttribute('content') ?? '';
    }, []);

    useEffect(() => {
        if (disabled && mode !== 'preview') {
            setMode('preview');
        }
    }, [disabled, mode]);

    useEffect(() => {
        const trimmed = value.trim();

        if (trimmed === '') {
            setPreviewHtml(EMPTY_PREVIEW_HTML);
            setPreviewError(null);
            setIsLoadingPreview(false);

            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(async () => {
            setIsLoadingPreview(true);
            setPreviewError(null);

            try {
                const body = new URLSearchParams({
                    content_markdown: value,
                }).toString();

                const response = await fetch('/knowledge-items/markdown-preview', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body,
                    signal: controller.signal,
                });

                if (!response.ok) {
                    if (response.status === 419) {
                        throw new Error('Session expired. Refresh the page and try again.');
                    }

                    throw new Error('Failed to render preview.');
                }

                const payload = (await response.json()) as { html?: string };
                setPreviewHtml(payload.html ?? EMPTY_PREVIEW_HTML);
            } catch (error) {
                if (controller.signal.aborted) {
                    return;
                }

                setPreviewError(
                    error instanceof Error
                        ? error.message
                        : 'Could not render preview.',
                );
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoadingPreview(false);
                }
            }
        }, 220);

        return () => {
            controller.abort();
            window.clearTimeout(timeout);
        };
    }, [csrfToken, value]);

    useEffect(() => {
        if (isVisualFocused && hasVisualInputRef.current) {
            return;
        }

        const nextVisualHtml = previewHtml === EMPTY_PREVIEW_HTML ? '' : previewHtml;
        if (visualEditorRef.current && mode === 'visual') {
            visualEditorRef.current.innerHTML = nextVisualHtml;
        }

        if (!isVisualFocused) {
            hasVisualInputRef.current = false;
        }
    }, [isVisualFocused, mode, previewHtml]);

    useEffect(() => {
        highlightMarkdownCodeBlocks(previewRef.current);
    }, [mode, previewHtml]);

    useEffect(() => {
        const captureSelection = (): void => {
            const editor = visualEditorRef.current;

            if (!editor) {
                return;
            }

            const selection = window.getSelection();

            if (!selection || selection.rangeCount === 0) {
                return;
            }

            const range = selection.getRangeAt(0);

            if (!editor.contains(range.startContainer)) {
                return;
            }

            savedRangeRef.current = range.cloneRange();
        };

        document.addEventListener('selectionchange', captureSelection);

        return () => {
            document.removeEventListener('selectionchange', captureSelection);
        };
    }, []);

    const restoreSelection = (): void => {
        const editor = visualEditorRef.current;

        if (!editor) {
            return;
        }

        const selection = window.getSelection();

        if (!selection) {
            return;
        }

        selection.removeAllRanges();

        if (savedRangeRef.current && editor.contains(savedRangeRef.current.startContainer)) {
            selection.addRange(savedRangeRef.current.cloneRange());

            return;
        }

        const range = document.createRange();
        range.selectNodeContents(editor);
        range.collapse(false);
        selection.addRange(range);
    };

    const pushVisualHtmlToMarkdown = (): void => {
        const editor = visualEditorRef.current;

        if (!editor) {
            return;
        }

        onChange(htmlToMarkdown(editor.innerHTML));
    };

    const applyVisualCommand = (command: string, value?: string): void => {
        if (disabled) {
            return;
        }

        const editor = visualEditorRef.current;

        if (!editor) {
            return;
        }

        restoreSelection();
        editor.focus();
        document.execCommand(command, false, value);
        hasVisualInputRef.current = true;
        pushVisualHtmlToMarkdown();
    };

    const insertLink = (): void => {
        restoreSelection();
        const url = window.prompt('Enter URL');

        if (!url) {
            return;
        }

        applyVisualCommand('createLink', url.trim());
    };

    const visualToolbar = [
        { label: 'Undo', icon: Undo2, action: () => applyVisualCommand('undo') },
        { label: 'Redo', icon: Redo2, action: () => applyVisualCommand('redo') },
        {
            label: 'H1',
            icon: Heading1,
            action: () => applyVisualCommand('formatBlock', 'h1'),
        },
        {
            label: 'H2',
            icon: Heading2,
            action: () => applyVisualCommand('formatBlock', 'h2'),
        },
        {
            label: 'H3',
            icon: Heading3,
            action: () => applyVisualCommand('formatBlock', 'h3'),
        },
        { label: 'Bold', icon: Bold, action: () => applyVisualCommand('bold') },
        { label: 'Italic', icon: Italic, action: () => applyVisualCommand('italic') },
        { label: 'Quote', icon: Quote, action: () => applyVisualCommand('formatBlock', 'blockquote') },
        { label: 'Bullets', icon: List, action: () => applyVisualCommand('insertUnorderedList') },
        { label: 'Numbered', icon: ListOrdered, action: () => applyVisualCommand('insertOrderedList') },
        { label: 'Link', icon: Link2, action: insertLink },
        { label: 'Code', icon: Code2, action: () => applyVisualCommand('formatBlock', 'pre') },
        {
            label: 'Divider',
            icon: SeparatorHorizontal,
            action: () => applyVisualCommand('insertHorizontalRule'),
        },
    ];

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-1 rounded-lg border bg-muted/20 p-1">
                    <button
                        type="button"
                        onClick={() => setMode('visual')}
                        className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${
                            mode === 'visual'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        }`}
                        disabled={disabled}
                    >
                        Visual Editor
                    </button>
                    <button
                        type="button"
                        onClick={() => setMode('raw')}
                        className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${
                            mode === 'raw'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        }`}
                        disabled={disabled}
                    >
                        Raw Markdown
                    </button>
                    <button
                        type="button"
                        onClick={() => setMode('preview')}
                        className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${
                            mode === 'preview'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        }`}
                    >
                        <Eye className="mr-1 inline h-3.5 w-3.5" />
                        Preview
                    </button>
                </div>
                {isLoadingPreview ? (
                    <p className="text-xs text-muted-foreground">Rendering preview…</p>
                ) : null}
            </div>

            {mode === 'visual' ? (
                <div className="space-y-2">
                    <div className="flex flex-wrap gap-1 rounded-lg border bg-background p-2">
                        {visualToolbar.map((button) => (
                            <Button
                                key={button.label}
                                type="button"
                                variant="outline"
                                size="sm"
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                }}
                                onClick={button.action}
                                disabled={disabled}
                                className="h-8 gap-1.5 px-2"
                            >
                                <button.icon className="h-3.5 w-3.5" />
                                <span className="hidden md:inline">{button.label}</span>
                            </Button>
                        ))}
                    </div>

                    <div className="rounded-xl border bg-card">
                        <div
                            ref={visualEditorRef}
                            contentEditable={!disabled}
                            suppressContentEditableWarning
                            data-placeholder="Start writing here. Use toolbar controls without needing markdown syntax."
                            className="kb-markdown kb-visual-editor min-h-[24rem] max-h-[40rem] overflow-auto p-4 outline-none"
                            onFocus={() => {
                                setIsVisualFocused(true);
                                restoreSelection();
                            }}
                            onBlur={() => {
                                setIsVisualFocused(false);
                                pushVisualHtmlToMarkdown();
                            }}
                            onInput={() => {
                                hasVisualInputRef.current = true;
                                pushVisualHtmlToMarkdown();
                            }}
                        />
                    </div>
                </div>
            ) : null}

            {mode === 'raw' ? (
                <textarea
                    ref={rawTextareaRef}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    rows={22}
                    disabled={disabled}
                    className="border-input placeholder:text-muted-foreground focus-visible:ring-ring/50 flex min-h-[24rem] w-full rounded-xl border bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
                    placeholder="Paste or write raw markdown here…"
                />
            ) : null}

            {mode === 'preview' ? (
                <div className="overflow-hidden rounded-xl border bg-card">
                    <div className="border-b bg-muted/30 px-3 py-2 text-xs font-medium text-muted-foreground">
                        Markdown preview
                    </div>
                    <div
                        ref={previewRef}
                        className="kb-markdown max-h-[42rem] overflow-auto p-4"
                        dangerouslySetInnerHTML={{ __html: previewHtml }}
                    />
                </div>
            ) : null}

            {previewError ? (
                <p className="text-xs text-destructive">{previewError}</p>
            ) : null}
        </div>
    );
}
