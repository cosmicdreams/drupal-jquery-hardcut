/**
 * @file
 *  Support code for testing AJAX requests in functional tests.
 */
(() => {
  window.drupalCumulativeXhrCount = 0;
  window.drupalActiveXhrCount = 0;
  window.drupalTestHasActiveAnimations = () =>
    window.Drupal?.hasActiveAnimations?.() ?? false;

  function incrementActive() {
    window.drupalActiveXhrCount = (window.drupalActiveXhrCount ?? 0) + 1;
  }

  function decrementActive() {
    // Some tests delete the counter to acknowledge an unfinished request.
    if (typeof window.drupalActiveXhrCount === 'number') {
      window.drupalActiveXhrCount--;
    }
  }

  const pending = new WeakMap();
  const { open, send } = XMLHttpRequest.prototype;

  XMLHttpRequest.prototype.open = function trackOpen(...args) {
    const finish = pending.get(this);
    const result = open.apply(this, args);
    // Reinitializing an active request can reset it without a loadend event.
    finish?.();
    return result;
  };

  XMLHttpRequest.prototype.send = function trackSend(...args) {
    if (pending.has(this)) {
      return send.apply(this, args);
    }

    const finish = () => {
      if (pending.get(this) !== finish) {
        return;
      }
      this.removeEventListener('loadend', finish);
      pending.delete(this);
      decrementActive();
    };
    pending.set(this, finish);
    this.addEventListener('loadend', finish);
    incrementActive();

    try {
      const result = send.apply(this, args);
      window.drupalCumulativeXhrCount++;
      return result;
    } catch (error) {
      // An invalid send() does not start a request or dispatch loadend.
      finish();
      throw error;
    }
  };

  // Catch calls to native fetch().
  const oldFetch = window.fetch;
  window.fetch = function newFetch(resource, options) {
    window.drupalCumulativeXhrCount++;
    incrementActive();

    return oldFetch
      .call(window, resource, options)
      .then(async (response) => {
        try {
          await response.clone().text();
          // Fetch normally "completes" when the header arrives, but the test
          // content won't be processed at that point. This occasionally creates
          // a race condition.  The line above ensures this promise won't
          // continue until every byte of the response body is received.
        } catch {
          // Ignore errors while reading the monitoring copy.
        }
        return response;
      })
      .finally(() => {
        // Decrement the active count even if the fetch() promise is rejected.
        decrementActive();
      });
  };
})();
