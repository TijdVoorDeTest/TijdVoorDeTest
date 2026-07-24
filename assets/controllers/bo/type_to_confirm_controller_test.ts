import { assertEquals } from '@std/assert';

const { default: TypeToConfirmController } = await import(
    './type_to_confirm_controller.ts'
);

// deno-lint-ignore no-explicit-any
function makeController(words: string[], value: string): any {
    const controller = new TypeToConfirmController({} as never);
    return Object.assign(controller, {
        inputTarget: { value },
        submitTarget: { disabled: false },
        wordsValue: words,
    });
}

Deno.test('check() disables the submit button when the input matches none of the words', () => {
    const controller = makeController(['verwijderen', 'delete'], '');
    controller.check();
    assertEquals(controller.submitTarget.disabled, true);
});

Deno.test('check() enables the submit button on an exact match of the first word', () => {
    const controller = makeController(['verwijderen', 'delete'], 'verwijderen');
    controller.check();
    assertEquals(controller.submitTarget.disabled, false);
});

Deno.test('check() enables the submit button on an exact match of another accepted word', () => {
    const controller = makeController(['verwijderen', 'delete'], 'delete');
    controller.check();
    assertEquals(controller.submitTarget.disabled, false);
});

Deno.test('check() is case-insensitive and ignores surrounding whitespace', () => {
    const controller = makeController(['verwijderen', 'delete'], '  Delete  ');
    controller.check();
    assertEquals(controller.submitTarget.disabled, false);
});

Deno.test('check() disables the submit button again once the input no longer matches', () => {
    const controller = makeController(['verwijderen', 'delete'], 'delete');
    controller.check();
    assertEquals(controller.submitTarget.disabled, false);

    controller.inputTarget.value = 'delet';
    controller.check();
    assertEquals(controller.submitTarget.disabled, true);
});

Deno.test('connect() disables the submit button by default', () => {
    const controller = makeController(['verwijderen', 'delete'], '');
    controller.connect();
    assertEquals(controller.submitTarget.disabled, true);
});
