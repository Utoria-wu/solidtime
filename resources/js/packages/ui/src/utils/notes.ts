import DOMPurify from 'dompurify';
import { marked } from 'marked';

export const noteColors = {
    red: { label: 'Red', hex: '#b42318' },
    blue: { label: 'Blue', hex: '#175cd3' },
    green: { label: 'Green', hex: '#067647' },
    purple: { label: 'Purple', hex: '#6941c6' },
} as const;

const defaultColor = 'var(--theme-color-text-primary, currentColor)';
const documentStart = '<div data-note-document="true">';

function sanitizedFragment(html: string) {
    const fragment = DOMPurify.sanitize(html, {
        ALLOWED_TAGS: [
            'a',
            'b',
            'blockquote',
            'br',
            'code',
            'del',
            'div',
            'em',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'hr',
            'i',
            'input',
            'li',
            'ol',
            'p',
            'pre',
            's',
            'span',
            'strong',
            'table',
            'tbody',
            'td',
            'th',
            'thead',
            'tr',
            'u',
            'ul',
        ],
        ALLOWED_ATTR: ['href', 'title', 'start', 'type', 'checked', 'disabled', 'data-note-color'],
        ALLOW_DATA_ATTR: false,
        ALLOW_ARIA_ATTR: false,
        RETURN_DOM_FRAGMENT: true,
    });
    for (const input of fragment.querySelectorAll('input')) {
        if (input.type === 'checkbox') input.disabled = true;
        else input.remove();
    }
    for (const element of fragment.querySelectorAll<HTMLElement>('[data-note-color]')) {
        const key = element.getAttribute('data-note-color');
        if (
            element.tagName !== 'SPAN' ||
            !key ||
            (key !== 'default' && !Object.hasOwn(noteColors, key))
        )
            element.removeAttribute('data-note-color');
    }
    return fragment;
}

export function renderNotes(notes?: string | null) {
    const value = notes ?? '';
    const isDocument = value.startsWith(documentStart) && value.endsWith('</div>');
    const container = document.createElement('div');
    container.append(
        sanitizedFragment(
            isDocument
                ? value.slice(documentStart.length, -6)
                : marked.parse(value, { async: false, gfm: true, breaks: true })
        )
    );
    for (const span of container.querySelectorAll<HTMLElement>('span[data-note-color]')) {
        const key = span.dataset.noteColor as keyof typeof noteColors | 'default';
        span.style.color = key === 'default' ? defaultColor : noteColors[key].hex;
    }
    return container.innerHTML;
}

export function serializeNotes(editor: HTMLElement) {
    if (!editor.textContent?.trim() && !editor.querySelector('input[type="checkbox"], hr'))
        return '';
    const copy = editor.cloneNode(true) as HTMLElement;
    const textColor = getComputedStyle(editor).color;
    for (const element of copy.querySelectorAll<HTMLElement>('font[color], span[style]')) {
        const color = element.getAttribute('color') || element.style.color;
        const normalized = document.createElement('span');
        normalized.style.color = color;
        const match = (Object.keys(noteColors) as (keyof typeof noteColors)[]).find((key) => {
            const swatch = document.createElement('span');
            swatch.style.color = noteColors[key].hex;
            return swatch.style.color === normalized.style.color;
        });
        const span = element.tagName === 'SPAN' ? element : document.createElement('span');
        if (span !== element) {
            span.append(...element.childNodes);
            element.replaceWith(span);
        }
        if (match) span.dataset.noteColor = match;
        else if (normalized.style.color === textColor || color === defaultColor)
            span.dataset.noteColor = 'default';
        else if (color) span.removeAttribute('data-note-color');
    }
    const container = document.createElement('div');
    container.append(sanitizedFragment(copy.innerHTML));
    return documentStart + container.innerHTML + '</div>';
}
