import { Controller } from '@hotwired/stimulus';
import { Tooltip } from 'bootstrap';

/** Turns every `data-bs-toggle="tooltip"` element inside into a Bootstrap tooltip (instant and styled, unlike a bare title). */
export default class extends Controller {
    tooltips: Tooltip[] = [];

    connect(): void {
        this.tooltips = [
            ...this.element.querySelectorAll('[data-bs-toggle="tooltip"]'),
        ].map((element) => Tooltip.getOrCreateInstance(element));
    }

    disconnect(): void {
        this.tooltips.forEach((tooltip) => tooltip.dispose());
    }
}
