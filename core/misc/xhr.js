/**
 * @file
 * Shared native XMLHttpRequest transport.
 */

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
})(Drupal);
