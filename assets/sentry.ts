import * as Sentry from '@sentry/browser';

type Integrations = NonNullable<
    Parameters<typeof Sentry.init>[0]
>['integrations'];

export function initSentry(integrations: Integrations = []): void {
    const dsn =
        document.querySelector<HTMLMetaElement>('meta[name="sentry-dsn"]')
            ?.content ?? '';
    const userEmail =
        document.querySelector<HTMLMetaElement>('meta[name="user-email"]')
            ?.content ?? '';

    // When no real DSN is configured, route to the local Spotlight sidecar so
    // nothing reaches Sentry. A syntactically valid DSN is still required for the
    // SDK to initialise; the tunnel option redirects all transport to Spotlight.
    const useSpotlight = !dsn;
    const effectiveDsn = dsn || 'https://0@o0.ingest.sentry.io/0';

    Sentry.init({
        dsn: effectiveDsn,
        tunnel: useSpotlight ? 'http://localhost:8969/stream' : undefined,
        integrations,
        // Turbo aborts in-flight fetch() visits on navigation; Firefox reports
        // that as this TypeError instead of an AbortError. Not an app bug.
        ignoreErrors: ['NetworkError when attempting to fetch resource.'],
    });

    if (userEmail) {
        Sentry.setUser({ email: userEmail });
    }
}
