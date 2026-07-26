import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import './styles/molshoop.scss';
import '@hotwired/turbo';
import './stimulus.ts';
import './bootstrap.ts';
import * as Sentry from '@sentry/browser';

const dsn = document.querySelector<HTMLMetaElement>('meta[name="sentry-dsn"]')
    ?.content ?? '';
const userEmail =
    document.querySelector<HTMLMetaElement>('meta[name="user-email"]')
        ?.content ?? '';

// When no real DSN is configured, route to the local Spotlight sidecar so
// nothing reaches Sentry. A syntactically valid DSN is still required for the
// SDK to initialise; the tunnel option redirects all transport to Spotlight.
const useSpotlight = !dsn;
const effectiveDsn = dsn || 'https://0@o0.ingest.sentry.io/0';

const feedbackIntegration = Sentry.feedbackIntegration({
    colorScheme: 'system',
    showName: false,
    showEmail: true,
    isEmailRequired: false,
    autoInject: false,
    triggerLabel: 'Report feedback',
    formTitle: 'Report Feedback',
    submitButtonLabel: 'Send Feedback',
});

Sentry.init({
    dsn: effectiveDsn,
    tunnel: useSpotlight ? 'http://localhost:8969/stream' : undefined,
    integrations: [feedbackIntegration],
    // Turbo aborts in-flight fetch() visits on navigation; Firefox reports
    // that as this TypeError instead of an AbortError. Not an app bug.
    ignoreErrors: ['NetworkError when attempting to fetch resource.'],
});

// autoInject is unreliable in Sentry v10 due to the setupOnce guard; mount manually.
feedbackIntegration.createWidget();

if (userEmail) {
    Sentry.setUser({ email: userEmail });
}
