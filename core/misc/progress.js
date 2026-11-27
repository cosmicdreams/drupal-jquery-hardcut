/**
 * @file
 * Progress bar.
 */

// cspell:ignore nocontent notmodified
((Drupal) => {
  /**
   * Theme function for the progress bar.
   *
   * @param {string} id
   *   The id for the progress bar.
   *
   * @return {string}
   *   The HTML for the progress bar.
   */
  Drupal.theme.progressBar = function (id) {
    const escapedId = Drupal.checkPlain(id);
    return (
      `<div id="${escapedId}" class="progress" aria-live="polite">` +
      '<div class="progress__label">&nbsp;</div>' +
      '<div class="progress__track"><div class="progress__bar"></div></div>' +
      '<div class="progress__percentage"></div>' +
      '<div class="progress__description">&nbsp;</div>' +
      '</div>'
    );
  };

  /**
   * A progressbar object. Initialized with the given id. Must be inserted into
   * the DOM afterwards through progressBar.elementNode.
   *
   * Method is the function which will perform the HTTP request to get the
   * progress bar state. Either "GET" or "POST".
   *
   * @example
   * pb = new Drupal.ProgressBar('myProgressBar');
   * some_element.appendChild(pb.elementNode);
   *
   * @constructor
   *
   * @param {string} id
   *   The id for the progressbar.
   * @param {function} updateCallback
   *   Callback to run on update.
   * @param {string} method
   *   HTTP method to use.
   * @param {function} errorCallback
   *   Callback to call on error.
   */
  Drupal.ProgressBar = function (id, updateCallback, method, errorCallback) {
    this.id = id;
    this.method = method || 'GET';
    this.updateCallback = updateCallback;
    this.errorCallback = errorCallback;

    // The WAI-ARIA setting aria-live="polite" will announce changes after
    // users
    // have completed their current activity and not interrupt the screen
    // reader.
    const template = document.createElement('template');
    template.innerHTML = Drupal.theme('progressBar', id);
    this.elementNode = template.content.firstElementChild;
  };

  Object.assign(Drupal.ProgressBar.prototype, {
    /**
     * Set the percentage and status message for the progressbar.
     *
     * @param {number} percentage
     *   The progress percentage.
     * @param {string} message
     *   The message to show the user.
     * @param {string} label
     *   The text for the progressbar label.
     */
    setProgress(percentage, message, label) {
      if (percentage >= 0 && percentage <= 100) {
        this.elementNode.querySelectorAll('.progress__bar').forEach((bar) => {
          bar.style.width = `${percentage}%`;
        });
        this.elementNode
          .querySelectorAll('.progress__percentage')
          .forEach((label) => {
            label.textContent = `${percentage}%`;
          });
      }
      for (const [part, value] of [
        ['description', message],
        ['label', label],
      ]) {
        if (value !== undefined) {
          this.elementNode
            .querySelectorAll(`.progress__${part}`)
            .forEach((element) => {
              element.innerHTML = value;
            });
        }
      }
      if (this.updateCallback) {
        this.updateCallback(percentage, message, this);
      }
    },

    /**
     * Start monitoring progress via Ajax.
     *
     * @param {string} uri
     *   The URI to use for monitoring.
     * @param {number} delay
     *   The delay for calling the monitoring URI.
     */
    startMonitoring(uri, delay) {
      this.delay = delay;
      this.uri = uri;
      this.sendPing();
    },

    /**
     * Stop monitoring progress via Ajax.
     */
    stopMonitoring() {
      clearTimeout(this.timer);
      // This allows monitoring to be stopped from within the callback.
      this.uri = null;
    },

    /**
     * Request progress data from server.
     */
    sendPing() {
      if (this.timer) {
        clearTimeout(this.timer);
      }
      if (!this.uri) {
        return;
      }

      const uri = new URL(this.uri, document.baseURI);
      uri.search += `${uri.search ? '&' : '?'}_format=json`;
      const method = this.method.toUpperCase();
      const success = (response) => {
        if (response?.status === 0) {
          this.displayError(response.data);
          return;
        }
        if (response != null) {
          this.setProgress(
            response.percentage,
            response.message,
            response.label,
          );
        }
        if (this.uri) {
          this.timer = setTimeout(() => this.sendPing(), this.delay);
        }
      };
      const error = (xhr) => {
        this.displayError(
          `<pre>${new Drupal.AjaxError(xhr, this.uri).message}</pre>`,
        );
      };
      Drupal.ajaxRequest(
        {
          url: uri.href,
          method,
          headers: { Accept: 'application/json, text/javascript, */*; q=0.01' },
        },
        ({ status, response, xhr }) => {
          if (['success', 'nocontent', 'notmodified'].includes(status)) {
            success(response);
          } else {
            error(xhr);
          }
        },
      ).commands.catch(Drupal.throwError);
    },

    /**
     * Display errors on the page.
     *
     * @param {string} string
     *   The error message to show the user.
     */
    displayError(string) {
      const error = document.createElement('div');
      error.className = 'messages messages--error';
      error.innerHTML = string;
      this.elementNode.before(error);
      this.elementNode.style.display = 'none';

      if (this.errorCallback) {
        this.errorCallback(this);
      }
    },
  });
})(Drupal);
