/**
 * @file
 * Shared native XMLHttpRequest transport.
 */

// cspell:ignore nocontent notmodified
((Drupal) => {
  /**
   * Sends a prepared request.
   *
   * @param {object} options
   *   The request options.
   * @param {string} options.url
   *   The request URL, relative to the document or absolute.
   * @param {string} [options.method='GET']
   *   The HTTP method.
   * @param {boolean} [options.async=true]
   *   Whether the request is asynchronous.
   * @param {string|null} [options.username=null]
   *   The user name for HTTP authentication.
   * @param {string|null} [options.password=null]
   *   The password for HTTP authentication.
   * @param {HeadersInit} [options.headers]
   *   The HTTP headers.
   * @param {XMLHttpRequestBodyInit|Document|function|null} [options.body=null]
   *   The encoded request body, or a function that returns it after
   *   beforeSend has run.
   * @param {function(XMLHttpRequest, Headers): (boolean|undefined)} [options.beforeSend]
   *   Configures the opened request, attaches listeners, or changes the
   *   headers. A header set on the request here replaces the prepared value.
   *   Return false to cancel sending it.
   * @param {XMLHttpRequest} [options.request]
   *   An unsent request to use instead of a new one.
   *
   * @return {XMLHttpRequest}
   *   The native request. A synchronous response is available on return.
   */
  Drupal.xhr = function ({
    url,
    method = 'GET',
    async = true,
    username = null,
    password = null,
    headers,
    body = null,
    beforeSend,
    request = new XMLHttpRequest(),
  }) {
    const target = new URL(url, document.baseURI);
    const requestHeaders = new Headers(headers);
    if (
      target.origin === window.location.origin &&
      !requestHeaders.has('X-Requested-With')
    ) {
      requestHeaders.set('X-Requested-With', 'XMLHttpRequest');
    }

    request.open(method, target.href, async, username, password);
    // Header writes replace the prepared headers instead of adding to them.
    request.setRequestHeader = (name, value) => {
      requestHeaders.set(name, value);
    };
    let proceed;
    try {
      proceed = beforeSend?.(request, requestHeaders);
    } finally {
      delete request.setRequestHeader;
    }
    if (proceed === false) {
      return request;
    }
    requestHeaders.forEach((value, name) => {
      request.setRequestHeader(name, value);
    });
    request.send(typeof body === 'function' ? body() : body);
    return request;
  };

  /**
   * Runs a prepared request for an Ajax command response.
   *
   * @param {object} options
   *   Transport options passed to Drupal.xhr.
   * @param {function} receive
   *   Handles the network result and returns any pending command work.
   *
   * @return {{network: Promise, commands: Promise, abort: function}}
   *   The network result, completion of the response handler, and cancellation
   *   of an in-flight request.
   *
   * @internal
   */
  Drupal.ajaxRequest = function (options, receive) {
    let finish;
    let result;
    const network = new Promise((resolve) => {
      finish = (value) => {
        result = result || value;
        resolve(value);
      };
    });
    const request = Drupal.xhr({
      ...options,
      beforeSend(xhr, headers) {
        const settle = (status, response, error) =>
          finish({ status, response, error, xhr });
        xhr.addEventListener('load', () => {
          const success =
            (xhr.status >= 200 && xhr.status < 300) || xhr.status === 304;
          if (!success) {
            settle('error', undefined, xhr.statusText);
          } else if (
            xhr.status === 204 ||
            options.method?.toUpperCase() === 'HEAD'
          ) {
            settle('nocontent');
          } else if (xhr.status === 304) {
            settle('notmodified');
          } else {
            let response;
            try {
              response = JSON.parse(xhr.responseText);
            } catch (error) {
              settle('parsererror', undefined, error);
              return;
            }
            settle('success', response);
          }
        });
        // jQuery passed an empty error for a network failure.
        ['error', 'timeout', 'abort'].forEach((status) => {
          xhr.addEventListener(status, () =>
            settle(status, undefined, status === 'error' ? '' : status),
          );
        });
        if (options.beforeSend?.(xhr, headers) === false) {
          settle('canceled');
          return false;
        }
      },
    });
    let commands;
    if (options.async === false && result) {
      // A synchronous response is handled before the request returns.
      try {
        commands = Promise.resolve(receive(result));
      } catch (error) {
        commands = Promise.reject(error);
      }
    } else {
      commands = network.then(receive);
    }
    return {
      network,
      commands,
      abort() {
        if (
          request.readyState > XMLHttpRequest.UNSENT &&
          request.readyState < XMLHttpRequest.DONE
        ) {
          request.abort();
        }
      },
    };
  };
})(Drupal);
