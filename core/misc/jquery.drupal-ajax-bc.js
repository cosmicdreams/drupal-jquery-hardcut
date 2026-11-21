/**
 * @file
 * Projects Drupal Ajax requests onto an explicitly loaded jQuery instance.
 *
 * Drupal Ajax sends its requests with the native transport. When an extension
 * declares core/jquery, this adapter keeps the jQuery request contract for
 * those requests: $.ajaxSetup() defaults, prefilters and transports registered
 * after this file loads, the jqXHR object, the global Ajax events and
 * $.active, and the jQuery Form plugin events. It follows the control flow of
 * jQuery.ajax() in the pinned jQuery 4.0.0 without calling it.
 *
 * @deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. Use the
 *   native Ajax lifecycle, request handle, form element or request options.
 *
 * @see https://www.drupal.org/node/CHANGE_RECORD
 */

// cspell:ignore jqxhr nocontent notmodified prefilter prefilters
((jQuery) => {
  if (typeof jQuery !== 'function' || !jQuery.ajaxSettings) {
    return;
  }

  const warned = new Set();

  /**
   * Reports explicit use of a deprecated request contract once.
   */
  function warnLegacy(contract, migration) {
    if (!warned.has(contract)) {
      warned.add(contract);
      window.Drupal.deprecationError({
        message: `${contract} is deprecated in drupal:12.0.0 and is removed from drupal:13.0.0. ${migration} See https://www.drupal.org/node/CHANGE_RECORD.`,
      });
    }
  }

  const defaultXhr = jQuery.ajaxSettings.xhr;
  const rNotHtmlWhite = /[^\x20\t\r\n\f]+/g;
  const rHash = /#.*$/;
  const rAntiCache = /([?&])_=[^&]*/;
  const rHeaders = /^(.*?):[ \t]*([^\r\n]*)$/gm;
  const rNoContent = /^(?:GET|HEAD)$/;
  const rProtocol = /^\/\//;
  const rQuery = /\?/;
  const r20 = /%20/g;
  const allTypes = '*/*';
  const successStatus = { 0: 200 };
  let guid = Date.now();

  const unsupported = (name) => () => {
    // eslint-disable-next-line no-console
    console.error(
      `Drupal Ajax does not reproduce the jQuery ${name} for its requests.`,
    );
  };

  /**
   * Built-in jQuery prefilters, at the positions jQuery registered them.
   */
  const builtinPrefilters = {
    contentType(s) {
      Object.keys(s.headers || {}).forEach((name) => {
        if (name.toLowerCase() === 'content-type') {
          s.contentType = s.headers[name] || '';
        }
      });
    },
    script(s) {
      if (s.cache === undefined) {
        s.cache = false;
      }
      // Requests handled with a script tag are GET requests.
      if (canUseScriptTag(s)) {
        s.type = 'GET';
      }
    },
    jsonp(s) {
      if (s.jsonp !== false || s.dataTypes[0] === 'jsonp') {
        unsupported('JSONP prefilter')();
      }
    },
    binaryData(s, original) {
      if (
        typeof s.data !== 'string' &&
        !jQuery.isPlainObject(s.data) &&
        !Array.isArray(s.data) &&
        !('processData' in original)
      ) {
        s.processData = false;
      }
      if (s.data instanceof window.FormData) {
        s.contentType = false;
      }
    },
  };

  /**
   * Sends a request with Drupal's native transport.
   *
   * @param {object} s
   *   The effective request settings.
   * @param {object} original
   *   The settings passed by the caller.
   * @param {object} jqXHR
   *   The request facade.
   *
   * @return {{send: function, abort: function}}
   *   A transport with the interface of jQuery's XMLHttpRequest transport.
   */
  function nativeTransport(s, original, jqXHR) {
    let callback;
    return {
      native: true,
      send(headers, complete) {
        const request =
          s.xhr === defaultXhr ? nativeRequests.get(jqXHR) : s.xhr();
        // Header writes and aborts during beforeSend went to the facade.
        if (s.xhr === defaultXhr) {
          delete request?.setRequestHeader;
          delete request?.abort;
        }
        if (request) {
          nativeRequests.set(jqXHR, request);
        }
        window.Drupal.xhr({
          request,
          url: s.url,
          method: s.type,
          async: s.async,
          username: s.username,
          password: s.password,
          headers,
          crossDomain: s.crossDomain,
          body: () => (s.hasContent && s.data) || null,
          beforeSend(xhr) {
            Object.entries(s.xhrFields || {}).forEach(([name, value]) => {
              xhr[name] = value;
            });
            if (s.mimeType && xhr.overrideMimeType) {
              xhr.overrideMimeType(s.mimeType);
            }
            const settle = (type) => () => {
              if (!callback) {
                return;
              }
              callback = null;
              xhr.onload = null;
              xhr.onerror = null;
              xhr.onabort = null;
              xhr.ontimeout = null;
              if (type === 'abort') {
                xhr.abort();
              } else if (type === 'error') {
                if (typeof xhr.status !== 'number') {
                  complete(0, 'error');
                } else {
                  complete(xhr.status, xhr.statusText);
                }
              } else {
                complete(
                  successStatus[xhr.status] || xhr.status,
                  xhr.statusText,
                  (xhr.responseType || 'text') !== 'text'
                    ? { binary: xhr.response }
                    : { text: xhr.responseText },
                  xhr.getAllResponseHeaders(),
                );
              }
            };
            callback = true;
            xhr.onload = settle();
            const errorCallback = settle('error');
            xhr.onerror = errorCallback;
            xhr.ontimeout = errorCallback;
            xhr.onabort = errorCallback;
            callback = settle('abort');
          },
        });
      },
      abort() {
        if (typeof callback === 'function') {
          callback();
        }
      },
    };
  }

  /**
   * Tests whether jQuery would load a script with a script tag.
   *
   * @param {object} s
   *   The effective request settings.
   *
   * @return {boolean}
   *   Whether to use a script tag.
   */
  function canUseScriptTag(s) {
    return (
      !!s.scriptAttrs ||
      (!s.headers &&
        (s.crossDomain || (s.async && !s.dataTypes.includes('json'))))
    );
  }

  /**
   * Loads a script with a script tag, as jQuery's script transport does.
   *
   * @param {object} s
   *   The effective request settings.
   *
   * @return {{send: function, abort: function}|undefined}
   *   The transport, if a script tag can be used.
   */
  function scriptTransport(s) {
    if (!canUseScriptTag(s)) {
      return undefined;
    }
    let script;
    let callback;
    return {
      send(headers, complete) {
        script = document.createElement('script');
        // As jQuery's attr(): skip undefined, and leave out null and false
        // except on ARIA attributes.
        Object.entries(s.scriptAttrs || {}).forEach(([name, value]) => {
          if (
            value === undefined ||
            value === null ||
            (value === false && !name.toLowerCase().startsWith('aria-'))
          ) {
            return;
          }
          script.setAttribute(name, value);
        });
        if (s.scriptCharset) {
          script.charset = s.scriptCharset;
        }
        script.src = s.url;
        callback = (event) => {
          script.removeEventListener('load', callback);
          script.removeEventListener('error', callback);
          script.remove();
          callback = null;
          if (event) {
            complete(event.type === 'error' ? 404 : 200, event.type);
          }
        };
        script.addEventListener('load', callback);
        script.addEventListener('error', callback);
        document.head.appendChild(script);
      },
      abort() {
        if (callback) {
          callback();
        }
      },
    };
  }

  // The native request behind each request facade, for core callbacks.
  const nativeRequests = new WeakMap();

  const prefilters = {
    '*': [builtinPrefilters.contentType, builtinPrefilters.binaryData],
    script: [builtinPrefilters.script],
    jsonp: [builtinPrefilters.jsonp],
  };
  const transports = {
    '*': [nativeTransport],
    script: [scriptTransport],
  };

  /**
   * Records a registration as jQuery's addToPrefiltersOrTransports() does.
   *
   * @param {object} structure
   *   The prefilter or transport lists.
   *
   * @return {function(string|function, function=)}
   *   The registration function.
   */
  function addTo(structure) {
    return (expression, func) => {
      if (typeof expression !== 'string') {
        func = expression;
        expression = '*';
      }
      if (typeof func !== 'function') {
        return;
      }
      (expression.toLowerCase().match(rNotHtmlWhite) || []).forEach(
        (dataType) => {
          if (dataType[0] === '+') {
            dataType = dataType.slice(1) || '*';
            (structure[dataType] = structure[dataType] || []).unshift(func);
          } else {
            (structure[dataType] = structure[dataType] || []).push(func);
          }
        },
      );
    };
  }

  /**
   * Runs prefilters or selects a transport as jQuery does.
   *
   * @param {object} structure
   *   The prefilter or transport lists.
   * @param {object} s
   *   The effective request settings.
   * @param {object} original
   *   The settings passed by the caller.
   * @param {object} jqXHR
   *   The request facade.
   *
   * @return {object|undefined}
   *   The selected transport, when seeking one.
   */
  function inspect(structure, s, original, jqXHR) {
    const inspected = {};
    const seekingTransport = structure === transports;
    function run(dataType) {
      let selected;
      inspected[dataType] = true;
      (structure[dataType] || []).some((prefilterOrFactory) => {
        const result = prefilterOrFactory(s, original, jqXHR);
        if (
          typeof result === 'string' &&
          !seekingTransport &&
          !inspected[result]
        ) {
          s.dataTypes.unshift(result);
          run(result);
          return true;
        }
        if (seekingTransport) {
          selected = result;
          return !!selected;
        }
        return false;
      });
      return selected;
    }
    return run(s.dataTypes[0]) || (!inspected['*'] && run('*'));
  }

  /**
   * Selects the response for the expected data type, as jQuery does.
   *
   * @param {object} s
   *   The effective request settings.
   * @param {object} jqXHR
   *   The request facade.
   * @param {object} responses
   *   Responses keyed by data type.
   *
   * @return {*}
   *   The selected response.
   */
  function handleResponses(s, jqXHR, responses) {
    let contentType;
    const { contents, dataTypes } = s;
    while (dataTypes[0] === '*') {
      dataTypes.shift();
      if (contentType === undefined) {
        contentType = s.mimeType || jqXHR.getResponseHeader('Content-Type');
      }
    }
    if (contentType) {
      Object.keys(contents).some((type) => {
        if (contents[type] && contents[type].test(contentType)) {
          dataTypes.unshift(type);
          return true;
        }
        return false;
      });
    }
    let finalDataType;
    if (dataTypes[0] in responses) {
      [finalDataType] = dataTypes;
    } else {
      let firstDataType;
      Object.keys(responses).some((type) => {
        if (!dataTypes[0] || s.converters[`${type} ${dataTypes[0]}`]) {
          finalDataType = type;
          return true;
        }
        firstDataType = firstDataType || type;
        return false;
      });
      finalDataType = finalDataType || firstDataType;
    }
    if (finalDataType) {
      if (finalDataType !== dataTypes[0]) {
        dataTypes.unshift(finalDataType);
      }
      return responses[finalDataType];
    }
  }

  /**
   * Converts a response along the data type chain, as jQuery does.
   *
   * @param {object} s
   *   The effective request settings.
   * @param {*} response
   *   The selected response.
   * @param {object} jqXHR
   *   The request facade.
   * @param {boolean} isSuccess
   *   Whether the HTTP status was successful.
   *
   * @return {{state: string, data: *, error: *}}
   *   The conversion result.
   */
  function convert(s, response, jqXHR, isSuccess) {
    const converters = {};
    const dataTypes = s.dataTypes.slice();
    if (dataTypes[1]) {
      Object.keys(s.converters).forEach((name) => {
        converters[name.toLowerCase()] = s.converters[name];
      });
    }
    let current = dataTypes.shift();
    let previous;
    while (current) {
      if (s.responseFields[current]) {
        jqXHR[s.responseFields[current]] = response;
      }
      if (!previous && isSuccess && s.dataFilter) {
        response = s.dataFilter(response, s.dataType);
      }
      previous = current;
      current = dataTypes.shift();
      if (current) {
        if (current === '*') {
          current = previous;
        } else if (previous !== '*' && previous !== current) {
          let converter =
            converters[`${previous} ${current}`] || converters[`* ${current}`];
          if (!converter) {
            Object.keys(converters).some((name) => {
              const [from, to] = name.split(' ');
              if (to === current) {
                converter =
                  converters[`${previous} ${from}`] || converters[`* ${from}`];
                if (converter) {
                  if (converter === true) {
                    converter = converters[name];
                  } else if (converters[name] !== true) {
                    current = from;
                    dataTypes.unshift(to);
                  }
                  return true;
                }
              }
              return false;
            });
          }
          if (converter !== true) {
            if (converter && s.throws) {
              response = converter(response);
            } else {
              try {
                response = converter(response);
              } catch (e) {
                return {
                  state: 'parsererror',
                  error: converter
                    ? e
                    : `No conversion from ${previous} to ${current}`,
                };
              }
            }
          }
        }
      }
    }
    return { state: 'success', data: response };
  }

  /**
   * Tests whether jQuery's global completion events wait for Drupal commands.
   *
   * @param {object} xhr
   *   The request facade.
   * @param {object} settings
   *   The effective request settings.
   *
   * @return {boolean}
   *   Whether the response commands are still running.
   */
  function commandsPending(xhr, settings) {
    return (
      // Trusted iframe responses can reach Drupal without a token header.
      (received.has(xhr) ||
        xhr?.getResponseHeader?.('X-Drupal-Ajax-Token') === '1') &&
      // The isInProgress() function might not be defined if the Ajax request
      // was initiated without Drupal.ajax() or new Drupal.Ajax().
      typeof settings?.isInProgress === 'function' &&
      // Until this is false, the Ajax request isn't completely done (the
      // response's commands might still be running).
      settings.isInProgress()
    );
  }

  // Requests whose global completion events wait for their commands, and
  // command promises waiting for the queue, keyed by request facade.
  const pendingCompletion = new WeakMap();
  const commandWaiters = new WeakMap();
  const successData = new WeakMap();
  // Requests sent by this adapter, which delays their events itself, and
  // those whose response Drupal's success callback received.
  const facades = new WeakSet();
  const received = new WeakSet();

  // A raw $.ajax() call can still carry Drupal Ajax settings, so keep delaying
  // its global completion events until the commands have run.
  ['ajaxSuccess', 'ajaxComplete'].forEach((type) => {
    const special =
      jQuery.event.special[type] || (jQuery.event.special[type] = {});
    const previous = special.trigger;
    special.trigger = function (event, xhr, settings, data) {
      if (!facades.has(xhr) && commandsPending(xhr, settings)) {
        if (type === 'ajaxSuccess') {
          successData.set(xhr, data);
        } else {
          // Keep the request counted while its commands run.
          jQuery.active++;
          pendingCompletion.set(xhr, {
            target: jQuery(this),
            settings,
            data: successData.get(xhr),
          });
        }
        return false;
      }
      // A deferred event invokes the earlier hook when it is replayed.
      return previous?.apply(this, arguments);
    };
  });

  /**
   * Sends Drupal Ajax request settings following jQuery.ajax().
   *
   * @param {object} options
   *   The request settings.
   * @param {function} [onCancel]
   *   Called if the request ends before its callbacks are registered, as when
   *   a prefilter or beforeSend aborts it.
   *
   * @return {object}
   *   The jqXHR-shaped request facade.
   */
  function send(options, onCancel) {
    let transport;
    let responseHeadersString;
    let responseHeaders;
    let timeoutTimer;
    let completed;
    let cacheURL;
    // As in jQuery, globals stay off until the prefilters have run.
    let fireGlobals;
    let strAbort = 'canceled';
    let callbacksAdded = false;
    const s = jQuery.ajaxSetup({}, options);
    const callbackContext = s.context || s;
    const globalEventContext =
      s.context && (callbackContext.nodeType || callbackContext.jquery)
        ? jQuery(callbackContext)
        : jQuery.event;
    const deferred = jQuery.Deferred();
    const completeDeferred = jQuery.Callbacks('once memory');
    let statusCode = s.statusCode || {};
    const requestHeaders = {};
    const requestHeadersNames = {};
    let done;

    const jqXHR = {
      readyState: 0,
      getResponseHeader(key) {
        let match;
        if (completed) {
          if (!responseHeaders) {
            responseHeaders = {};
            rHeaders.lastIndex = 0;
            // eslint-disable-next-line no-cond-assign
            while ((match = rHeaders.exec(responseHeadersString))) {
              const name = `${match[1].toLowerCase()} `;
              responseHeaders[name] = (responseHeaders[name] || []).concat(
                match[2],
              );
            }
          }
          match = responseHeaders[`${key.toLowerCase()} `];
        }
        return match == null ? null : match.join(', ');
      },
      getAllResponseHeaders() {
        return completed ? responseHeadersString : null;
      },
      setRequestHeader(name, value) {
        if (completed == null) {
          requestHeadersNames[name.toLowerCase()] =
            requestHeadersNames[name.toLowerCase()] || name;
          name = requestHeadersNames[name.toLowerCase()];
          requestHeaders[name] = value;
        }
        return this;
      },
      overrideMimeType(type) {
        if (completed == null) {
          s.mimeType = type;
        }
        return this;
      },
      statusCode(map) {
        if (map) {
          if (completed) {
            jqXHR.always(map[jqXHR.status]);
          } else {
            Object.keys(map).forEach((code) => {
              statusCode[code] = [statusCode[code], map[code]];
            });
          }
        }
        return this;
      },
      abort(statusText) {
        const finalText = statusText || strAbort;
        if (transport) {
          transport.abort(finalText);
        }
        done(0, finalText);
        return this;
      },
    };
    deferred.promise(jqXHR);
    facades.add(jqXHR);
    // The native request handle members, so core sees the same handle type.
    let settleNetwork;
    let settleCommands;
    let failCommands;
    jqXHR.network = new Promise((resolve) => {
      settleNetwork = resolve;
    });
    jqXHR.commands = new Promise((resolve, reject) => {
      settleCommands = resolve;
      failCommands = reject;
    });
    // A failure is reported where it is thrown; callers can still observe it.
    jqXHR.commands.catch(() => {});

    /**
     * Completes the request as jQuery's done() does.
     *
     * @param {number} status
     *   The HTTP status, 0 or -1.
     * @param {string} nativeStatusText
     *   The status text.
     * @param {object} [responses]
     *   Responses keyed by data type.
     * @param {string} [headers]
     *   The raw response headers.
     */
    done = (status, nativeStatusText, responses, headers) => {
      let statusText = nativeStatusText;
      let success;
      let error;
      if (completed) {
        return;
      }
      completed = true;
      if (timeoutTimer) {
        window.clearTimeout(timeoutTimer);
      }
      transport = undefined;
      responseHeadersString = headers || '';
      jqXHR.readyState = status > 0 ? 4 : 0;
      let isSuccess = (status >= 200 && status < 300) || status === 304;
      // Callbacks, filters and observers may throw, as Drupal's error handling
      // does. Finish the request's bookkeeping first, then rethrow the first
      // error.
      let failure;
      const attempt = (callback) => {
        try {
          callback();
        } catch (e) {
          failure = failure || { error: e };
        }
      };
      let response;
      if (responses) {
        response = handleResponses(s, jqXHR, responses);
      }
      if (
        !isSuccess &&
        s.dataTypes.includes('script') &&
        !s.dataTypes.includes('json')
      ) {
        s.converters['text script'] = () => {};
      }
      try {
        response = convert(s, response, jqXHR, isSuccess);
      } catch (e) {
        // A throwing dataFilter, or a converter with the throws option.
        failure = { error: e };
        response = { state: 'parsererror', error: e };
      }
      if (isSuccess) {
        if (s.ifModified) {
          let modified = jqXHR.getResponseHeader('Last-Modified');
          if (modified) {
            jQuery.lastModified[cacheURL] = modified;
          }
          modified = jqXHR.getResponseHeader('etag');
          if (modified) {
            jQuery.etag[cacheURL] = modified;
          }
        }
        if (status === 204 || s.type === 'HEAD') {
          statusText = 'nocontent';
        } else if (status === 304) {
          statusText = 'notmodified';
        } else {
          statusText = response.state;
          success = response.data;
          error = response.error;
          isSuccess = !error;
        }
      } else {
        error = statusText;
        if (status || !statusText) {
          statusText = 'error';
          if (status < 0) {
            status = 0;
          }
        }
      }
      jqXHR.status = status;
      jqXHR.statusText = `${nativeStatusText || statusText}`;
      // As on the native lane, the network result is known before callbacks.
      settleNetwork({
        status: statusText,
        response: success,
        error,
        xhr: jqXHR,
      });

      attempt(() => {
        if (isSuccess) {
          deferred.resolveWith(callbackContext, [success, statusText, jqXHR]);
        } else {
          deferred.rejectWith(callbackContext, [jqXHR, statusText, error]);
        }
      });
      attempt(() => jqXHR.statusCode(statusCode));
      statusCode = undefined;

      // Drupal responses run their commands after Drupal's success callback.
      // If that callback ran, the global completion events wait for the
      // commands, as before, even if another callback threw.
      const queued =
        isSuccess && received.has(jqXHR) && commandsPending(jqXHR, s);
      const waiting = fireGlobals && queued;
      if (fireGlobals && !waiting) {
        attempt(() =>
          globalEventContext.trigger(isSuccess ? 'ajaxSuccess' : 'ajaxError', [
            jqXHR,
            s,
            isSuccess ? success : error,
          ]),
        );
      }
      attempt(() =>
        completeDeferred.fireWith(callbackContext, [jqXHR, statusText]),
      );
      // The commands promise follows the Drupal command queue, and reports a
      // callback failure once the queue has finished.
      if (
        isSuccess &&
        received.has(jqXHR) &&
        typeof s.isInProgress === 'function' &&
        s.isInProgress()
      ) {
        commandWaiters.set(jqXHR, [
          failure ? () => failCommands(failure.error) : settleCommands,
          failCommands,
        ]);
      } else if (failure) {
        failCommands(failure.error);
      } else {
        settleCommands();
      }
      if (waiting) {
        pendingCompletion.set(jqXHR, {
          target: globalEventContext,
          settings: s,
          data: success,
        });
      } else if (fireGlobals) {
        attempt(() => globalEventContext.trigger('ajaxComplete', [jqXHR, s]));
        if (!--jQuery.active) {
          attempt(() => jQuery.event.trigger('ajaxStop'));
        }
      }
      // A request cancelled before its callbacks were added runs none.
      if (!callbacksAdded && onCancel) {
        attempt(onCancel);
      }
      if (failure) {
        throw failure.error;
      }
    };

    s.url = `${s.url || window.location.href}`.replace(
      rProtocol,
      `${window.location.protocol}//`,
    );
    s.type = options.method || options.type || s.method || s.type;
    s.dataTypes = (s.dataType || '*').toLowerCase().match(rNotHtmlWhite) || [
      '',
    ];
    if (s.xhr !== defaultXhr || s.crossDomain != null) {
      warnLegacy(
        'Using jQuery request options with Drupal Ajax',
        'Use the native Ajax lifecycle, request handle, form element or request options.',
      );
    }
    if (s.crossDomain == null) {
      try {
        s.crossDomain =
          new URL(s.url, document.baseURI).origin !== window.location.origin;
      } catch (e) {
        s.crossDomain = true;
      }
    }

    try {
      inspect(prefilters, s, options, jqXHR);
      if (s.data && s.processData && typeof s.data !== 'string') {
        // eslint-disable-next-line no-jquery/no-param -- Preserve legacy serialization.
        s.data = jQuery.param(s.data, s.traditional);
      }
      if (completed) {
        return jqXHR;
      }

      fireGlobals = jQuery.event && s.global;
      if (fireGlobals && jQuery.active++ === 0) {
        jQuery.event.trigger('ajaxStart');
      }
      s.type = s.type.toUpperCase();
      s.hasContent = !rNoContent.test(s.type);
      cacheURL = s.url.replace(rHash, '');
      if (!s.hasContent) {
        let uncached = s.url.slice(cacheURL.length);
        if (s.data && (s.processData || typeof s.data === 'string')) {
          cacheURL += (rQuery.test(cacheURL) ? '&' : '?') + s.data;
          delete s.data;
        }
        if (s.cache === false) {
          cacheURL = cacheURL.replace(rAntiCache, '$1');
          uncached = `${rQuery.test(cacheURL) ? '&' : '?'}_=${guid++}${uncached}`;
        }
        s.url = cacheURL + uncached;
      } else if (
        s.data &&
        s.processData &&
        (s.contentType || '').indexOf('application/x-www-form-urlencoded') === 0
      ) {
        s.data = s.data.replace(r20, '+');
      }
      if (s.ifModified) {
        if (jQuery.lastModified[cacheURL]) {
          jqXHR.setRequestHeader(
            'If-Modified-Since',
            jQuery.lastModified[cacheURL],
          );
        }
        if (jQuery.etag[cacheURL]) {
          jqXHR.setRequestHeader('If-None-Match', jQuery.etag[cacheURL]);
        }
      }
      if (
        (s.data && s.hasContent && s.contentType !== false) ||
        options.contentType
      ) {
        jqXHR.setRequestHeader('Content-Type', s.contentType);
      }
      jqXHR.setRequestHeader(
        'Accept',
        s.dataTypes[0] && s.accepts[s.dataTypes[0]]
          ? s.accepts[s.dataTypes[0]] +
              (s.dataTypes[0] !== '*' ? `, ${allTypes}; q=0.01` : '')
          : s.accepts['*'],
      );
      Object.keys(s.headers || {}).forEach((name) => {
        jqXHR.setRequestHeader(name, s.headers[name]);
      });
      // Core's own callbacks receive the native request. Until it is sent, its
      // header writes and abort go through the facade, as the jqXHR's would.
      const request = new XMLHttpRequest();
      request.setRequestHeader = (name, value) => {
        jqXHR.setRequestHeader(name, value);
      };
      request.abort = () => {
        jqXHR.abort();
      };
      nativeRequests.set(jqXHR, request);
      if (
        s.beforeSend &&
        (s.beforeSend.call(callbackContext, jqXHR, s) === false || completed)
      ) {
        return jqXHR.abort();
      }
      strAbort = 'abort';
      callbacksAdded = true;
      completeDeferred.add(s.complete);
      jqXHR.done(s.success);
      jqXHR.fail(s.error);

      transport = inspect(transports, s, options, jqXHR);
      if (!transport?.native) {
        // A contributed transport has no native request to offer.
        nativeRequests.delete(jqXHR);
      }
      if (!transport) {
        done(-1, 'No Transport');
      } else {
        jqXHR.readyState = 1;
        if (fireGlobals) {
          globalEventContext.trigger('ajaxSend', [jqXHR, s]);
        }
        if (completed) {
          return jqXHR;
        }
      }
    } catch (e) {
      // A prefilter, observer, beforeSend or transport factory threw. Do not
      // send, and finish the request before reporting the error.
      if (!completed) {
        try {
          jqXHR.abort();
        } catch (secondary) {
          // Keep the original error; report the one from the cleanup.
          window.reportError(secondary);
        }
      }
      throw e;
    }
    if (transport) {
      if (s.async && s.timeout > 0) {
        timeoutTimer = window.setTimeout(() => {
          jqXHR.abort('timeout');
        }, s.timeout);
      }
      try {
        completed = false;
        transport.send(requestHeaders, done);
      } catch (e) {
        if (completed) {
          throw e;
        }
        done(-1, e);
      }
    }
    return jqXHR;
  }

  /**
   * Fires the delayed global completion events once commands have run.
   *
   * @param {object} xhr
   *   The request facade.
   * @param {object} context
   *   The success callback context.
   * @param {*} [error]
   *   The reason the command queue failed, if it did.
   */
  function commandsComplete(xhr, context, error) {
    const [resolve, reject] = commandWaiters.get(xhr) || [];
    commandWaiters.delete(xhr);
    const pending = pendingCompletion.get(xhr);
    pendingCompletion.delete(xhr);
    let failure;
    if (pending) {
      const target = pending.target || jQuery(document);
      const settings = pending.settings || context;
      const trigger = (type, args) => {
        try {
          target.trigger(type, args);
        } catch (e) {
          failure = failure || { error: e };
        }
      };
      trigger('ajaxSuccess', [xhr, settings, pending.data]);
      trigger('ajaxComplete', [xhr, settings]);
      if (--jQuery.active === 0) {
        try {
          jQuery.event.trigger('ajaxStop');
        } catch (e) {
          failure = failure || { error: e };
        }
      }
    }
    if (error === undefined) {
      resolve?.();
    } else {
      reject?.(error);
    }
    if (failure) {
      throw failure.error;
    }
  }

  // Capture registrations from now on, and still register them with jQuery
  // for raw $.ajax() requests.
  const { ajaxPrefilter, ajaxTransport } = jQuery;
  const addPrefilter = addTo(prefilters);
  const addTransport = addTo(transports);
  jQuery.ajaxPrefilter = function (...args) {
    addPrefilter(...args);
    return ajaxPrefilter.apply(this, args);
  };
  jQuery.ajaxTransport = function (...args) {
    addTransport(...args);
    return ajaxTransport.apply(this, args);
  };

  // Record the original methods before dependent scripts can override them.
  const originalDomMethods = new Map(
    [
      'html',
      'replaceWith',
      'append',
      'prepend',
      'before',
      'after',
      'remove',
    ].map((method) => [method, jQuery.fn[method]]),
  );

  /**
   * Uses the explicit Form plugin after Drupal has run the form callbacks.
   */
  function submitLegacyForm($form, options, prepared) {
    warnLegacy(
      'Submitting Drupal Ajax forms through the jQuery Form plugin',
      'Use Drupal AJAX with native form options, or supply your own form plugin.',
    );
    const { beforeSend, error: onError, complete } = options;
    let completed = false;
    let canceled = false;
    let beforeSendError;
    const cancel = () => {
      if (!completed && !canceled) {
        canceled = true;
        prepared.cancel();
        if (beforeSendError) {
          window.alert(
            `An error occurred while attempting to process ${options.url}: ${beforeSendError.message}`,
          );
        }
      }
    };
    const settings = {
      ...options,
      beforeSend(xhr, requestSettings) {
        try {
          return beforeSend?.call(this, xhr, requestSettings);
        } catch (error) {
          beforeSendError = error;
          return false;
        }
      },
      error(...args) {
        completed = true;
        try {
          return onError?.apply(this, args);
        } catch (error) {
          // jQuery must still call complete and release the global request.
          window.Drupal.throwError(error);
        }
      },
      complete(...args) {
        completed = true;
        try {
          return complete?.apply(this, args);
        } catch (error) {
          // Let the plugin and jQuery finish dispatching global events first.
          window.Drupal.throwError(error);
        }
      },
      drupalAjaxPreparedForm: {
        ...prepared,
        cancel,
        track(request) {
          request.fail(cancel);
        },
      },
    };
    try {
      $form.ajaxSubmit(settings);
      return $form.data('jqxhr');
    } catch (error) {
      cancel();
      throw error;
    }
  }

  const triggerFrames = [];
  const trigger = jQuery.event.trigger;
  jQuery.event.trigger = function (event, data, target) {
    const type = typeof event === 'string' ? event : event.type;
    triggerFrames.push({
      target: target || document,
      type: type.split('.')[0],
    });
    try {
      return trigger.apply(this, arguments);
    } finally {
      triggerFrames.pop();
    }
  };

  // These libraries can be attached before or after core/jquery.
  function installCollectionAccessor(
    prototype,
    legacy,
    native,
    contract,
    single = false,
  ) {
    if (!prototype) {
      return;
    }
    const collections = new WeakMap();
    Object.defineProperty(prototype, legacy, {
      configurable: true,
      get() {
        warnLegacy(contract, `Use the native ${native} property.`);
        const value = this[native];
        if (single && !value) {
          return undefined;
        }
        const cached = collections.get(this);
        if (cached && cached.value === value) {
          return cached.collection;
        }
        const collection = jQuery(value);
        collections.set(this, { value, collection });
        return collection;
      },
      set(input) {
        warnLegacy(contract, `Use the native ${native} property.`);
        const collection = jQuery(input);
        const value = single ? collection[0] : collection.toArray();
        this[native] = value;
        collections.set(this, {
          value,
          collection: input?.jquery ? input : collection,
        });
      },
    });
  }

  const ajaxProgressObjects = new WeakSet();
  function bridgeAjaxProgress(progress) {
    if (typeof progress === 'object' && !ajaxProgressObjects.has(progress)) {
      const initial = Object.getOwnPropertyDescriptor(
        progress,
        'element',
      )?.value;
      if (initial && !progress.elementNode) {
        [progress.elementNode] = jQuery(initial);
      }
      installCollectionAccessor(
        progress,
        'element',
        'elementNode',
        'Drupal.Ajax#progress.element',
        true,
      );
      ajaxProgressObjects.add(progress);
    }
  }

  function installProgressAccessor() {
    const progress = window.Drupal?.ProgressBar;
    if (progress) {
      progress.request = send;
    }
    installCollectionAccessor(
      window.Drupal?.ProgressBar?.prototype,
      'element',
      'elementNode',
      'Drupal.ProgressBar#element',
      true,
    );
  }

  function installViewsAccessors() {
    const prototype = window.Drupal?.views?.ajaxView?.prototype;
    installCollectionAccessor(
      prototype,
      '$view',
      'views',
      'Drupal.views.ajaxView#$view',
    );
    installCollectionAccessor(
      prototype,
      '$exposed_form',
      'exposedForms',
      'Drupal.views.ajaxView#$exposed_form',
    );
  }

  installProgressAccessor();
  installViewsAccessors();
  document.addEventListener('drupal:progress-ready', installProgressAccessor, {
    once: true,
  });
  document.addEventListener('drupal:views-ajax-ready', installViewsAccessors, {
    once: true,
  });

  const wrappedForms = new WeakMap();

  /**
   * Connects the adapter to Drupal Ajax.
   */
  function install() {
    const { Drupal } = window;
    if (!Drupal?.Ajax || Drupal.ajax.compatibility) {
      return;
    }
    const subscriptions = new Map();
    const handlers = new Map();
    const removalTypes = new Set();
    const delivering = new Set();
    const observeRemoval = (type) => {
      if (removalTypes.has(type)) {
        return;
      }
      removalTypes.add(type);
      const special =
        jQuery.event.special[type] || (jQuery.event.special[type] = {});
      const previous = special.remove;
      special.remove = function (handle) {
        try {
          previous?.call(this, handle);
        } finally {
          const binding = handlers.get(handle.guid);
          if (binding) {
            handlers.delete(handle.guid);
            subscriptions.delete(binding);
            binding.remove();
          }
        }
      };
    };
    let adapter;
    Drupal.eventBindings.observe({
      add(binding) {
        const { ajax, target, type, namespace, listener } = binding;
        if (!ajax) {
          return;
        }
        const name = namespace ? `${type}.${namespace}` : type;
        const bindType = jQuery.event.special[type]?.bindType || type;
        observeRemoval(type);
        observeRemoval(bindType);
        const deliver = (event) => {
          if (!adapter || Drupal.ajax.compatibility !== adapter) {
            return;
          }
          const original = event.originalEvent;
          if (!event.isTrigger && original) {
            if (Drupal.eventBindings.isForeignDefaultAction?.(original)) {
              return;
            }
            const result = listener(event);
            // Marked native callbacks may cancel the original DOM event.
            if (original.defaultPrevented && !event.isDefaultPrevented()) {
              event.preventDefault();
            }
            if (original.cancelBubble && !event.isPropagationStopped()) {
              event.stopPropagation();
            }
            return result;
          }
          if (!event.isTrigger || delivering.has(event)) {
            return;
          }
          delivering.add(event);
          try {
            return listener(event);
          } finally {
            delivering.delete(event);
          }
        };
        const element = jQuery(target);
        element.on(name, deliver);
        handlers.set(deliver.guid, binding);
        subscriptions.set(binding, { name, bindType, deliver, element });
      },
      remove(binding) {
        const subscription = subscriptions.get(binding);
        if (subscription) {
          handlers.delete(subscription.deliver.guid);
          subscriptions.delete(binding);
          subscription.element.off(subscription.name, subscription.deliver);
        }
      },
    });
    adapter = {
      hasEventBinding(target, type, event) {
        if (
          Drupal.ajax.compatibility !== adapter ||
          adapter.isSyntheticDefaultAction(event)
        ) {
          return false;
        }
        return jQuery
          ._data(target, 'events')
          ?.[event.type]?.some(({ guid }) => handlers.get(guid)?.type === type);
      },
      handlesNativeEvent(binding, event) {
        const subscription = subscriptions.get(binding);
        if (
          !subscription ||
          jQuery.event.triggered === event.type ||
          (subscription.bindType !== event.type && !event.isTrusted)
        ) {
          return false;
        }
        // Trust the installed handler's slot in jQuery's dispatch queue, even
        // when jQuery maps the native event type (for example, mouseenter).
        const handlers = jQuery._data(binding.target, 'events')?.[
          subscription.bindType
        ];
        return handlers?.some(({ guid }) => guid === subscription.deliver.guid);
      },
      isSyntheticDefaultAction(event) {
        if (Drupal.eventBindings.isForeignDefaultAction?.(event)) {
          return true;
        }
        const frame = triggerFrames.at(-1);
        if (frame?.type !== event.type || frame?.target !== event.target) {
          return false;
        }
        let synthetic = jQuery.event.triggered === event.type;
        if (!synthetic && ['click', 'focus', 'blur'].includes(event.type)) {
          // The pinned leverageNative handler stores arguments during the DOM
          // method call, then replaces them with the result. Our listener can
          // run on either side of that handler.
          const phase = jQuery._data(event.target, event.type);
          synthetic = Array.isArray(phase)
            ? !delivering.has(phase[0])
            : !!phase && Object.hasOwn(phase, 'value');
        }
        if (synthetic) {
          frame.defaultEvent = frame.defaultEvent || event;
          return frame.defaultEvent === event;
        }
        return false;
      },
      asEvent: (event) =>
        typeof event.isDefaultPrevented === 'function'
          ? event
          : jQuery.Event(event),
      withNativeEvent(event, invoke) {
        if (event instanceof Event) {
          return invoke(event);
        }
        const original = event.originalEvent;
        if (original instanceof Event && original.type === event.type) {
          const method = 'stopImmediatePropagation';
          const descriptor = Object.getOwnPropertyDescriptor(original, method);
          if (
            descriptor ? descriptor.configurable : Object.isExtensible(original)
          ) {
            const stop = original[method];
            // Use jQuery's cancellation method so its dispatch loop also
            // skips later handlers on this target. Keep the real DOM event.
            Object.defineProperty(original, method, {
              configurable: true,
              writable: true,
              value() {
                if (
                  this !== original ||
                  event.isImmediatePropagationStopped()
                ) {
                  return stop.call(this);
                }
                return event.stopImmediatePropagation();
              },
            });
            try {
              return invoke(original);
            } finally {
              if (descriptor) {
                Object.defineProperty(original, method, descriptor);
              } else {
                delete original[method];
              }
            }
          }
        }
        // Project synthetic or mapped events without dispatching a second
        // event. Cancellation still applies to the active dispatch.
        const native = new Event(event.type, { cancelable: true });
        Object.defineProperties(native, {
          target: { value: event.target },
          currentTarget: { value: event.currentTarget },
          key: { value: event.key },
          which: { value: event.which },
          submitter: { value: event.submitter },
          defaultPrevented: {
            get: () => event.isDefaultPrevented?.() ?? event.defaultPrevented,
          },
          preventDefault: { value: () => event.preventDefault() },
          stopPropagation: { value: () => event.stopPropagation() },
          stopImmediatePropagation: {
            value: () => event.stopImmediatePropagation(),
          },
        });
        return invoke(native);
      },
      css(response) {
        warnLegacy(
          'Using jQuery Ajax selector, method or effect inputs',
          'Use a CSS selector and supported native command options.',
        );
        // eslint-disable-next-line no-jquery/no-css -- Honor contributed CSS hooks.
        jQuery(response.selector).css(response.argument);
      },
      data(response) {
        warnLegacy(
          "DataCommand's jQuery-data semantics",
          "Use Drupal's element data API.",
        );
        const { name, value } = response;
        const targets = jQuery(response.selector);
        targets.data(name, value);
        if (value !== undefined && Drupal.ajax.elementData.isLegacyKey(name)) {
          targets.each((index, element) => {
            Drupal.ajax.elementData.set(
              element,
              name,
              jQuery.data(element, name),
            );
          });
        }
      },
      getData: (element, key) => jQuery.data(element, key),
      cloneContent(content) {
        return content?.jquery ? content.clone(true, true).get() : undefined;
      },
      cleanData: (nodes) => jQuery.cleanData(nodes),
      wrapMultipleRoots: (nodes) => jQuery('<div></div>').append(nodes),
      bridgeAjaxProgress,
      warnLegacy,
      send,
      commandsComplete,
      serializeLegacyForm($form, settings) {
        if (typeof $form.formToArray === 'function') {
          const elements = [];
          const values = $form.formToArray(
            settings.semantic,
            elements,
            settings.filtering,
          );
          return { values, elements };
        }
      },
      submitLegacyForm,
      rejected(error) {
        const handle = jQuery.Deferred().reject();
        const failed = Promise.reject(error);
        failed.catch(() => {});
        handle.network = failed;
        handle.commands = failed;
        handle.abort = () => handle;
        return handle;
      },
      native: (xhr) => nativeRequests.get(xhr) || xhr,
      received: (xhr) => received.add(xhr),
      defaults: () => jQuery.ajaxSettings,
      hasDomMethodOverride: (method) =>
        originalDomMethods.get(method) !== jQuery.fn[method],
      wrap: (form) => jQuery(form),
      select: (selector) => jQuery(selector).toArray(),
      trigger(element, type, args) {
        jQuery(element).trigger(type, args);
      },
      notify(form, args, request) {
        // The jQuery Form plugin stored the request before notifying.
        jQuery(form).removeData('jqxhr').data('jqxhr', request);
        jQuery(form).trigger('form-submit-notify', args);
      },
    };
    Drupal.ajax.compatibility = adapter;
    Drupal.ajax.instances.forEach((ajax) => {
      if (ajax?.progress) {
        bridgeAjaxProgress(ajax.progress);
      }
    });
    // The jQuery form member is an accessor over the native form.
    Object.defineProperty(Drupal.Ajax.prototype, '$form', {
      configurable: true,
      get() {
        warnLegacy('Drupal.Ajax#$form', 'Use Drupal.Ajax#form.');
        if (!this.form) {
          return undefined;
        }
        let wrapped = wrappedForms.get(this);
        if (!wrapped || wrapped[0] !== this.form) {
          wrapped = jQuery(this.form);
          wrappedForms.set(this, wrapped);
        }
        return wrapped;
      },
      set(value) {
        warnLegacy('Drupal.Ajax#$form', 'Use Drupal.Ajax#form.');
        if (value && value.jquery) {
          [this.form] = value;
          wrappedForms.set(this, value);
        } else {
          this.form = value;
        }
      },
    });
  }

  if (window.Drupal?.Ajax) {
    install();
  } else {
    document.addEventListener('drupal:ajax-ready', install, { once: true });
  }
})(window.jQuery);
