import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import {
    hasUnsavedInput,
    registerUnsavedInput,
    useUnsavedInput,
} from '../../src/runtime/unsavedInput';

describe('unsavedInput', () => {
    it('ohne Quellen keine ungespeicherten Eingaben', () => {
        expect(hasUnsavedInput.value).toBe(false);
    });

    it('folgt reaktiv den Feldern; Abmelden entfernt die Quelle', () => {
        const text = ref('');
        const off = registerUnsavedInput(() => text.value !== '');
        expect(hasUnsavedInput.value).toBe(false);
        text.value = 'M1';
        expect(hasUnsavedInput.value).toBe(true);
        off();
        expect(hasUnsavedInput.value).toBe(false);
    });

    it('eine von mehreren Quellen genügt', () => {
        const offA = registerUnsavedInput(() => false);
        const offB = registerUnsavedInput(() => true);
        expect(hasUnsavedInput.value).toBe(true);
        offB();
        expect(hasUnsavedInput.value).toBe(false);
        offA();
    });

    it('useUnsavedInput gilt, solange die Komponente besteht', () => {
        const text = ref('x');
        const Form = defineComponent({
            setup() {
                useUnsavedInput(() => text.value !== '');
                return () => h('form');
            },
        });
        const wrapper = mount(Form);
        expect(hasUnsavedInput.value).toBe(true);
        wrapper.unmount();
        expect(hasUnsavedInput.value).toBe(false);
    });
});
