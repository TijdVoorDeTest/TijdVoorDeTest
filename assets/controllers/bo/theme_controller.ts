import { Controller } from '@hotwired/stimulus';

export const STORAGE_KEY = 'tvdt-theme';

export type Theme = 'auto' | 'light' | 'dark';

export function resolveEffectiveTheme(
    theme: Theme,
    prefersDark: boolean,
): 'light' | 'dark' {
    return theme === 'auto' ? (prefersDark ? 'dark' : 'light') : theme;
}

const ICON_CLASSES: Record<Theme, string> = {
    auto: 'bi-circle-half',
    light: 'bi-sun-fill',
    dark: 'bi-moon-stars-fill',
};

export default class extends Controller {
    static targets = ['option', 'icon'];

    declare readonly optionTargets: HTMLElement[];
    declare readonly iconTarget: HTMLElement;

    media = window.matchMedia('(prefers-color-scheme: dark)');

    connect(): void {
        this.apply(this.currentTheme());
        this.media.addEventListener('change', this.onMediaChange);
    }

    disconnect(): void {
        this.media.removeEventListener('change', this.onMediaChange);
    }

    select(event: Event): void {
        const theme = (event.currentTarget as HTMLElement).dataset
            .theme as Theme;
        localStorage.setItem(STORAGE_KEY, theme);
        this.apply(theme);
    }

    onMediaChange = (): void => {
        if (this.currentTheme() === 'auto') this.apply('auto');
    };

    apply(theme: Theme): void {
        document.documentElement.setAttribute(
            'data-bs-theme',
            resolveEffectiveTheme(theme, this.media.matches),
        );
        this.syncActive();
    }

    syncActive(): void {
        const current = this.currentTheme();
        this.optionTargets.forEach((option) => {
            const isCurrent = option.dataset.theme === current;
            option.classList.toggle('active', isCurrent);
            option.setAttribute('aria-pressed', isCurrent ? 'true' : 'false');
        });
        this.iconTarget.className = `bi ${ICON_CLASSES[current]}`;
    }

    currentTheme(): Theme {
        return (localStorage.getItem(STORAGE_KEY) as Theme | null) ?? 'auto';
    }
}
