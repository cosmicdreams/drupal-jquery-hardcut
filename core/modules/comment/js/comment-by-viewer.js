/**
 * @file
 * Attaches behaviors for the Comment module's "by-viewer" class.
 */

(function (Drupal, drupalSettings) {
  /**
   * Add 'by-viewer' class to comments written by the current user.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.commentByViewer = {
    attach() {
      const currentUserID = parseInt(drupalSettings.user.uid, 10);
      document.querySelectorAll('[data-comment-user-id]').forEach((comment) => {
        const authorID = parseInt(
          comment.getAttribute('data-comment-user-id'),
          10,
        );
        if (authorID === currentUserID) {
          comment.classList.add('by-viewer');
        }
      });
    },
  };
})(Drupal, drupalSettings);
