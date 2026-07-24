import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'submit'];
    static values = { word: String };

    declare readonly inputTarget: HTMLInputElement;
    declare readonly submitTarget: HTMLButtonElement;
    declare readonly wordValue: string;

    connect(): void {
        this.check();
    }

    check(): void {
        this.submitTarget.disabled =
            this.inputTarget.value.trim().toLowerCase() !==
                this.wordValue.toLowerCase();
    }
}
