/**
 * @file
 * AJAX commands used by Editor module.
 */

((Drupal) => {
  /**
   * Command to save the contents of an editor-provided modal.
   *
   * This command does not close the open modal. It should be followed by a
   * call to `Drupal.AjaxCommands.prototype.closeDialog`. Editors that are
   * integrated with dialogs must independently listen for an
   * `editor:dialogsave` event to save the changes into the contents of their
   * interface.
   *
   * @param {Drupal.Ajax} ajax
   *   The Drupal.Ajax object.
   * @param {object} response
   *   The server response from the ajax request.
   * @param {Array} response.values
   *   The values that were saved.
   *
   * @fires event:editor:dialogsave
   */
  Drupal.AjaxCommands.prototype.editorDialogSave = function (ajax, response) {
    const { dialogOrigin } = ajax;
    if (dialogOrigin && Drupal.dialog.hasOpenOrigin(dialogOrigin)) {
      window.dispatchEvent(
        new CustomEvent('editor:dialogsave', {
          detail: { dialog: dialogOrigin.element, values: response.values },
        }),
      );
    }
  };
})(Drupal);
