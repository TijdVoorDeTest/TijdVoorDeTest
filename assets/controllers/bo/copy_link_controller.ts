import { Controller } from '@hotwired/stimulus';
import { Tooltip } from 'bootstrap';

const TOOLTIP_DURATION_MS = 1500;

export default class extends Controller {
    static targets = ['trigger', 'status'];
    static values = { url: String, hintLabel: String, copiedLabel: String };

    declare readonly triggerTarget: HTMLElement;
    declare readonly statusTarget: HTMLElement;
    declare readonly urlValue: string;
    declare readonly hintLabelValue: string;
    declare readonly copiedLabelValue: string;

    tooltip?: Tooltip;
    hideTimeout?: ReturnType<typeof setTimeout>;

    connect(): void {
        this.tooltip = new Tooltip(this.triggerTarget, {
            title: this.hintLabelValue,
        });
    }

    disconnect(): void {
        clearTimeout(this.hideTimeout);
        this.tooltip?.dispose();
    }

    async copy(): Promise<void> {
        try {
            await navigator.clipboard.writeText(this.urlValue);
        } catch {
            return;
        }

        this.tooltip?.setContent({ '.tooltip-inner': this.copiedLabelValue });
        this.statusTarget.textContent = this.copiedLabelValue;

        clearTimeout(this.hideTimeout);
        this.hideTimeout = setTimeout(() => {
            this.tooltip?.setContent({ '.tooltip-inner': this.hintLabelValue });
            this.statusTarget.textContent = '';
        }, TOOLTIP_DURATION_MS);
    }
}
