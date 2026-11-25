/**
 * @file
 * Renders the default setting of an Ajax-loaded library.
 */

Drupal.behaviors.ajaxTestSettingsWidget = {
  attach(context, settings) {
    context.querySelectorAll('[data-ajax-test-widget]').forEach((widget) => {
      widget.textContent = settings.ajaxTestWidget.label;
    });
  },
};
