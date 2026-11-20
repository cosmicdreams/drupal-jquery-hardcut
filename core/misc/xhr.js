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
   * @param {HeadersInit} [options.headers]
   *   The HTTP headers.
   * @param {XMLHttpRequestBodyInit|Document|null} [options.body=null]
   *   The encoded request body.
   * @param {function(XMLHttpRequest, Headers): (boolean|undefined)} [options.beforeSend]
   *   Configures the opened request, attaches listeners, or changes the
   *   headers. Return false to cancel sending it.
   *
   * @return {XMLHttpRequest}
   *   The native request. A synchronous response is available on return.
   */
  Drupal.xhr = function ({
    url,
    method = 'GET',
    async = true,
    headers,
    body = null,
    beforeSend,
  }) {
    const target = new URL(url, document.baseURI);
    const requestHeaders = new Headers(headers);
    if (
      target.origin === window.location.origin &&
      !requestHeaders.has('X-Requested-With')
    ) {
      requestHeaders.set('X-Requested-With', 'XMLHttpRequest');
    }

    const request = new XMLHttpRequest();
    request.open(method, target.href, async);
    if (beforeSend?.(request, requestHeaders) === false) {
      return request;
    }
    requestHeaders.forEach((value, name) => {
      request.setRequestHeader(name, value);
    });
    request.send(body);
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
    const network = new Promise((resolve) => {
      finish = resolve;
    });
    const request = Drupal.xhr({
      ...options,
      beforeSend(xhr, headers) {
        const settle = (status, response, error) =>
          finish({ status, response, error, xhr });
        xhr.addEventListener('load', () => {
          if (xhr.status === 304) {
            settle('notmodified');
          } else if (xhr.status < 200 || xhr.status >= 300) {
            settle('error', undefined, xhr.statusText);
          } else if (
            xhr.status === 204 ||
            options.method?.toUpperCase() === 'HEAD'
          ) {
            settle('nocontent');
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
        ['error', 'timeout', 'abort'].forEach((status) => {
          xhr.addEventListener(status, () => settle(status));
        });
        if (options.beforeSend?.(xhr, headers) === false) {
          settle('canceled');
          return false;
        }
      },
    });
    return {
      network,
      commands: network.then(receive),
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
