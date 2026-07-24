import { assertEquals } from '@std/assert';
import { resolveEffectiveTheme } from './theme_controller.ts';

Deno.test('resolveEffectiveTheme follows the OS preference in auto mode', () => {
    assertEquals(resolveEffectiveTheme('auto', true), 'dark');
    assertEquals(resolveEffectiveTheme('auto', false), 'light');
});

Deno.test('resolveEffectiveTheme ignores the OS preference for explicit choices', () => {
    assertEquals(resolveEffectiveTheme('light', true), 'light');
    assertEquals(resolveEffectiveTheme('dark', false), 'dark');
});
