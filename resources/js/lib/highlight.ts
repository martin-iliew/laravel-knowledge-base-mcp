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
import highlightThemeDarkHref from 'highlight.js/styles/github-dark.css?url';
import highlightThemeLightHref from 'highlight.js/styles/github.css?url';

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
let hasBoundThemeObserver = false;
const HIGHLIGHT_THEME_LINK_ID = 'kb-highlight-theme';

function resolveHighlightThemeHref(): string {
    if (typeof document === 'undefined') {
        return highlightThemeLightHref;
    }

    return document.documentElement.classList.contains('dark')
        ? highlightThemeDarkHref
        : highlightThemeLightHref;
}

function ensureHighlightTheme(): void {
    if (typeof document === 'undefined') {
        return;
    }

    const expectedHref = resolveHighlightThemeHref();
    const existingNode = document.getElementById(HIGHLIGHT_THEME_LINK_ID);
    const themeLink =
        existingNode instanceof HTMLLinkElement
            ? existingNode
            : document.createElement('link');

    if (!(existingNode instanceof HTMLLinkElement)) {
        themeLink.id = HIGHLIGHT_THEME_LINK_ID;
        themeLink.rel = 'stylesheet';
        document.head.appendChild(themeLink);
    }

    if (themeLink.getAttribute('href') !== expectedHref) {
        themeLink.setAttribute('href', expectedHref);
    }

    if (hasBoundThemeObserver) {
        return;
    }

    const observer = new MutationObserver(() => {
        const currentThemeLink = document.getElementById(HIGHLIGHT_THEME_LINK_ID);

        if (!(currentThemeLink instanceof HTMLLinkElement)) {
            return;
        }

        const nextHref = resolveHighlightThemeHref();

        if (currentThemeLink.getAttribute('href') !== nextHref) {
            currentThemeLink.setAttribute('href', nextHref);
        }
    });

    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
    hasBoundThemeObserver = true;
}

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
    ensureHighlightTheme();
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
        codeElement.style.backgroundColor = 'transparent';
        codeElement.style.padding = '0';
        codeElement.innerHTML = highlightCode(rawCode, normalizedLanguage);
    });
}
