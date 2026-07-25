import { Controller } from '@hotwired/stimulus';
import { Tooltip } from 'bootstrap';

const TOOLTIP_DURATION_MS = 1500;

export default class extends Controller {
    static values = { url: String, hintLabel: String, copiedLabel: String };

    declare readonly urlValue: string;
    declare readonly hintLabelValue: string;
    declare readonly copiedLabelValue: string;

    tooltip?: Tooltip;
    hideTimeout?: ReturnType<typeof setTimeout>;

    connect(): void {
        this.tooltip = new Tooltip(this.element, {
            title: this.hintLabelValue,
        });
    }

    disconnect(): void {
        clearTimeout(this.hideTimeout);
        this.tooltip?.dispose();
    }

    copy(): void {
        this.tooltip?.setContent({ '.tooltip-inner': this.copiedLabelValue });

        try {
            void navigator.clipboard.writeText(this.urlValue);
        } catch {
            /* clipboard access unavailable; tooltip feedback already shown */
        }

        clearTimeout(this.hideTimeout);
        this.hideTimeout = setTimeout(() => {
            this.tooltip?.setContent({ '.tooltip-inner': this.hintLabelValue });
        }, TOOLTIP_DURATION_MS);
    }
}
