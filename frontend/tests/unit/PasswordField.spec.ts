import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PasswordField from '../../src/components/PasswordField.vue';

function field(autocomplete: 'current-password' | 'new-password' = 'current-password') {
    return mount(PasswordField, {
        props: { id: 'pw', modelValue: 'geheim', autocomplete },
    });
}

describe('PasswordField', () => {
    it('ist verborgen und für Passwort-Manager beschriftet', () => {
        const input = field('new-password').get('input');
        expect(input.attributes('type')).toBe('password');
        expect(input.attributes('autocomplete')).toBe('new-password');
        expect(input.attributes('autocapitalize')).toBe('none');
        expect(input.attributes('spellcheck')).toBe('false');
    });

    it('Auge-Knopf zeigt und verbirgt das Passwort', async () => {
        const wrapper = field();
        const toggle = wrapper.get('button');
        expect(toggle.attributes('type')).toBe('button');
        expect(toggle.attributes('aria-label')).toBe('Passwort anzeigen');
        expect(toggle.attributes('aria-controls')).toBe('pw');
        expect(toggle.attributes('aria-pressed')).toBe('false');

        await toggle.trigger('click');
        expect(wrapper.get('input').attributes('type')).toBe('text');
        expect(toggle.attributes('aria-pressed')).toBe('true');

        await toggle.trigger('click');
        expect(wrapper.get('input').attributes('type')).toBe('password');
    });

    it('meldet Eingaben über v-model', async () => {
        const wrapper = field();
        await wrapper.get('input').setValue('neu-neu-neu');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['neu-neu-neu']);
    });

    it('verbindet Fehlerhinweise', () => {
        const wrapper = mount(PasswordField, {
            props: {
                id: 'pw',
                modelValue: '',
                autocomplete: 'current-password',
                invalid: true,
                describedby: 'pw-error',
            },
        });
        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('input').attributes('aria-describedby')).toBe('pw-error');
    });
});
