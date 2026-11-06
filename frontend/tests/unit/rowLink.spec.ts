import { afterEach, describe, expect, it, vi } from 'vitest';
import { opensRow, vRowLink } from '../../src/components/rowLink';

/** Zeile mit Link, Text, Knopf und Kästchen in einer Spalte mit `data-row-ignore`. */
function row(): { row: HTMLElement; link: Element; linkClicks: () => number } {
    const element = document.createElement('div');
    element.innerHTML = `
        <span class="select" data-row-ignore><input type="checkbox" /></span>
        <a href="#ziel" data-row-link>M1</a>
        <span class="text">HLF 20 / G1</span>
        <button type="button"><svg><path /></svg></button>
        <label><span class="label-text">Name</span></label>`;
    document.body.append(element);
    const link = find(element, 'a');
    let clicks = 0;
    link.addEventListener('click', (event) => {
        event.preventDefault();
        clicks += 1;
    });
    vRowLink.mounted?.(element, {} as never, {} as never, null);
    return { row: element, link, linkClicks: () => clicks };
}

function find(root: Element, selector: string): Element {
    const element = root.querySelector(selector);
    if (element === null) {
        throw new Error(`${selector} fehlt`);
    }
    return element;
}

function click(target: Element, init: MouseEventInit = {}): void {
    target.dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0, ...init }));
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('vRowLink', () => {
    it('ein Klick in die Zeile löst genau einmal den Link aus', () => {
        const { row: element, linkClicks } = row();
        click(find(element, '.text'));
        click(element);
        expect(linkClicks()).toBe(2);
        expect(element.hasAttribute('data-row-open')).toBe(true);
    });

    it('der Link selbst wird nicht doppelt ausgelöst', () => {
        const { link, linkClicks } = row();
        click(link);
        expect(linkClicks()).toBe(1);
    });

    it('Knöpfe (auch ihr Symbol), Kästchen, Labels und data-row-ignore öffnen nichts', () => {
        const { row: element, linkClicks } = row();
        click(find(element, 'path'));
        click(find(element, 'input'));
        click(find(element, '.select'));
        click(find(element, '.label-text'));
        expect(linkClicks()).toBe(0);
    });

    it('andere Maustasten und Zusatztasten öffnen nichts', () => {
        const { row: element, linkClicks } = row();
        const text = find(element, '.text');
        click(text, { button: 1 });
        click(text, { ctrlKey: true });
        click(text, { metaKey: true });
        click(text, { shiftKey: true });
        click(text, { altKey: true });
        expect(linkClicks()).toBe(0);
    });

    it('markierter Text öffnet nichts', () => {
        const { row: element, linkClicks } = row();
        vi.spyOn(window, 'getSelection').mockReturnValue({ isCollapsed: false } as Selection);
        click(find(element, '.text'));
        expect(linkClicks()).toBe(0);
    });

    it('ohne Link tut der Klick nichts', () => {
        const element = document.createElement('div');
        element.innerHTML = '<span class="text">ohne Link</span>';
        document.body.append(element);
        vRowLink.mounted?.(element, {} as never, {} as never, null);
        expect(() => {
            click(find(element, '.text'));
        }).not.toThrow();
    });

    it('nach dem Entfernen reagiert die Zeile nicht mehr', () => {
        const { row: element, linkClicks } = row();
        vRowLink.beforeUnmount?.(element, {} as never, {} as never, null);
        click(find(element, '.text'));
        expect(linkClicks()).toBe(0);
    });

    it('ein Knopf außerhalb der Zeile zählt nicht (nur innerhalb wird geprüft)', () => {
        const outer = document.createElement('label');
        const inner = document.createElement('div');
        const text = document.createElement('span');
        inner.append(text);
        outer.append(inner);
        document.body.append(outer);
        const event = new MouseEvent('click', { bubbles: true, button: 0 });
        Object.defineProperty(event, 'target', { value: text });
        expect(opensRow(inner, event)).toBe(true);
    });
});
