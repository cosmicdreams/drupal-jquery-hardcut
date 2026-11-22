/**
 * @file
 * Drupal's batch API.
 */

(function (Drupal) {
  /**
   * Attaches the batch behavior to progress bars.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.batch = {
    attach(context, settings) {
      const batch = settings.batch;
      const progressContainers = once('batch', '[data-drupal-progress]');

      // Success: redirect to the summary.
      function updateCallback(progress, status, pb) {
        if (progress === '100') {
          pb.stopMonitoring();
          const url = new URL(batch.uri, window.location.origin);
          url.searchParams.set('op', 'finished');
          window.location = url.toString();
        }
      }

      function errorCallback() {
        progressContainers.forEach((container) => {
          const error = document.createElement('p');
          error.className = 'error';
          error.innerHTML = batch.errorMessage;
          container.prepend(error);
        });
        const wait = document.getElementById('wait');
        if (wait) {
          wait.style.display = 'none';
        }
      }

      if (progressContainers.length) {
        const progressBar = new Drupal.ProgressBar(
          'updateprogress',
          updateCallback,
          'POST',
          errorCallback,
        );
        progressBar.setProgress(-1, batch.initMessage);
        const url = new URL(batch.uri, window.location.origin);
        url.searchParams.set('op', 'do');
        progressBar.startMonitoring(url.toString(), 10);
        // Replace the no-JavaScript progress markup.
        progressContainers.forEach((container, index) => {
          const element = progressBar.elementNode;
          container.replaceChildren(
            index === progressContainers.length - 1
              ? element
              : element.cloneNode(true),
          );
        });
      }
    },
  };
})(Drupal);
