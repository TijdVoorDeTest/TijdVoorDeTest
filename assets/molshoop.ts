import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import './styles/molshoop.scss';
import '@hotwired/turbo';
import './stimulus.ts';
import './bootstrap.ts';
import * as Sentry from '@sentry/browser';
import { initSentry } from './sentry.ts';
import { trans } from './translator.ts';

const feedbackIntegration = Sentry.feedbackIntegration({
    showName: false,
    showEmail: true,
    isEmailRequired: false,
    autoInject: false,
    triggerLabel: trans('Report feedback'),
    triggerAriaLabel: trans('Report feedback'),
    formTitle: trans('Report feedback'),
    submitButtonLabel: trans('Send feedback'),
    cancelButtonLabel: trans('Cancel'),
    confirmButtonLabel: trans('Confirm'),
    addScreenshotButtonLabel: trans('Add a screenshot'),
    removeScreenshotButtonLabel: trans('Remove screenshot'),
    nameLabel: trans('Name'),
    namePlaceholder: trans('Name'),
    emailLabel: trans('Email'),
    emailPlaceholder: trans('your.email@example.org'),
    messageLabel: trans('Description'),
    messagePlaceholder: trans("What's the bug? What did you expect?"),
    isRequiredLabel: trans('(required)'),
    successMessageText: trans('Thank you for your report!'),
});

initSentry([feedbackIntegration]);

// autoInject is unreliable in Sentry v10 due to the setupOnce guard; mount manually.
feedbackIntegration.createWidget();

const syncFeedbackTheme = (): void => {
    const theme = document.documentElement.getAttribute('data-bs-theme');
    feedbackIntegration.setTheme(theme === 'dark' ? 'dark' : 'light');
};
syncFeedbackTheme();
new MutationObserver(syncFeedbackTheme).observe(document.documentElement, {
    attributeFilter: ['data-bs-theme'],
});
