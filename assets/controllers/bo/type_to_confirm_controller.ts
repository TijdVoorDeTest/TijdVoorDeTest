import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'submit'];
    static values = { words: Array };

    declare readonly inputTarget: HTMLInputElement;
    declare readonly submitTarget: HTMLButtonElement;
    declare readonly wordsValue: string[];

    connect(): void {
        this.check();
    }

    check(): void {
        const value = this.inputTarget.value.trim().toLowerCase();
        this.submitTarget.disabled = !this.wordsValue.some(
            (word) => word.toLowerCase() === value,
        );
    }
}
