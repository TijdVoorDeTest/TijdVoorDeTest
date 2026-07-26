import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'tvdt-fullscreen';

export default class extends Controller {
    static targets = ['button'];

    declare readonly buttonTarget: HTMLElement;

    connect(): void {
        document.addEventListener('fullscreenchange', this.onFullscreenChange);
        this.syncState();

        if (
            sessionStorage.getItem(STORAGE_KEY) === '1' &&
            !document.fullscreenElement
        ) {
            this.request();
        }
    }

    disconnect(): void {
        document.removeEventListener(
            'fullscreenchange',
            this.onFullscreenChange,
        );
    }

    toggle(): void {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            this.request();
        }
    }

    request(): void {
        document.documentElement.requestFullscreen().catch(() => {});
    }

    onFullscreenChange = (): void => {
        sessionStorage.setItem(
            STORAGE_KEY,
            document.fullscreenElement ? '1' : '0',
        );
        this.syncState();
    };

    syncState(): void {
        const isFullscreen = Boolean(document.fullscreenElement);
        document.documentElement.classList.toggle(
            'is-fullscreen',
            isFullscreen,
        );
        this.buttonTarget.setAttribute('aria-pressed', String(isFullscreen));
    }
}
