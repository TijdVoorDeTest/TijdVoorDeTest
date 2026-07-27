import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import './styles/molshoop.scss';
import '@hotwired/turbo';
import './stimulus.ts';
import './bootstrap.ts';
import * as Sentry from '@sentry/browser';
import { initSentry } from './sentry.ts';

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

initSentry([feedbackIntegration]);

// autoInject is unreliable in Sentry v10 due to the setupOnce guard; mount manually.
feedbackIntegration.createWidget();
