import { assertEquals } from '@std/assert';

const { default: ChartTitlesController } = await import(
    './chart_titles_controller.ts'
);

// deno-lint-ignore no-explicit-any
function preConnect(titles: string[], options: any = {}): any {
    const controller = new ChartTitlesController({} as never);
    Object.assign(controller, { titlesValue: titles });
    controller.preConnect({ detail: { options } } as never);
    return options;
}

Deno.test('preConnect() makes the tooltip title the numbered full text of the hovered bar', () => {
    const options = preConnect(['First question', 'Second question']);
    assertEquals(
        options.plugins.tooltip.callbacks.title([{ dataIndex: 1 }]),
        '#2 Second question',
    );
});

Deno.test('preConnect() keeps existing tooltip callbacks', () => {
    const label = () => 'x';
    const options = preConnect(['Q'], {
        plugins: { tooltip: { callbacks: { label } } },
    });
    assertEquals(options.plugins.tooltip.callbacks.label, label);
});

Deno.test('preConnect() falls back to an empty title for an unknown bar', () => {
    const options = preConnect(['Q']);
    assertEquals(
        options.plugins.tooltip.callbacks.title([{ dataIndex: 5 }]),
        '',
    );
});
