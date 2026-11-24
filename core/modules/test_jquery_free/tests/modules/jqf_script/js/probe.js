/**
 * @file
 * Reads both legacy globals from the same script.
 */

const globals = ['jQuery', '$'].map((name) => typeof Reflect.get(window, name));
[document.body.dataset.jquery, document.body.dataset.alias] = globals;
