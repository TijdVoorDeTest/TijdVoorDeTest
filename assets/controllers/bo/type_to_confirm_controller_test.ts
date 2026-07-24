import { assertEquals } from '@std/assert';

const { default: TypeToConfirmController } = await import(
    './type_to_confirm_controller.ts'
);

// deno-lint-ignore no-explicit-any
function makeController(word: string, value: string): any {
    const controller = new TypeToConfirmController({} as never);
    return Object.assign(controller, {
        inputTarget: { value },
        submitTarget: { disabled: false },
        wordValue: word,
    });
}

Deno.test('check() disables the submit button when the input does not match', () => {
    const controller = makeController('verwijderen', '');
    controller.check();
    assertEquals(controller.submitTarget.disabled, true);
});

Deno.test('check() enables the submit button on an exact match', () => {
    const controller = makeController('verwijderen', 'verwijderen');
    controller.check();
    assertEquals(controller.submitTarget.disabled, false);
});

Deno.test('check() is case-insensitive and ignores surrounding whitespace', () => {
    const controller = makeController('verwijderen', '  Verwijderen  ');
    controller.check();
    assertEquals(controller.submitTarget.disabled, false);
});

Deno.test('check() disables the submit button again once the input no longer matches', () => {
    const controller = makeController('verwijderen', 'verwijderen');
    controller.check();
    assertEquals(controller.submitTarget.disabled, false);

    controller.inputTarget.value = 'verwijdere';
    controller.check();
    assertEquals(controller.submitTarget.disabled, true);
});

Deno.test('connect() disables the submit button by default', () => {
    const controller = makeController('verwijderen', '');
    controller.connect();
    assertEquals(controller.submitTarget.disabled, true);
});
