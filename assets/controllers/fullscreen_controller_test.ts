import { assertEquals } from '@std/assert';

// Stimulus's Controller base class constructor only does `this.context = context`
// (see @hotwired/stimulus dist/stimulus.js), and none of this controller's methods
// touch Stimulus-specific getters (element/scope/targets), so a plain `{}` context
// is enough to construct a real instance. `document`/`sessionStorage` are stubbed
// since Deno has no browser DOM.
class FakeClassList {
    classes = new Set<string>();
    toggle(name: string, force?: boolean) {
        const shouldAdd = force ?? !this.classes.has(name);
        if (shouldAdd) this.classes.add(name);
        else this.classes.delete(name);
    }
    contains(name: string) {
        return this.classes.has(name);
    }
}

const classList = new FakeClassList();
let fullscreenElement: unknown = null;
const sessionStore = new Map<string, string>();

// deno-lint-ignore no-explicit-any
(globalThis as any).document = {
    documentElement: { classList },
    get fullscreenElement() {
        return fullscreenElement;
    },
    addEventListener: () => {},
    removeEventListener: () => {},
};
// Deno defines a native `sessionStorage` accessor on globalThis (get/set pair), so a
// plain assignment would just call through to it instead of replacing it — redefine
// the property outright.
Object.defineProperty(globalThis, 'sessionStorage', {
    value: {
        getItem: (key: string) => sessionStore.get(key) ?? null,
        setItem: (key: string, value: string) => sessionStore.set(key, value),
    },
    configurable: true,
});

const { default: FullscreenController } = await import(
    './fullscreen_controller.ts'
);

class FakeButton {
    attrs = new Map<string, string>();
    setAttribute(name: string, value: string) {
        this.attrs.set(name, value);
    }
}

// deno-lint-ignore no-explicit-any
function makeController(): any {
    // deno-lint-ignore no-explicit-any
    const controller = new FullscreenController({} as never) as any;
    controller.buttonTarget = new FakeButton();
    return controller;
}

Deno.test('syncState adds is-fullscreen and sets aria-pressed when a fullscreen element is set', () => {
    fullscreenElement = { tagName: 'HTML' };
    const controller = makeController();
    controller.syncState();
    assertEquals(classList.contains('is-fullscreen'), true);
    assertEquals(controller.buttonTarget.attrs.get('aria-pressed'), 'true');
});

Deno.test('syncState removes is-fullscreen and sets aria-pressed to false when there is no fullscreen element', () => {
    fullscreenElement = null;
    const controller = makeController();
    controller.syncState();
    assertEquals(classList.contains('is-fullscreen'), false);
    assertEquals(controller.buttonTarget.attrs.get('aria-pressed'), 'false');
});

Deno.test('onFullscreenChange persists the fullscreen state and syncs classes', () => {
    fullscreenElement = { tagName: 'HTML' };
    makeController().onFullscreenChange();
    assertEquals(sessionStore.get('tvdt-fullscreen'), '1');
    assertEquals(classList.contains('is-fullscreen'), true);

    fullscreenElement = null;
    makeController().onFullscreenChange();
    assertEquals(sessionStore.get('tvdt-fullscreen'), '0');
    assertEquals(classList.contains('is-fullscreen'), false);
});
