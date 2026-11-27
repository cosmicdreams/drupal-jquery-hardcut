/**
 * @file
 * Marks the off-canvas dialog once its size has been reported.
 */

window.addEventListener('dialogContentResize', ({ target }) => {
  if (Drupal.offCanvas.isOffCanvas(target)) {
    target.dataset.resizeDone = 'true';
  }
});
