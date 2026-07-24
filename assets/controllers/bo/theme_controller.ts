import { Controller } from '@hotwired/stimulus';

export const STORAGE_KEY = 'tvdt-theme';

export type Theme = 'auto' | 'light' | 'dark';

export function resolveEffectiveTheme(
    theme: Theme,
    prefersDark: boolean,
): 'light' | 'dark' {
    return theme === 'auto' ? (prefersDark ? 'dark' : 'light') : theme;
}

export default class extends Controller {
    static targets = ['option'];

    declare readonly optionTargets: HTMLElement[];

    media = window.matchMedia('(prefers-color-scheme: dark)');

    connect(): void {
        this.syncActive();
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
            option.classList.toggle(
                'active',
                option.dataset.theme === current,
            );
        });
    }

    currentTheme(): Theme {
        return (localStorage.getItem(STORAGE_KEY) as Theme | null) ?? 'auto';
    }
}
