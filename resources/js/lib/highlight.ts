import type { LanguageFn } from 'highlight.js';
import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import css from 'highlight.js/lib/languages/css';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import plaintext from 'highlight.js/lib/languages/plaintext';
import python from 'highlight.js/lib/languages/python';
import sql from 'highlight.js/lib/languages/sql';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import yaml from 'highlight.js/lib/languages/yaml';

const LANGUAGE_ALIASES: Record<string, string> = {
    sh: 'bash',
    shell: 'bash',
    zsh: 'bash',
    js: 'javascript',
    jsx: 'javascript',
    ts: 'typescript',
    tsx: 'typescript',
    yml: 'yaml',
    md: 'text',
    plaintext: 'text',
    text: 'text',
    html: 'xml',
};

const REGISTERED_LANGUAGES: Record<string, LanguageFn> = {
    bash,
    css,
    javascript,
    json,
    php,
    python,
    sql,
    typescript,
    xml,
    yaml,
};

let hasRegisteredLanguages = false;

function ensureLanguages(): void {
    if (hasRegisteredLanguages) {
        return;
    }

    Object.entries(REGISTERED_LANGUAGES).forEach(([languageName, languageFn]) => {
        if (!hljs.getLanguage(languageName)) {
            hljs.registerLanguage(languageName, languageFn);
        }
    });

    if (!hljs.getLanguage('text')) {
        hljs.registerLanguage('text', plaintext);
    }

    hasRegisteredLanguages = true;
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function languageFromClassName(className: string): string | null {
    const match = className.match(/(?:lang|language)-([a-z0-9_+-]+)/i);

    if (!match) {
        return null;
    }

    return match[1] ?? null;
}

export function normalizeCodeLanguage(value: string | null | undefined): string {
    const normalized = (value ?? '').trim().toLowerCase();

    if (normalized === '') {
        return 'text';
    }

    return LANGUAGE_ALIASES[normalized] ?? normalized;
}

export function highlightCode(code: string, language: string | null | undefined): string {
    ensureLanguages();

    const normalizedLanguage = normalizeCodeLanguage(language);

    if (normalizedLanguage === 'text') {
        return escapeHtml(code);
    }

    if (hljs.getLanguage(normalizedLanguage)) {
        return hljs.highlight(code, {
            language: normalizedLanguage,
            ignoreIllegals: true,
        }).value;
    }

    return escapeHtml(code);
}

export function highlightMarkdownCodeBlocks(container: HTMLElement | null): void {
    if (!container) {
        return;
    }

    const codeBlocks = container.querySelectorAll('pre code');

    codeBlocks.forEach((node) => {
        const codeElement = node as HTMLElement;
        const rawCode = codeElement.textContent ?? '';
        const detectedLanguage = languageFromClassName(codeElement.className);
        const normalizedLanguage = normalizeCodeLanguage(detectedLanguage);

        codeElement.className = normalizedLanguage
            ? `hljs language-${normalizedLanguage}`
            : 'hljs';
        codeElement.innerHTML = highlightCode(rawCode, normalizedLanguage);
    });
}
