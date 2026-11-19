/**
 * @file
 * Defines JavaScript behaviors for the block_content module.
 */

(function ($, Drupal) {
  /**
   * Sets summaries about revision and translation of entities.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches summary behavior entity form tabs.
   *
   *   Specifically, it updates summaries to the revision information and the
   *   translation options.
   */
  Drupal.behaviors.entityContentDetailsSummaries = {
    attach(context) {
      const $context = $(context);
      $context
        .find('.entity-content-form-revision-information')
        .drupalSetSummary((fieldset) => {
          const revisionCheckbox = fieldset.querySelector(
            '.js-form-item-revision input',
          );

          // Users without the "Administer content" permission only see the log
          // field when the content type is configured to create revisions.
          if (
            revisionCheckbox?.checked ||
            (!revisionCheckbox &&
              fieldset.querySelector('.js-form-item-revision-log textarea'))
          ) {
            return Drupal.t('New revision');
          }

          return Drupal.t('No revision');
        });

      $context
        .find('details.entity-translation-options')
        .drupalSetSummary((details) => {
          const translate = details.querySelector(
            '.js-form-item-translation-translate input',
          );

          if (translate) {
            return translate.checked
              ? Drupal.t('Needs to be updated')
              : Drupal.t('Does not need to be updated');
          }

          const retranslate = details.querySelector(
            '.js-form-item-translation-retranslate input',
          );
          return retranslate?.checked
            ? Drupal.t('Flag other translations as outdated')
            : Drupal.t('Do not flag other translations as outdated');
        });
    },
  };
})(jQuery, Drupal);
