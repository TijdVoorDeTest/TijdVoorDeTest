import { Controller } from '@hotwired/stimulus';

/** Shows the full text per bar as the tooltip title of a Chart.js chart whose axis only has short labels. */
export default class extends Controller {
    static values = { titles: Array };

    declare readonly titlesValue: string[];

    // Called on `chartjs:pre-connect`, before ux-chartjs creates the chart.
    preConnect(event: CustomEvent<{ options: ChartOptions }>): void {
        const options = event.detail.options;
        options.plugins ??= {};
        options.plugins.tooltip ??= {};
        options.plugins.tooltip.callbacks = {
            ...options.plugins.tooltip.callbacks,
            title: (items: { dataIndex: number }[]) => {
                const index = items[0].dataIndex;
                const title = this.titlesValue[index];
                return title === undefined ? '' : `#${index + 1} ${title}`;
            },
        };
    }
}

interface ChartOptions {
    plugins?: {
        tooltip?: {
            callbacks?: Record<string, unknown>;
        };
    };
}
