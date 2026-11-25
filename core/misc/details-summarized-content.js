/**
 * @file
 * Adds a summary of a details element's contents to its summary element.
 */
((Drupal) => {
  /**
   * The DetailsSummarizedContent object represents a single details element.
   *
   * @constructor Drupal.DetailsSummarizedContent
   *
   * @param {HTMLElement} node
   *   A details element, the summary of which may have supplemental text.
   *   The supplemental text summarizes the details element's contents.
   */
  function DetailsSummarizedContent(node) {
    this.node = node;
    this.setupSummary();
  }

  Object.assign(
    DetailsSummarizedContent,
    /** @lends Drupal.DetailsSummarizedContent */ {
      /**
       * Holds references to instantiated DetailsSummarizedContent objects.
       *
       * @type {Array.<Drupal.DetailsSummarizedContent>}
       */
      instances: [],
    },
  );

  Object.assign(
    DetailsSummarizedContent.prototype,
    /** @lends Drupal.DetailsSummarizedContent# */ {
      /**
       * Initialize and setup summary events and markup.
       *
       * @fires event:summaryUpdated
       *
       * @listens event:summaryUpdated
       */
      setupSummary() {
        const template = document.createElement('template');
        template.innerHTML = Drupal.theme('detailsSummarizedContentWrapper');
        this.summaryWrapper = template.content.firstElementChild;
        Drupal.form.listen(
          this.node,
          'summaryUpdated',
          this.onSummaryUpdatedNative.bind(this),
        );
        Drupal.form.publish(this.node, 'summaryUpdated');
        if (this.summaryWrapper) {
          this.node
            .querySelector(':scope > summary')
            ?.append(this.summaryWrapper);
        }
      },

      /**
       * Updates the summary from a native summaryUpdated event.
       *
       * Assign a replacement to this method to register a native override.
       * Each instance binds the method when it is constructed.
       */
      onSummaryUpdatedNative() {
        const text = Drupal.form.getSummary(this.node);
        if (this.summaryWrapper) {
          this.summaryWrapper.innerHTML = Drupal.theme(
            'detailsSummarizedContentText',
            text,
          );
        }
      },
    },
  );

  /**
   * Adds a summary of a details element's contents to its summary element.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior for the details element.
   */
  Drupal.behaviors.detailsSummary = {
    attach(context) {
      DetailsSummarizedContent.instances =
        DetailsSummarizedContent.instances.concat(
          once('details', 'details', context).map(
            (details) => new DetailsSummarizedContent(details),
          ),
        );
    },
  };

  Drupal.DetailsSummarizedContent = DetailsSummarizedContent;

  /**
   * The element containing a wrapper for summarized details content.
   *
   * @return {string}
   *   The markup for the element that will contain the summarized content.
   */
  Drupal.theme.detailsSummarizedContentWrapper = () =>
    `<span class="summary"></span>`;

  /**
   * Formats the summarized details content text.
   *
   * @param {string|null} [text]
   *   (optional) The summarized content text displayed in the summary.
   * @return {string}
   *   The formatted summarized content text.
   */
  Drupal.theme.detailsSummarizedContentText = (text) =>
    text ? ` (${text})` : '';
})(Drupal);
