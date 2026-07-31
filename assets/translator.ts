import { createTranslator } from '@symfony/ux-translator';
import { localeFallbacks, messages } from '../var/translations/index.js';

const translator = createTranslator({
    messages,
    localeFallbacks: localeFallbacks as unknown as Record<string, string>,
});

export const { trans } = translator;
