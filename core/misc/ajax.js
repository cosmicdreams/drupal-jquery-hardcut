/**
 * @file
 * Provides Ajax page updating.
 *
 * Ajax is a method of making a request via JavaScript while viewing an HTML
 * page. The request returns an array of commands encoded in JSON, which is
 * then executed to make any changes that are necessary to the page.
 *
 * Drupal uses this file to enhance form elements with `#ajax['url']` and
 * `#ajax['wrapper']` properties. If set, this file will automatically be
 * included to provide Ajax capabilities.
 */

// cspell:ignore nocontent notmodified

(function (window, Drupal, drupalSettings, loadjs, { isFocusable, tabbable }) {
  const rCRLF = /\r?\n/g;
  const rBracket = /\[\]$/;
  const eventNames = (names) =>
    typeof names === 'string' ? (names.match(/[^\x20\t\r\n\f]+/g) ?? []) : [];
  const multipartType = 'multipart/form-data';
  const builtinTypes = {
    '[object Boolean]': 'boolean',
    '[object Number]': 'number',
    '[object String]': 'string',
    '[object Function]': 'function',
    '[object Array]': 'array',
    '[object Date]': 'date',
    '[object RegExp]': 'regexp',
    '[object Object]': 'object',
    '[object Error]': 'error',
    '[object Symbol]': 'symbol',
  };
  const accepts = {
    '*': '*/*',
    text: 'text/plain',
    html: 'text/html',
    xml: 'application/xml, text/xml',
    json: 'application/json, text/javascript',
    script:
      'text/javascript, application/javascript, application/ecmascript, application/x-ecmascript',
  };

  /**
   * Returns the type name used by the pinned jQuery.param().
   *
   * @param {*} value
   *   The value to inspect.
   *
   * @return {string}
   *   The type name.
   */
  function typeOf(value) {
    if (value == null) {
      return `${value}`;
    }
    return typeof value === 'object'
      ? builtinTypes[Object.prototype.toString.call(value)] || 'object'
      : typeof value;
  }

  /**
   * Checks for an object created by an object literal or Object.create(null).
   *
   * @param {*} value
   *   The value to inspect.
   *
   * @return {boolean}
   *   Whether the value is a plain object.
   */
  function isPlainObject(value) {
    if (Object.prototype.toString.call(value) !== '[object Object]') {
      return false;
    }
    const prototype = Object.getPrototypeOf(value);
    return (
      !prototype ||
      (Object.prototype.hasOwnProperty.call(prototype, 'constructor') &&
        prototype.constructor === Object)
    );
  }

  /**
   * Deeply copies plain objects and arrays as jQuery.extend(true, ...) did.
   *
   * @param {object} target
   *   The object to copy into.
   * @param {...object} sources
   *   The objects to copy from, in order.
   *
   * @return {object}
   *   The target.
   */
  function extendDeep(target, ...sources) {
    sources.forEach((source) => {
      // eslint-disable-next-line no-restricted-syntax, guard-for-in
      for (const name in source) {
        const copy = source[name];
        if (name !== '__proto__' && target !== copy) {
          const copyIsArray = Array.isArray(copy);
          if (copy && (copyIsArray || isPlainObject(copy))) {
            const current = target[name];
            let clone;
            if (copyIsArray) {
              clone = Array.isArray(current) ? current : [];
            } else {
              clone = isPlainObject(current) ? current : {};
            }
            target[name] = extendDeep(clone, copy);
          } else if (copy !== undefined) {
            target[name] = copy;
          }
        }
      }
    });
    return target;
  }

  /**
   * Adds one value or nested structure to a parameter list.
   *
   * @param {string} prefix
   *   The parameter name.
   * @param {*} value
   *   The value.
   * @param {boolean} traditional
   *   Whether to use the shallow, traditional encoding.
   * @param {function(string, *)} add
   *   Adds one encoded pair.
   */
  function buildParams(prefix, value, traditional, add) {
    if (Array.isArray(value)) {
      // Visit every index, including holes, as jQuery.each() did.
      for (let index = 0; index < value.length; index++) {
        const item = value[index];
        if (traditional || rBracket.test(prefix)) {
          add(prefix, item);
        } else {
          buildParams(
            `${prefix}[${typeof item === 'object' && item != null ? index : ''}]`,
            item,
            traditional,
            add,
          );
        }
      }
    } else if (!traditional && typeOf(value) === 'object') {
      // eslint-disable-next-line no-restricted-syntax, guard-for-in
      for (const name in value) {
        buildParams(`${prefix}[${name}]`, value[name], traditional, add);
      }
    } else {
      add(prefix, value);
    }
  }

  /**
   * Encodes data as the pinned jQuery.param() did.
   *
   * @param {object|Array<{name: string, value: *}>} data
   *   An object, or a list of name and value pairs.
   * @param {boolean} [traditional=false]
   *   Whether to use the shallow, traditional encoding.
   *
   * @return {string}
   *   The encoded parameters.
   *
   * @internal
   */
  function param(data, traditional = false) {
    const parts = [];
    const add = (key, valueOrFunction) => {
      const value =
        typeof valueOrFunction === 'function'
          ? valueOrFunction()
          : valueOrFunction;
      parts.push(
        `${encodeURIComponent(key)}=${encodeURIComponent(value == null ? '' : value)}`,
      );
    };
    if (data == null) {
      return '';
    }
    if (Array.isArray(data)) {
      Array.from(data).forEach(({ name, value }) => add(name, value));
    } else {
      // eslint-disable-next-line no-restricted-syntax, guard-for-in
      for (const prefix in data) {
        buildParams(prefix, data[prefix], traditional, add);
      }
    }
    return parts.join('&');
  }

  /**
   * Returns the submitted value of a form control.
   *
   * @param {HTMLElement} element
   *   The form control.
   * @param {boolean} [successful=true]
   *   Whether to return null for a control that would not be submitted.
   *
   * @return {string|Array<string>|null}
   *   The value, the selected values of a multiple select, or null.
   *
   * @internal
   */
  function fieldValue(element, successful = true) {
    const { name, type } = element;
    const tag = element.tagName.toLowerCase();
    if (
      successful &&
      (!name ||
        element.disabled ||
        type === 'reset' ||
        type === 'button' ||
        ((type === 'checkbox' || type === 'radio') && !element.checked) ||
        ((type === 'submit' || type === 'image') &&
          element.form &&
          element.form.clk !== element) ||
        (tag === 'select' && element.selectedIndex === -1))
    ) {
      return null;
    }
    if (tag === 'select') {
      const index = element.selectedIndex;
      if (index < 0) {
        return null;
      }
      const values = [];
      const one = type === 'select-one';
      const max = one ? index + 1 : element.options.length;
      for (let i = one ? index : 0; i < max; i++) {
        const option = element.options[i];
        if (option.selected && !option.disabled) {
          if (one) {
            return option.value;
          }
          values.push(option.value);
        }
      }
      return values;
    }
    const { value } = element;
    return (
      typeof value === 'string' ? value.replace(/\r/g, '') : `${value ?? ''}`
    ).replace(rCRLF, '\r\n');
  }

  /**
   * Collects the successful controls of a form in submission order.
   *
   * @param {HTMLFormElement} form
   *   The form, including its clicked control in `form.clk`.
   *
   * @return {Array<{name: string, value: *, type: (string|undefined), required: (boolean|undefined)}>}
   *   The submitted entries.
   *
   * @internal
   */
  function formToArray(form) {
    const values = [];
    Array.from(form.elements).forEach((element) => {
      const { name } = element;
      if (!name || element.disabled) {
        return;
      }
      const value = fieldValue(element);
      if (Array.isArray(value)) {
        value.forEach((item) => values.push({ name, value: item }));
      } else if (element.type === 'file') {
        if (element.files.length) {
          Array.from(element.files).forEach((file) =>
            values.push({ name, value: file, type: element.type }),
          );
        } else {
          values.push({ name, value: '', type: element.type });
        }
      } else if (value !== null && value !== undefined) {
        values.push({
          name,
          value,
          type: element.type,
          required: element.required,
        });
      }
    });
    const { clk } = form;
    if (clk && clk.name && !clk.disabled && clk.type === 'image') {
      values.push(
        { name: clk.name, value: clk.value },
        { name: `${clk.name}.x`, value: form.clk_x },
        { name: `${clk.name}.y`, value: form.clk_y },
      );
    }
    return values;
  }

  /**
   * Calls one callback or a list of callbacks.
   *
   * @param {function|Array<function>|undefined} callbacks
   *   The callbacks.
   * @param {object} context
   *   The value of `this`.
   * @param {Array} args
   *   The arguments.
   *
   * @return {Array}
   *   The results of the callbacks.
   */
  function callAll(callbacks, context, args) {
    return [callbacks]
      .flat(Infinity)
      .filter((callback) => typeof callback === 'function')
      .map((callback) => callback.apply(context, args));
  }

  // Cache-busting values, as jQuery generated them.
  let nonce = Date.now();

  /**
   * Sends request settings through the native request transport.
   *
   * @param {object} options
   *   Request settings with the keys of Drupal.Ajax#options.
   * @param {function} [onCancel]
   *   Called if beforeSend cancels the request.
   *
   * @return {{network: Promise, commands: Promise, abort: function}}
   *   The request handle.
   */
  function sendRequest(options, onCancel) {
    const settings = { ...options };
    const context = settings.context || settings;
    settings.type = (settings.method || settings.type || 'GET').toUpperCase();
    settings.hasContent = !/^(?:GET|HEAD)$/.test(settings.type);
    const dataType = (settings.dataType || '*').toLowerCase().split(/\s+/)[0];
    let { contentType } = settings;
    if (contentType === undefined) {
      contentType = 'application/x-www-form-urlencoded; charset=UTF-8';
    }
    Object.keys(settings.headers || {}).forEach((name) => {
      if (name.toLowerCase() === 'content-type') {
        contentType = settings.headers[name] || '';
      }
    });
    let processData = settings.processData !== false;
    if (
      typeof settings.data !== 'string' &&
      !isPlainObject(settings.data) &&
      !Array.isArray(settings.data) &&
      !('processData' in options)
    ) {
      processData = false;
    }
    if (settings.data instanceof FormData) {
      contentType = false;
    }
    if (settings.data && processData && typeof settings.data !== 'string') {
      settings.data = param(settings.data, settings.traditional);
    }

    let url = `${settings.url || window.location.href}`;
    if (!settings.hasContent) {
      const hashIndex = url.indexOf('#');
      const hash = hashIndex < 0 ? '' : url.slice(hashIndex);
      url = hashIndex < 0 ? url : url.slice(0, hashIndex);
      if (settings.data && (processData || typeof settings.data === 'string')) {
        url += (url.includes('?') ? '&' : '?') + settings.data;
        delete settings.data;
      }
      if (settings.cache === false) {
        url = url.replace(/([?&])_=[^&]*/, '$1');
        url += `${url.includes('?') ? '&' : '?'}_=${nonce++}`;
      }
      url += hash;
    } else if (
      settings.data &&
      processData &&
      `${contentType || ''}`.startsWith('application/x-www-form-urlencoded')
    ) {
      settings.data = settings.data.replace(/%20/g, '+');
    }
    settings.url = url;

    const headers = new Headers();
    if (
      (settings.data && settings.hasContent && contentType !== false) ||
      options.contentType
    ) {
      headers.set('Content-Type', contentType);
    }
    headers.set(
      'Accept',
      accepts[dataType]
        ? accepts[dataType] + (dataType !== '*' ? ', */*; q=0.01' : '')
        : accepts['*'],
    );
    Object.entries(settings.headers || {}).forEach(([name, value]) => {
      headers.set(name, value);
    });

    const transport = {
      url,
      method: settings.type,
      async: settings.async !== false,
      username: settings.username,
      password: settings.password,
    };
    const request = Drupal.ajaxRequest(
      {
        ...transport,
        headers,
        body: () => (settings.hasContent && settings.data) || null,
        canceled: onCancel,
        beforeSend(xhr) {
          if (
            settings.beforeSend &&
            settings.beforeSend.call(context, xhr, settings) === false
          ) {
            return false;
          }
          // As jQuery opened the request after beforeSend, use its changes.
          const opened = {
            url: settings.url,
            method: `${settings.type}`.toUpperCase(),
            async: settings.async !== false,
            username: settings.username,
            password: settings.password,
          };
          if (
            Object.keys(opened).some((key) => opened[key] !== transport[key])
          ) {
            xhr.open(
              opened.method,
              new URL(opened.url, document.baseURI).href,
              opened.async,
              opened.username ?? null,
              opened.password ?? null,
            );
            // The response is classified by the method actually sent.
            Object.assign(this, opened);
          }
          // A synchronous request cannot have a timeout.
          if (settings.async !== false && settings.timeout > 0) {
            xhr.timeout = settings.timeout;
          }
          Object.entries(settings.xhrFields || {}).forEach(([name, value]) => {
            xhr[name] = value;
          });
          if (settings.mimeType) {
            xhr.overrideMimeType(settings.mimeType);
          }
        },
      },
      ({ status, response, error, xhr }) => {
        // As in jQuery, a request cancelled before sending has no callbacks.
        if (status === 'canceled') {
          return;
        }
        let results = [];
        let failure;
        // Run the complete callbacks even if an earlier callback throws.
        const attempt = (callback) => {
          try {
            return callback();
          } catch (e) {
            failure = failure || { error: e };
          }
          return [];
        };
        if (['success', 'notmodified', 'nocontent'].includes(status)) {
          results = attempt(() =>
            callAll(settings.success, context, [response, status, xhr]),
          );
        } else {
          attempt(() => callAll(settings.error, context, [xhr, status, error]));
        }
        attempt(() => callAll(settings.complete, context, [xhr, status]));
        if (failure) {
          // Report callback errors, such as Drupal.AjaxError, as uncaught
          // errors, as when they were thrown from the request's load handler.
          window.reportError(failure.error);
          throw failure.error;
        }
        // Wait for every success callback, including the command queue.
        return Promise.all(results.flat(Infinity));
      },
    );
    // The error was reported; callers can still observe the rejection.
    request.commands.catch(() => {});
    return request;
  }

  /**
   * Returns a handle for a request that failed before it was sent.
   *
   * @param {Error} error
   *   The failure.
   *
   * @return {object}
   *   A rejected request handle.
   */
  function rejectedRequest(error) {
    const failed = Promise.reject(error);
    failed.catch(() => {});
    return { network: failed, commands: failed, abort() {} };
  }

  // Ajax objects whose element beforeSend prepared for the current attempt,
  // and requests whose default success callback ran.
  const preparedRequests = new WeakSet();
  const receivedRequests = new WeakSet();
  const startedRequests = new WeakSet();

  /**
   * Fired on document before Drupal Ajax calls the beforeSend method.
   *
   * The event's detail.ajax is the Drupal.Ajax instance. The event is
   * observational. A beforeSend callback can cancel the request.
   *
   * @event Drupal.Ajax#drupal:ajax-before-send
   * @type {CustomEvent}
   */

  /**
   * Fired on document after the command queue and afterSuccess have settled,
   * or after request state is restored on failure or cancellation. Only
   * attempts that fired drupal:ajax-before-send fire this event.
   *
   * @event Drupal.Ajax#drupal:ajax-complete
   * @type {CustomEvent}
   * @property {Drupal.Ajax} detail.ajax
   *   The Ajax instance.
   */

  /**
   * Publishes completion after the response queue or request cleanup.
   */
  function finishRequest(ajax) {
    if (ajax.form?.clk) {
      ajax.form.clk = null;
    }
    if (startedRequests.delete(ajax)) {
      document.dispatchEvent(
        new CustomEvent('drupal:ajax-complete', {
          detail: { ajax },
        }),
      );
    }
  }

  /**
   * Undoes the state an Ajax request attempt changed on its element.
   *
   * @param {Drupal.Ajax} ajax
   *   The Ajax object.
   * @param {boolean} [reattach=true]
   *   Whether to reattach behaviors detached before serialization.
   * @param {boolean} [cancelled=false]
   *   Whether the attempt ended before it was sent.
   */
  function restoreElement(ajax, reattach = true, cancelled = false) {
    ajax.ajaxing = false;
    const prepared = preparedRequests.delete(ajax);
    // Remove the progress element.
    ajax.progress?.elementNode?.remove();
    if (ajax.progress?.object) {
      ajax.progress.object.stopMonitoring();
    }
    // Re-enable the element. A cancelled attempt only re-enables it if
    // beforeSend disabled it.
    if (!cancelled || prepared) {
      if (ajax.element) {
        ajax.element.disabled = false;
      }
    }
    // Reattach behaviors, if they were detached in beforeSerialize(), and the
    // form is still part of the document.
    if (reattach && ajax.form && document.body.contains(ajax.form)) {
      const settings = ajax.settings || drupalSettings;
      Drupal.attachBehaviors(ajax.form, settings);
    }
    finishRequest(ajax);
  }

  /**
   * Serializes and submits a form using the Ajax request callbacks.
   *
   * @param {HTMLFormElement} form
   *   The form.
   * @param {object} options
   *   Request settings with the keys of Drupal.Ajax#options.
   * @param {function(boolean)} [onVeto]
   *   Called if a callback vetoes the submission, with whether
   *   the serialize callback had already run.
   *
   * @return {object|undefined}
   *   The request handle, or undefined if a callback vetoed the submission.
   */
  function submitForm(form, options, onVeto = () => {}) {
    const method =
      options.method || options.type || form.getAttribute('method');
    const action = options.url || form.getAttribute('action');
    let url = (typeof action === 'string' ? action.trim() : '') || '';
    url = url || window.location.href || '';
    url = (url.match(/^([^#]+)/) || [])[1];
    const settings = extendDeep({ url, type: method || 'GET' }, options);

    if (settings.beforeSerialize?.(form, settings) === false) {
      onVeto(true);
      return;
    }

    const { traditional } = settings;
    const values = formToArray(form);
    let extraQuery;
    if (settings.data) {
      const data =
        typeof settings.data === 'function'
          ? settings.data(values)
          : settings.data;
      settings.extraData = data;
      extraQuery = param(data, traditional);
    }
    if (settings.beforeSubmit?.(values, form, settings) === false) {
      onVeto(true);
      return;
    }

    let query = param(values, traditional);
    if (extraQuery) {
      query = query ? `${query}&${extraQuery}` : extraQuery;
    }
    if (settings.type.toUpperCase() === 'GET') {
      settings.url += (settings.url.includes('?') ? '&' : '?') + query;
      settings.data = null;
    } else {
      settings.data = query;
    }

    // Include file inputs associated with the form from outside it.
    const hasFiles = Array.from(form.elements).some(
      (input) =>
        input.type === 'file' &&
        input.matches(':enabled') &&
        input.value !== '',
    );
    const multipart =
      form.getAttribute('enctype') === multipartType ||
      form.getAttribute('encoding') === multipartType;
    if (hasFiles || multipart) {
      const body = new FormData();
      values.forEach(({ name, value }) => body.append(name, value));
      if (settings.extraData) {
        // Matches the plugin's decoding of jQuery.param() output.
        param(settings.extraData, settings.traditional)
          .split('&')
          .forEach((pair) => {
            const [name, value] = pair.replace(/\+/g, ' ').split('=');
            body.append(decodeURIComponent(name), decodeURIComponent(value));
          });
      }
      settings.data = null;
      const upload = extendDeep({}, settings, {
        contentType: false,
        processData: false,
        cache: false,
        type: method || 'POST',
      });
      upload.data = null;
      const { beforeSend } = upload;
      upload.beforeSend = function (xhr, requestSettings) {
        requestSettings.data = settings.formData || body;
        // Cancel the request if beforeSend vetoes it.
        if (beforeSend) {
          return beforeSend.call(this, xhr, requestSettings);
        }
      };
      return sendRequest(upload, () => onVeto(true));
    }
    return sendRequest(settings, () => onVeto(true));
  }

  /**
   * Attaches the Ajax behavior to each Ajax form element.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Initialize all {@link Drupal.Ajax} objects declared in
   *   `drupalSettings.ajax` or initialize {@link Drupal.Ajax} objects from
   *   DOM elements having the `use-ajax-submit` or `use-ajax` css class.
   * @prop {Drupal~behaviorDetach} detach
   *   During `unload` remove all {@link Drupal.Ajax} objects related to
   *   the removed content.
   */
  Drupal.behaviors.AJAX = {
    attach(context, settings) {
      function loadAjaxBehavior(base) {
        const elementSettings = settings.ajax[base];
        if (typeof elementSettings.selector === 'undefined') {
          elementSettings.selector = `#${base}`;
        }
        const { selector } = elementSettings;
        const targets = commandTargets(selector);
        once('drupal-ajax', targets).forEach((el) => {
          elementSettings.element = el;
          elementSettings.base = base;
          Drupal.ajax(elementSettings);
        });
      }

      // Load all Ajax behaviors specified in the settings.
      Object.keys(settings.ajax || {}).forEach(loadAjaxBehavior);

      Drupal.ajax.bindAjaxLinks(document.body);

      // This class means to submit the form to the action using Ajax.
      once('ajax', '.use-ajax-submit').forEach((el) => {
        const elementSettings = {
          // Ajax submits specified in this manner automatically submit to the
          // normal form action.
          url: el.form?.getAttribute('action') ?? undefined,
          // Form submit button clicks need to tell the form what was clicked so
          // it gets passed in the POST request.
          setClick: true,
          // Form buttons use the 'click' event rather than mousedown.
          event: 'click',
          // Clicked form buttons look better with the throbber than the progress
          // bar.
          progress: { type: 'throbber' },
          base: el.id,
          element: el,
        };

        Drupal.ajax(elementSettings);
      });
    },

    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        Drupal.ajax.expired().forEach((instance) => {
          // Set this to null and allow garbage collection to reclaim
          // the memory.
          instance.removeEventBindings?.();
          Drupal.ajax.instances[instance.instanceIndex] = null;
        });
      }
    },
  };

  /**
   * Extends Error to provide handling for Errors in Ajax.
   *
   * @constructor
   *
   * @augments Error
   *
   * @param {XMLHttpRequest} xmlhttp
   *   XMLHttpRequest object used for the failed request.
   * @param {string} uri
   *   The URI where the error occurred.
   * @param {string} customMessage
   *   The custom message.
   */
  Drupal.AjaxError = function (xmlhttp, uri, customMessage) {
    let statusCode;
    let statusText;
    let responseText;
    if (xmlhttp.status) {
      statusCode = `\n${Drupal.t('An AJAX HTTP error occurred.')}\n${Drupal.t(
        'HTTP Result Code: !status',
        {
          '!status': xmlhttp.status,
        },
      )}`;
    } else {
      statusCode = `\n${Drupal.t(
        'An AJAX HTTP request terminated abnormally.',
      )}`;
    }
    statusCode += `\n${Drupal.t('Debugging information follows.')}`;
    const pathText = `\n${Drupal.t('Path: !uri', { '!uri': uri })}`;
    statusText = '';
    // In some cases, when statusCode === 0, xmlhttp.statusText may not be
    // defined. Unfortunately, testing for it with typeof, etc, doesn't seem to
    // catch that and the test causes an exception. So we need to catch the
    // exception here.
    try {
      statusText = `\n${Drupal.t('StatusText: !statusText', {
        '!statusText': xmlhttp.statusText.trim(),
      })}`;
    } catch (e) {
      // Empty.
    }

    responseText = '';
    // Again, we don't have a way to know for sure whether accessing
    // xmlhttp.responseText is going to throw an exception. So we'll catch it.
    try {
      responseText = `\n${Drupal.t('ResponseText: !responseText', {
        '!responseText': xmlhttp.responseText.trim(),
      })}`;
    } catch (e) {
      // Empty.
    }

    // Make the responseText more readable by stripping HTML tags and newlines.
    responseText = responseText.replace(/<("[^"]*"|'[^']*'|[^'">])*>/gi, '');
    responseText = responseText.replace(/[\n]+\s+/g, '\n');

    // We don't need readyState except for status == 0.
    const readyStateText =
      xmlhttp.status === 0
        ? `\n${Drupal.t('ReadyState: !readyState', {
            '!readyState': xmlhttp.readyState,
          })}`
        : '';

    customMessage = customMessage
      ? `\n${Drupal.t('CustomMessage: !customMessage', {
          '!customMessage': customMessage,
        })}`
      : '';

    /**
     * Formatted and translated error message.
     *
     * @type {string}
     */
    this.message =
      statusCode +
      pathText +
      statusText +
      customMessage +
      responseText +
      readyStateText;

    /**
     * Used by some browsers to display a more accurate stack trace.
     *
     * @type {string}
     */
    this.name = 'AjaxError';

    if (!Drupal.AjaxError.messages) {
      Drupal.AjaxError.messages = new Drupal.Message();
    }
    Drupal.AjaxError.messages.add(
      Drupal.t(
        "Oops, something went wrong. Check your browser's developer console for more details.",
      ),
      {
        type: 'error',
      },
    );
  };

  Drupal.AjaxError.prototype = new Error();
  Drupal.AjaxError.prototype.constructor = Drupal.AjaxError;

  /**
   * Provides Ajax page updating.
   *
   * This function is designed to improve developer experience by wrapping the
   * initialization of {@link Drupal.Ajax} objects and storing all created
   * objects in the {@link Drupal.ajax.instances} array.
   *
   * @example
   * Drupal.behaviors.myCustomAJAXStuff = {
   *   attach: function (context, settings) {
   *
   *     var ajaxSettings = {
   *       url: 'my/url/path',
   *       // If the old version of Drupal.ajax() needs to be used those
   *       // properties can be added
   *       base: 'myBase',
   *       element: context.querySelector('.someElement')
   *     };
   *
   *     var myAjaxObject = Drupal.ajax(ajaxSettings);
   *
   *     // Declare a new Ajax command specifically for this Ajax object.
   *     myAjaxObject.commands.insert = function (ajax, response, status) {
   *       document.querySelector('#my-wrapper').insertAdjacentHTML('beforeend', response.data);
   *       alert('New content was appended to #my-wrapper');
   *     };
   *
   *     // This command will remove this Ajax object from the page.
   *     myAjaxObject.commands.destroyObject = function (ajax, response, status) {
   *       Drupal.ajax.instances[this.instanceIndex] = null;
   *     };
   *
   *     // Programmatically trigger the Ajax request.
   *     myAjaxObject.execute();
   *   }
   * };
   *
   * @param {object} settings
   *   The settings object passed to {@link Drupal.Ajax} constructor.
   * @param {string} [settings.base]
   *   Base is passed to {@link Drupal.Ajax} constructor as the 'base'
   *   parameter.
   * @param {HTMLElement} [settings.element]
   *   Element parameter of {@link Drupal.Ajax} constructor, element on which
   *   event listeners will be bound.
   *
   * @return {Drupal.Ajax}
   *   The created Ajax object.
   *
   * @see Drupal.AjaxCommands
   */
  Drupal.ajax = function (settings) {
    if (arguments.length !== 1) {
      throw new Error(
        'Drupal.ajax() function must be called with one configuration object only',
      );
    }
    // Map those config keys to variables for the old Drupal.ajax function.
    const base = settings.base || false;
    const element = settings.element || false;
    delete settings.base;
    delete settings.element;

    // By default do not display progress for ajax calls without an element.
    if (!settings.progress && !element) {
      settings.progress = false;
    }

    const ajax = new Drupal.Ajax(base, element, settings);
    ajax.instanceIndex = Drupal.ajax.instances.length;
    Drupal.ajax.instances.push(ajax);

    return ajax;
  };

  /**
   * Contains all created Ajax objects.
   *
   * @type {Array.<Drupal.Ajax|null>}
   */
  Drupal.ajax.instances = [];

  /**
   * List all objects where the associated element is not in the DOM
   *
   * This method ignores {@link Drupal.Ajax} objects not bound to DOM elements
   * when created with {@link Drupal.ajax}.
   *
   * @return {Array.<Drupal.Ajax>}
   *   The list of expired {@link Drupal.Ajax} objects.
   */
  Drupal.ajax.expired = function () {
    return Drupal.ajax.instances.filter(
      (instance) =>
        instance &&
        instance.element !== false &&
        !document.body.contains(instance.element),
    );
  };

  /**
   * Bind Ajax functionality to links that use the 'use-ajax' class.
   *
   * @param {HTMLElement} element
   *   Element to enable Ajax functionality for.
   */
  Drupal.ajax.bindAjaxLinks = (element) => {
    // Bind Ajax behaviors to all items showing the class.
    once('ajax', '.use-ajax', element).forEach((ajaxLink) => {
      const elementSettings = {
        // Clicked links look better with the throbber than the progress bar.
        progress: { type: 'throbber' },
        dialogType: Drupal.ajax.elementData.get(ajaxLink, 'dialog-type'),
        dialog: Drupal.ajax.elementData.get(ajaxLink, 'dialog-options'),
        dialogRenderer: Drupal.ajax.elementData.get(
          ajaxLink,
          'dialog-renderer',
        ),
        base: ajaxLink.getAttribute('id') ?? undefined,
        element: ajaxLink,
      };
      const href = ajaxLink.getAttribute('href');
      /**
       * For anchor tags, these will go to the target of the anchor rather than
       * the usual location.
       */
      if (href) {
        elementSettings.url = href;
        elementSettings.event = 'click';
      }
      const httpMethod = Drupal.ajax.elementData.get(
        ajaxLink,
        'ajax-http-method',
      );
      /**
       * In case of setting custom ajax http method for link we rewrite ajax.httpMethod.
       */
      if (httpMethod) {
        elementSettings.httpMethod = httpMethod;
      }
      Drupal.ajax(elementSettings);
    });
  };

  /**
   * Settings for an Ajax object.
   *
   * @typedef {object} Drupal.Ajax~elementSettings
   *
   * @prop {string} url
   *   Target of the Ajax request.
   * @prop {?string} [event]
   *   Event bound to settings.element which will trigger the Ajax request.
   * @prop {boolean} [keypress=true]
   *   Triggers a request on keypress events.
   * @prop {?string} selector
   *   CSS selector targeting the element to bind events to or used with
   *   {@link Drupal.AjaxCommands}.
   * @prop {string} [effect='none']
   *   Effect for displaying new Ajax content: 'none' or 'fade'.
   * @prop {string|number} [speed='none']
   *   Speed with which to apply the effect.
   * @prop {string} [method]
   *   Name of the insertion method used to insert new content in the targeted
   *   element.
   * @prop {object} [progress]
   *   Settings for the display of a user-friendly loader.
   * @prop {string} [progress.type='throbber']
   *   Type of progress element, core provides `'bar'`, `'throbber'` and
   *   `'fullscreen'`.
   * @prop {string} [progress.message=Drupal.t('Processing...')]
   *   Custom message to be used with the bar indicator.
   * @prop {object} [submit]
   *   Extra data to be sent with the Ajax request.
   * @prop {boolean} [submit.js=true]
   *   Allows the PHP side to know this comes from an Ajax request.
   * @prop {object} [dialog]
   *   Options for {@link Drupal.dialog}.
   * @prop {string} [dialogType]
   *   One of `'modal'` or `'dialog'`.
   * @prop {string} [prevent]
   *   List of events on which to stop default action and stop propagation.
   */

  /**
   * Ajax constructor.
   *
   * The Ajax request returns an array of commands encoded in JSON, which is
   * then executed to make any changes that are necessary to the page.
   *
   * Drupal uses this file to enhance form elements with `#ajax['url']` and
   * `#ajax['wrapper']` properties. If set, this file will automatically be
   * included to provide Ajax capabilities.
   *
   * @constructor
   *
   * @param {string} [base]
   *   Base parameter of {@link Drupal.Ajax} constructor
   * @param {HTMLElement} [element]
   *   Element parameter of {@link Drupal.Ajax} constructor, element on which
   *   event listeners will be bound.
   * @param {Drupal.Ajax~elementSettings} elementSettings
   *   Settings for this Ajax object.
   */
  Drupal.Ajax = function (base, element, elementSettings) {
    const defaults = {
      httpMethod: 'POST',
      event: element ? 'mousedown' : null,
      keypress: true,
      selector: base ? `#${base}` : null,
      effect: 'none',
      speed: 'none',
      method: 'replaceWith',
      progress: {
        type: 'throbber',
        message: Drupal.t('Processing...'),
      },
      submit: {
        js: true,
      },
    };

    // Retain defined inherited settings and shallow object assignments.
    for (const settings of [defaults, elementSettings]) {
      for (const name in settings) {
        const value = settings[name];
        if (name !== '__proto__' && value !== undefined && value !== this) {
          this[name] = value;
        }
      }
    }

    /**
     * @type {Drupal.AjaxCommands}
     */
    this.commands = new Drupal.AjaxCommands();

    /**
     * @type {boolean|number}
     */
    this.instanceIndex = false;

    // @todo Remove this after refactoring the PHP code to:
    //   - Call this 'selector'.
    //   - Include the '#' for ID-based selectors.
    //   - Support non-ID-based selectors.
    if (this.wrapper) {
      /**
       * @type {string}
       */
      this.wrapper = `#${this.wrapper}`;
    }

    /**
     * @type {HTMLElement}
     */
    this.element = element;

    /**
     * The last focused element right before processing ajax response.
     *
     * @type {string|null}
     */
    this.preCommandsFocusedElementSelector = null;

    /**
     * @type {Drupal.Ajax~elementSettings}
     */
    this.elementSettings = elementSettings;

    // Without a form, the request is sent directly, allowing us to bind Ajax
    // to links as well.
    if (this.element?.form) {
      /**
       * The form submitted with the request.
       *
       * @type {HTMLFormElement}
       */
      this.form = this.element.form;
    }

    // If no Ajax callback URL was given, use the link href or form action.
    if (!this.url) {
      if (this.element?.tagName === 'A') {
        this.url = this.element.getAttribute('href') ?? undefined;
      } else if (this.form) {
        this.url = this.form.getAttribute('action') ?? undefined;
      }
    }

    // Replacing 'nojs' with 'ajax' in the URL allows for an easy method to let
    // the server detect when it needs to degrade gracefully.
    // There are four scenarios to check for:
    // 1. /nojs/
    // 2. /nojs$ - The end of a URL string.
    // 3. /nojs? - Followed by a query (e.g. path/nojs?destination=foobar).
    // 4. /nojs# - Followed by a fragment (e.g.: path/nojs#my-fragment).
    const originalUrl = this.url;

    /**
     * Processed Ajax URL.
     *
     * @type {string}
     */
    this.url = this.url.replace(/\/nojs(\/|$|\?|#)/, '/ajax$1');
    // If the 'nojs' version of the URL is trusted, also trust the 'ajax'
    // version.
    if (drupalSettings.ajaxTrustedUrl.hasOwnProperty(originalUrl)) {
      drupalSettings.ajaxTrustedUrl[this.url] = true;
    }

    // Set the options for the ajaxSubmit function.
    // The 'this' variable will not persist inside of the options object.
    const ajax = this;

    /**
     * Options for the Ajax request.
     *
     * @name Drupal.Ajax#options
     *
     * @type {object}
     *
     * @prop {string} url
     *   Ajax URL to be called.
     * @prop {object} data
     *   Ajax payload.
     * @prop {function} beforeSerialize
     *   Implement the beforeSerialize function to call
     *   {@link Drupal.Ajax#beforeSerialize}.
     * @prop {function} beforeSubmit
     *   Implement the beforeSubmit function to call
     *   {@link Drupal.Ajax#beforeSubmit}.
     * @prop {function} beforeSend
     *   Implement the beforeSend function to call
     *   {@link Drupal.Ajax#beforeSend}.
     * @prop {function} success
     *   Implement the success callback to call
     *   {@link Drupal.Ajax#success}.
     * @prop {function} complete
     *   Implement the complete callback to clean up ajax state and trigger an
     *   error if needed.
     * @prop {string} dataType='json'
     *   Type of the response expected.
     * @prop {string} type='POST'
     *   HTTP method to use for the Ajax request.
     */
    ajax.options = {
      url: ajax.url,
      data: ajax.submit,
      isInProgress() {
        return ajax.ajaxing;
      },
      beforeSerialize(elementSettings, options) {
        return ajax.beforeSerialize(elementSettings, options);
      },
      beforeSubmit(formValues, elementSettings, options) {
        ajax.ajaxing = true;
        ajax.preCommandsFocusedElementSelector = null;
        return ajax.beforeSubmit(formValues, elementSettings, options);
      },
      beforeSend(xmlhttprequest, options) {
        ajax.ajaxing = true;
        const wasDisabled = ajax.element?.disabled;
        startedRequests.add(ajax);
        document.dispatchEvent(
          new CustomEvent('drupal:ajax-before-send', {
            detail: { ajax },
          }),
        );
        try {
          return ajax.beforeSend(xmlhttprequest, options);
        } finally {
          // Remember whether this attempt disabled the element.
          if (!wasDisabled && ajax.element?.disabled) {
            preparedRequests.add(ajax);
          }
        }
      },
      success(response, status, xmlhttprequest) {
        receivedRequests.add(xmlhttprequest);
        return ajax.receiveResponse(response, status, xmlhttprequest);
      },
      error() {
        ajax.ajaxing = false;
      },
      complete(xmlhttprequest, status) {
        if (status === 'error' || status === 'parsererror') {
          return ajax.error(xmlhttprequest, ajax.url);
        }
        // An abort, with any status text, a timeout, or a success whose
        // default callback did not run because an earlier one threw.
        if (
          !['success', 'notmodified', 'nocontent'].includes(status) ||
          !receivedRequests.has(xmlhttprequest)
        ) {
          restoreElement(ajax);
        }
      },
      dataType: 'json',
      jsonp: false,
      method: ajax.httpMethod,
    };

    if (elementSettings.dialog) {
      ajax.options.data.dialogOptions = elementSettings.dialog;
    }

    // Ensure that we have a valid URL by adding ? when no query parameter is
    // yet available, otherwise append using &.
    if (!ajax.options.url.includes('?')) {
      ajax.options.url += '?';
    } else {
      ajax.options.url += '&';
    }
    // If this element has a dialog type use if for the wrapper if not use 'ajax'.
    let wrapper = `drupal_${elementSettings.dialogType || 'ajax'}`;
    if (elementSettings.dialogRenderer) {
      wrapper += `.${elementSettings.dialogRenderer}`;
    }
    ajax.options.url += `${Drupal.ajax.WRAPPER_FORMAT}=${wrapper}`;

    const removers = new Set();
    const bind = (names, operation) => {
      eventNames(names).forEach((name) => {
        const target = ajax.element;
        const listener = (event) => {
          if (operation(target, event) === false) {
            event.preventDefault();
            event.stopPropagation();
          }
        };
        target.addEventListener(name, listener);
        removers.add(() => target.removeEventListener(name, listener));
      });
    };
    if (ajax.element) {
      bind(elementSettings.event, (target, event) => {
        if (
          !Object.hasOwn(drupalSettings.ajaxTrustedUrl, ajax.url) &&
          !Drupal.url.isLocal(ajax.url)
        ) {
          throw new Error(
            Drupal.t('The callback URL is not local and not trusted: !url', {
              '!url': ajax.url,
            }),
          );
        }
        return ajax.eventResponse(target, event);
      });
      if (elementSettings.keypress) {
        bind('keypress', (target, event) =>
          ajax.keypressResponse(target, event),
        );
      }
      bind(elementSettings.prevent, (target, event) => {
        event.preventDefault();
        event.stopPropagation();
        return false;
      });
      ajax.removeEventBindings = () => removers.forEach((remove) => remove());
    }
  };

  /**
   * URL query attribute to indicate the wrapper used to render a request.
   *
   * The wrapper format determines how the HTML is wrapped, for example in a
   * modal dialog.
   *
   * @const {string}
   *
   * @default
   */
  Drupal.ajax.WRAPPER_FORMAT = '_wrapper_format';

  /**
   * Request parameter to indicate that a request is a Drupal Ajax request.
   *
   * @const {string}
   *
   * @default
   */
  Drupal.Ajax.AJAX_REQUEST_PARAMETER = '_drupal_ajax';

  /**
   * Execute the ajax request.
   *
   * Allows developers to execute an Ajax request manually without specifying
   * an event to respond to.
   *
   * @return {object}
   *   The request handle, with `network` and `commands` promises and an
   *   `abort()` method. If pre-serialization fails, the handle is rejected.
   */
  Drupal.Ajax.prototype.execute = function () {
    // Do not perform another ajax command if one is already in progress.
    if (this.ajaxing) {
      return;
    }

    preparedRequests.delete(this);
    try {
      this.beforeSerialize(this.element, this.options);
      const request = sendRequest(this.options, () =>
        restoreElement(this, true, true),
      );
      // Return the request handle so that external code can observe it.
      return request;
    } catch (e) {
      // Unset the ajax.ajaxing flag here because it won't be unset during
      // the complete response.
      this.ajaxing = false;
      window.alert(
        `An error occurred while attempting to process ${this.options.url}: ${e.message}`,
      );
      // For consistency, return a rejected handle so that calling code can
      // take appropriate action.
      return rejectedRequest(e);
    }
  };

  /**
   * Handle a key press.
   *
   * The Ajax object will, if instructed, bind to a key press response. This
   * will test to see if the key press is valid to trigger this event and
   * if it is, trigger it for us and prevent other keypresses from triggering.
   * In this case we're handling RETURN and SPACE BAR keypresses (event codes 13
   * and 32. RETURN is often used to submit a form when in a textfield, and
   * SPACE is often used to activate an element without submitting.
   *
   * @param {HTMLElement} element
   *   Element the event was triggered on.
   * @param {Event} event
   *   Triggered event.
   */
  Drupal.Ajax.prototype.keypressResponse = function (element, event) {
    // Create a synonym for this to reduce code confusion.
    const ajax = this;

    // Detect enter key and space bar and allow the standard response for them,
    // except for form elements of type 'text', 'tel', 'number' and 'textarea',
    // where the space bar activation causes inappropriate activation if
    // #ajax['keypress'] is TRUE. On a text-type widget a space should always
    // be a space.
    const { key } = event;
    if (
      key === 'Enter' ||
      (key === ' ' &&
        element.type !== 'text' &&
        element.type !== 'textarea' &&
        element.type !== 'tel' &&
        element.type !== 'number')
    ) {
      event.preventDefault();
      event.stopPropagation();
      const [name] = eventNames(ajax.elementSettings.event);
      if (name) {
        const EventType =
          /^(?:click|dblclick|auxclick|contextmenu|mouse(?:down|up|move|over|out|enter|leave))$/.test(
            name,
          )
            ? MouseEvent
            : Event;
        element.dispatchEvent(
          new EventType(name, {
            bubbles: true,
            cancelable: true,
          }),
        );
      }
    }
  };

  /**
   * Handle an event that triggers an Ajax response.
   *
   * When an event that triggers an Ajax response happens, this method will
   * perform the actual Ajax call. It is bound to the event using
   * bind() in the constructor, and it uses the options specified on the
   * Ajax object.
   *
   * @param {HTMLElement} element
   *   Element the event was triggered on.
   * @param {Event} event
   *   Triggered event.
   */
  Drupal.Ajax.prototype.eventResponse = function (element, event) {
    event.preventDefault();
    event.stopPropagation();

    // Create a synonym for this to reduce code confusion.
    const ajax = this;
    const submitter = event.submitter;

    // Do not perform another Ajax command if one is already in progress.
    if (ajax.ajaxing) {
      return;
    }

    preparedRequests.delete(ajax);
    try {
      if (ajax.form) {
        if (submitter?.form === ajax.form) {
          ajax.form.clk = submitter;
        }
        // If setClick is set, we must set this to ensure that the button's
        // value is passed.
        if (ajax.setClick) {
          // Mark the clicked button. 'form.clk' is a special variable for
          // ajaxSubmit that tells the system which element got clicked to
          // trigger the submit. Without it there would be no 'op' or
          // equivalent.
          element.form.clk = element;
        }

        // A veto or cancellation undoes what this attempt changed, so it can
        // be retried.
        submitForm(ajax.form, ajax.options, (serialized) =>
          restoreElement(ajax, serialized, true),
        );
      } else {
        ajax.beforeSerialize(ajax.element, ajax.options);
        sendRequest(ajax.options, () => restoreElement(ajax, true, true));
      }
    } catch (e) {
      // Unset the ajax.ajaxing flag here because it won't be unset during
      // the complete response.
      ajax.ajaxing = false;
      window.alert(
        `An error occurred while attempting to process ${ajax.options.url}: ${e.message}`,
      );
    }
  };

  /**
   * Handler for the form serialization.
   *
   * Runs before the beforeSend() handler (see below), and unlike that one, runs
   * before field data is collected.
   *
   * @param {object} [element]
   *   Ajax object's `elementSettings`.
   * @param {object} options
   *   Ajax request options.
   */
  Drupal.Ajax.prototype.beforeSerialize = function (element, options) {
    // Allow detaching behaviors to update field values before collecting them.
    // This is only needed when field values are added to the POST data, so only
    // when there is a form. Explicitly check this.form.
    if (this.form && document.body.contains(this.form)) {
      const settings = this.settings || drupalSettings;
      Drupal.detachBehaviors(this.form, settings, 'serialize');
    }

    // Inform Drupal that this is an AJAX request.
    options.data[Drupal.Ajax.AJAX_REQUEST_PARAMETER] = 1;

    // Allow Drupal to return new JavaScript and CSS files to load without
    // returning the ones already loaded.
    // @see \Drupal\Core\StackMiddleWare\AjaxPageState
    // @see \Drupal\Core\Theme\AjaxBasePageNegotiator
    // @see \Drupal\Core\Asset\LibraryDependencyResolverInterface::getMinimalRepresentativeSubset()
    // @see system_js_settings_alter()
    const pageState = drupalSettings.ajaxPageState;
    options.data['ajax_page_state[theme]'] = pageState.theme;
    options.data['ajax_page_state[theme_token]'] = pageState.theme_token;
    options.data['ajax_page_state[libraries]'] = pageState.libraries;
  };

  /**
   * Modify form values prior to form submission.
   *
   * @param {Array.<object>} formValues
   *   Processed form values.
   * @param {HTMLFormElement} element
   *   The form element.
   * @param {object} options
   *   Ajax request options.
   */
  Drupal.Ajax.prototype.beforeSubmit = function (formValues, element, options) {
    // This function is left empty to make it simple to override for modules
    // that wish to add functionality here.
  };

  /**
   * Prepare the Ajax request before it is sent.
   *
   * @param {XMLHttpRequest} xmlhttprequest
   *   Native Ajax object.
   * @param {object} options
   *   Ajax request options.
   */
  Drupal.Ajax.prototype.beforeSend = function () {
    // Disable the element that received the change to prevent user interface
    // interaction while the Ajax request is in progress. ajax.ajaxing prevents
    // the element from triggering a new request, but does not prevent the user
    // from changing its value.
    if (this.element) {
      this.element.disabled = true;
    }

    if (!this.progress || !this.progress.type) {
      return;
    }

    // Insert progress indicator.
    const progressIndicatorMethod = `setProgressIndicator${this.progress.type
      .slice(0, 1)
      .toUpperCase()}${this.progress.type.slice(1).toLowerCase()}`;
    if (
      progressIndicatorMethod in this &&
      typeof this[progressIndicatorMethod] === 'function'
    ) {
      this[progressIndicatorMethod].call(this);
    }
  };

  /**
   * An animated progress throbber and container element for AJAX operations.
   *
   * @param {string} [message]
   *   (optional) The message shown on the UI.
   * @return {string}
   *   The HTML markup for the throbber.
   */
  Drupal.theme.ajaxProgressThrobber = (message) => {
    // Build markup without adding extra white space since it affects rendering.
    const messageMarkup =
      typeof message === 'string'
        ? Drupal.theme('ajaxProgressMessage', message)
        : '';
    const throbber = '<div class="throbber">&nbsp;</div>';

    return `<div class="ajax-progress ajax-progress-throbber">${throbber}${messageMarkup}</div>`;
  };

  /**
   * An animated progress throbber and container element for AJAX operations.
   *
   * @return {string}
   *   The HTML markup for the throbber.
   */
  Drupal.theme.ajaxProgressIndicatorFullscreen = () =>
    '<div class="ajax-progress ajax-progress-fullscreen">&nbsp;</div>';

  /**
   * Formats text accompanying the AJAX progress throbber.
   *
   * @param {string} message
   *   The message shown on the UI.
   * @return {string}
   *   The HTML markup for the throbber.
   */
  Drupal.theme.ajaxProgressMessage = (message) =>
    `<div class="message">${message}</div>`;

  /**
   * Provide a wrapper for the AJAX progress bar element.
   *
   * @param {HTMLElement} element
   *   Progress bar element.
   * @return {HTMLElement}
   *   The progress bar wrapper.
   */
  const wrapProgressBar = (element) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'ajax-progress ajax-progress-bar';
    wrapper.append(element);
    return wrapper;
  };

  Drupal.theme.ajaxProgressBar = wrapProgressBar;

  function progressElement(content) {
    if (typeof content !== 'string') {
      return content;
    }
    const template = document.createElement('template');
    template.innerHTML = content;
    return template.content.firstElementChild;
  }

  /**
   * Sets the progress bar progress indicator.
   */
  Drupal.Ajax.prototype.setProgressIndicatorBar = function () {
    const progressBar = new Drupal.ProgressBar(
      `ajax-progress-${this.element?.id}`,
      undefined,
      this.progress.method,
    );
    if (this.progress.message) {
      progressBar.setProgress(-1, this.progress.message);
    }
    if (this.progress.url) {
      progressBar.startMonitoring(
        this.progress.url,
        this.progress.interval || 1500,
      );
    }
    this.progress.elementNode = progressElement(
      Drupal.theme('ajaxProgressBar', progressBar.elementNode),
    );
    this.progress.object = progressBar;
    if (this.element) {
      this.element.after(this.progress.elementNode);
    }
  };

  /**
   * Sets the throbber progress indicator.
   */
  Drupal.Ajax.prototype.setProgressIndicatorThrobber = function () {
    if (!this.element) {
      return;
    }
    this.progress.elementNode = progressElement(
      Drupal.theme('ajaxProgressThrobber', this.progress.message),
    );
    const container = this.element.closest('[data-drupal-ajax-container]');
    (container ?? this.element).after(this.progress.elementNode);
  };

  /**
   * Sets the fullscreen progress indicator.
   */
  Drupal.Ajax.prototype.setProgressIndicatorFullscreen = function () {
    this.progress.elementNode = progressElement(
      Drupal.theme('ajaxProgressIndicatorFullscreen'),
    );
    document.body.append(this.progress.elementNode);
  };

  /**
   * Optional callback called with the response and status after the successful
   * command queue. Return a promise to delay drupal:ajax-complete until it
   * settles.
   *
   * @type {function(Array, string): (void|Promise)}
   */
  Drupal.Ajax.prototype.afterSuccess = null;

  /**
   * Verifies an Ajax response and waits for its commands to complete.
   *
   * @param {Array|string|null} response
   *   The response data.
   * @param {string} status
   *   The request outcome.
   * @param {XMLHttpRequest} xmlhttprequest
   *   The native request.
   *
   * @return {Promise|undefined}
   *   Completion of the response commands, if the response passed verification.
   *
   * @internal
   */
  Drupal.Ajax.prototype.receiveResponse = function (
    response,
    status,
    xmlhttprequest,
  ) {
    this.preCommandsFocusedElementSelector =
      document.activeElement.getAttribute('data-drupal-selector');

    // Sanity check for browser support (object expected).
    if (typeof response === 'string') {
      try {
        response = JSON.parse(response);
      } catch (e) {
        return this.error(xmlhttprequest, this.url);
      }
    }

    // Prior to invoking the response's commands, verify that they can be
    // trusted by checking for a response header. See
    // \Drupal\Core\EventSubscriber\AjaxResponseSubscriber for details.
    // - Empty responses are harmless so can bypass verification. This
    //   avoids an alert message for server-generated no-op responses that
    //   skip Ajax rendering.
    // - Ajax objects with trusted URLs (e.g., ones defined server-side via
    //   #ajax) can bypass header verification.
    if (
      response !== null &&
      !drupalSettings.ajaxTrustedUrl.hasOwnProperty(this.url)
    ) {
      if (xmlhttprequest.getResponseHeader('X-Drupal-Ajax-Token') !== '1') {
        const customMessage = Drupal.t(
          'The response failed verification so will not be processed.',
        );
        return this.error(xmlhttprequest, this.url, customMessage);
      }
    }

    // A success override may return a value or a promise. The
    // request is finished when it settles, even if a command failed.
    const complete = () => {
      this.ajaxing = false;
      finishRequest(this);
    };
    let result;
    try {
      result = this.success(response, status);
    } catch (error) {
      complete();
      throw error;
    }
    const commands = Promise.resolve(result);
    const completed = this.afterSuccess
      ? commands.then(() => this.afterSuccess(response, status))
      : commands;
    return completed.then(
      () => complete(),
      (error) => {
        complete();
        throw error;
      },
    );
  };

  /**
   * Helper method to make sure commands are executed in sequence.
   *
   * @param {Array.<Drupal.AjaxCommands~commandDefinition>} response
   *   Drupal Ajax response.
   * @param {number} status
   *   XMLHttpRequest status.
   *
   * @return {Promise}
   *  The promise that will resolve once all commands have finished executing.
   */
  Drupal.Ajax.prototype.commandExecutionQueue = function (response, status) {
    const ajaxCommands = this.commands;
    return Object.keys(response || {}).reduce(
      // Add all commands to a single execution queue.
      (executionQueue, key) =>
        executionQueue.then(() => {
          const { command } = response[key];
          if (command && ajaxCommands[command]) {
            // When a command returns a promise, the remaining commands will not
            // execute until that promise has been fulfilled. This is typically
            // used to ensure JavaScript files added via the 'add_js' command
            // have loaded before subsequent commands execute.
            return ajaxCommands[command](this, response[key], status);
          }
        }),
      Promise.resolve(),
    );
  };

  /**
   * Handler for the form redirection completion.
   *
   * @param {Array.<Drupal.AjaxCommands~commandDefinition>} response
   *   Drupal Ajax response.
   * @param {number} status
   *   XMLHttpRequest status.
   *
   * @return {Promise}
   * The promise that will resolve once all commands have finished executing.
   */
  Drupal.Ajax.prototype.success = function (response, status) {
    // Remove the progress element.
    this.progress?.elementNode?.remove();
    if (this.progress?.object) {
      this.progress.object.stopMonitoring();
    }
    if (this.element) {
      this.element.disabled = false;
    }

    // Save the element and its ancestors, starting with the nearest target.
    const elementParents = [];
    if (this.element) {
      elementParents.push(this.element);
      for (
        let parent = this.element.parentElement;
        parent;
        parent = parent.parentElement
      ) {
        if (parent.hasAttribute('data-drupal-selector')) {
          elementParents.push(parent);
        }
      }
    }

    // Track if any command is altering the focus so we can avoid changing the
    // focus set by the Ajax command.
    const focusChanged = Object.keys(response || {}).some((key) => {
      const { command } = response[key];
      return (
        command === 'focus' ||
        command === 'focusFirst' ||
        command === 'openDialog'
      );
    });

    const logError = (error) =>
      // eslint-disable-next-line no-console
      console.error(
        Drupal.t(
          'An error occurred during the execution of the Ajax response: !error',
          {
            '!error': error,
          },
        ),
      );

    return (
      this.commandExecutionQueue(response, status)
        // A failed command is logged, and the cleanup below still runs.
        .catch(logError)
        // If the focus hasn't been changed by the AJAX commands, try to refocus
        // the triggering element or one of its parents if that element does not
        // exist anymore.
        .then(() => {
          if (!focusChanged) {
            let target = false;
            if (this.element) {
              if (
                Drupal.ajax.elementData.get(this.element, 'refocus-blur') &&
                this.preCommandsFocusedElementSelector
              ) {
                target = document.querySelector(
                  `[data-drupal-selector="${CSS.escape(this.preCommandsFocusedElementSelector)}"]`,
                );
              }
              if (
                !target &&
                !Drupal.ajax.elementData.get(this.element, 'disable-refocus')
              ) {
                for (let n = 0; !target && n < elementParents.length; n += 1) {
                  target = document.querySelector(
                    `[data-drupal-selector="${CSS.escape(
                      elementParents[n].getAttribute('data-drupal-selector'),
                    )}"]`,
                  );
                }
              }
            }
            if (target) {
              target.focus();
            }
          }
          // Reattach behaviors, if they were detached in beforeSerialize(). The
          // attachBehaviors() called on the new content from processing the
          // response commands is not sufficient, because behaviors from the
          // entire form need to be reattached.
          if (this.form && document.body.contains(this.form)) {
            const settings = this.settings || drupalSettings;
            Drupal.attachBehaviors(this.form, settings);
          }
          // Remove any response-specific settings so they don't get used on the
          // next call by mistake.
          this.settings = null;
        })
        .catch(logError)
    );
  };

  /**
   * Handler for the form redirection error.
   *
   * @param {object} xmlhttprequest
   *   Native XMLHttpRequest object.
   * @param {string} uri
   *   Ajax Request URI.
   * @param {string} [customMessage]
   *   Extra message to print with the Ajax error.
   */
  Drupal.Ajax.prototype.error = function (xmlhttprequest, uri, customMessage) {
    restoreElement(this);
    // Undo hide.
    commandTargets(this.wrapper).forEach(showInsertedElement);
    throw new Drupal.AjaxError(xmlhttprequest, uri, customMessage);
  };

  /**
   * Wraps multiple visible roots when the response uses an effect.
   *
   * @param {Node[]} nodes
   *   Parsed response content.
   * @param {Drupal.Ajax} ajax
   *   The Ajax instance.
   * @param {object} response
   *   The Ajax response.
   *
   * @return {Node[]}
   *   The nodes to insert.
   */
  const wrapNewContent = (nodes, ajax, response) => {
    if (
      (response.effect || ajax.effect) === 'none' ||
      Array.from(nodes).filter(
        (node) =>
          node.nodeType !== Node.COMMENT_NODE &&
          (node.nodeType !== Node.TEXT_NODE || node.textContent.trim() !== ''),
      ).length <= 1
    ) {
      return nodes;
    }
    const wrapper = document.createElement('div');
    wrapper.append(...nodes);
    return [wrapper];
  };

  Drupal.theme.ajaxWrapperNewContent = wrapNewContent;

  function commandTargets(selector) {
    if (!selector) {
      return [];
    }
    if (/^#[\w-]+$/.test(selector)) {
      const target = document.getElementById(selector.slice(1));
      return target ? [target] : [];
    }
    return [...document.querySelectorAll(selector)];
  }

  const insertMethods = {
    html: (target, nodes) => target.replaceChildren(...nodes),
    replaceWith: (target, nodes) => target.replaceWith(...nodes),
    append: (target, nodes) => target.append(...nodes),
    prepend: (target, nodes) => target.prepend(...nodes),
    before: (target, nodes) => target.before(...nodes),
    after: (target, nodes) => target.after(...nodes),
  };

  function runInsertedScript(script) {
    if (
      !script.isConnected ||
      !/^$|^module$|\/(?:java|ecma)script/i.test(script.type || '')
    ) {
      return;
    }
    const executable = document.createElement('script');
    const { src, type, nonce, noModule } = script;
    const crossorigin = script.getAttribute('crossorigin');
    if (nonce) {
      executable.nonce = nonce;
    }
    if (crossorigin !== null) {
      executable.setAttribute('crossorigin', crossorigin);
    }
    if (src && (type || '').toLowerCase() !== 'module') {
      if (noModule) {
        return;
      }
      if (
        new URL(src).origin !== window.location.origin ||
        crossorigin !== null
      ) {
        executable.src = src;
        executable.addEventListener('load', () => executable.remove());
        executable.addEventListener('error', () => executable.remove());
        document.head.append(executable);
        return;
      }
      // Classic same-origin scripts execute in response order. Modules and
      // cross-origin scripts follow the browser's asynchronous script path.
      const request = Drupal.xhr({
        url: src,
        async: false,
        headers: { Accept: `${accepts.script}, */*; q=0.01` },
      });
      if (
        !(request.status >= 200 && request.status < 300) &&
        request.status !== 304
      ) {
        return;
      }
      executable.text = request.responseText;
    } else {
      executable.text = script.textContent;
      if (src) {
        executable.src = src;
      }
      if (type) {
        executable.type = type;
      }
      executable.noModule = noModule;
    }
    document.head.append(executable);
    executable.remove();
  }

  const defaultDisplays = new Map();

  function showInsertedElement(element) {
    if (element.style.display === 'none') {
      element.style.removeProperty('display');
    }
    if (element.isConnected && getComputedStyle(element).display === 'none') {
      const tagName = element.localName;
      if (!defaultDisplays.has(tagName)) {
        const sample = document.createElement(tagName);
        document.body.append(sample);
        const display = getComputedStyle(sample).display;
        defaultDisplays.set(tagName, display === 'none' ? 'block' : display);
        sample.remove();
      }
      element.style.display = defaultDisplays.get(tagName);
    }
  }

  function fadeIn(element, speed) {
    showInsertedElement(element);
    const duration =
      typeof speed === 'number'
        ? Math.max(0, speed)
        : ({ fast: 200, slow: 600 }[speed] ?? 400);
    if (duration) {
      Drupal.trackAnimation(
        element.animate(
          [{ opacity: 0 }, { opacity: getComputedStyle(element).opacity }],
          {
            duration,
            easing: 'ease-in-out',
          },
        ),
      );
    }
  }

  const pixelProperties =
    /^(?:border(?:-(?:top|right|bottom|left))?(?:-width)?|(?:margin|padding)(?:-(?:top|right|bottom|left))?|top|right|bottom|left|(?:(?:min|max)-)?(?:width|height))$/;

  // The pinned jQuery style hooks clamp single numeric box values.
  const nonNegativeProperties = /^(?:width|height|padding|border-width)$/;
  const cssNumber = /^([+-]?(?:\d*\.)?\d+(?:e[+-]?\d+)?)([a-z%]*)$/i;

  const elementData = new WeakMap();
  const normalizeDataKey = (key) =>
    key.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());

  /**
   * Stores Ajax command data on elements without retaining detached elements.
   *
   * @namespace
   */
  Drupal.ajax.elementData = {
    get(element, name) {
      const key = normalizeDataKey(name);
      const data = elementData.get(element);
      const value = data?.get(key)?.value;
      if (value !== undefined) {
        return value;
      }
      const attribute = element.getAttribute(
        `data-${key.replace(/[A-Z]/g, '-$&').toLowerCase()}`,
      );
      if (attribute === null) {
        return;
      }
      if (
        attribute === 'true' ||
        attribute === 'false' ||
        attribute === 'null'
      ) {
        return JSON.parse(attribute);
      }
      if (String(Number(attribute)) === attribute) {
        return Number(attribute);
      }
      if (/^(?:\{[\s\S]*\}|\[[\s\S]*\])$/.test(attribute)) {
        try {
          return JSON.parse(attribute);
        } catch (error) {
          // A malformed JSON attribute is a string value.
        }
      }
      return attribute;
    },
    set(element, name, value) {
      const data = elementData.get(element) ?? new Map();
      data.set(normalizeDataKey(name), { value });
      elementData.set(element, data);
    },
  };

  function clearElementData(element) {
    elementData.delete(element);
    element.querySelectorAll('*').forEach((node) => elementData.delete(node));
  }

  /**
   * @typedef {object} Drupal.AjaxCommands~commandDefinition
   *
   * @prop {string} command
   * @prop {string} [method]
   * @prop {string} [selector]
   * @prop {string} [data]
   * @prop {object} [settings]
   * @prop {boolean} [asterisk]
   * @prop {string} [text]
   * @prop {string} [title]
   * @prop {string} [url]
   * @prop {object} [argument]
   * @prop {string} [name]
   * @prop {string} [value]
   * @prop {string} [old]
   * @prop {string} [new]
   * @prop {boolean} [merge]
   * @prop {Array} [args]
   *
   * @see Drupal.AjaxCommands
   */

  /**
   * Provide a series of commands that the client will perform.
   *
   * @constructor
   */
  Drupal.AjaxCommands = function () {};
  Drupal.AjaxCommands.prototype = {
    /**
     * Command to insert new content into the DOM.
     *
     * @param {Drupal.Ajax} ajax
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {string} response.data
     *   The HTML to insert.
     * @param {string} [response.method]
     *   The DOM insertion method.
     * @param {string} [response.selector]
     *   An optional selector string.
     * @param {object} [response.settings]
     *   An optional array of settings that will be used.
     */
    insert(ajax, response) {
      const selector = response.selector || ajax.wrapper;
      const method = response.method || ajax.method;
      const effect = response.effect || ajax.effect;
      if (!Object.hasOwn(insertMethods, method)) {
        throw new Error(`Unsupported Ajax insertion method: ${method}`);
      }
      const targets = commandTargets(selector);
      const settings = response.settings || ajax.settings || drupalSettings;
      const template = document.createElement('template');
      template.innerHTML = response.data;
      const content = Drupal.theme(
        'ajaxWrapperNewContent',
        Array.from(template.content.childNodes),
        ajax,
        response,
      );
      const nodes = Array.from(content);
      if (method === 'html' || method === 'replaceWith') {
        // Retain the original detach context, including the missing-wrapper
        // fallback to document.
        Drupal.detachBehaviors(targets[0], settings);
      }
      targets.forEach((target, index) => {
        const children =
          index === targets.length - 1
            ? nodes
            : nodes.map((node) => node.cloneNode(true));
        let destination = target;
        if (method === 'html' || method === 'replaceWith') {
          if (method === 'replaceWith') {
            clearElementData(target);
          } else {
            Array.from(target.children).forEach(clearElementData);
          }
        } else if (
          (method === 'append' || method === 'prepend') &&
          target.localName === 'table' &&
          children[0]?.localName === 'tr'
        ) {
          destination = target.tBodies[0] ?? target;
        }
        insertMethods[method](destination, children);
      });
      const elements = nodes.filter(
        (node) => node.nodeType === Node.ELEMENT_NODE,
      );
      // Parsed scripts are inert. Run the originals once, after inserting all
      // targets, as the legacy insertion did. Response verification precedes
      // this command in receiveResponse().
      elements.forEach((element) => {
        if (element.localName === 'script') {
          runInsertedScript(element);
        }
        element.querySelectorAll('script').forEach(runInsertedScript);
      });
      const effectTargets = elements.flatMap((element) => [
        ...element.querySelectorAll('.ajax-new-content'),
      ]);
      if (effectTargets.length) {
        elements.forEach(showInsertedElement);
      }
      if (effect === 'fade') {
        (effectTargets.length ? effectTargets : elements).forEach(
          (fadeInElement) =>
            fadeIn(fadeInElement, response.speed || ajax.speed),
        );
      } else {
        effectTargets.forEach((element) => fadeIn(element, 0));
      }
      elements.forEach((element) => {
        if (element.isConnected) {
          Drupal.attachBehaviors(element, settings);
        }
      });
    },

    /**
     * Command to remove a chunk from the page.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {string} response.selector
     *   A selector string.
     * @param {object} [response.settings]
     *   An optional array of settings that will be used.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    remove(ajax, response) {
      const settings = response.settings || ajax.settings || drupalSettings;
      const targets = commandTargets(response.selector);
      targets.forEach((element) => Drupal.detachBehaviors(element, settings));
      targets.forEach((element) => {
        clearElementData(element);
        element.remove();
      });
    },

    /**
     * Command to mark a chunk changed.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The JSON response object from the Ajax request.
     * @param {string} response.selector
     *   A selector string.
     * @param {string} [response.asterisk]
     *   An optional CSS selector. If specified, an asterisk will be
     *   appended to the HTML inside the provided selector.
     * @param {number} [status]
     *   The request status.
     */
    changed(ajax, response) {
      const targets = commandTargets(response.selector);
      if (
        !targets.some((element) => element.classList.contains('ajax-changed'))
      ) {
        const markers = new Set();
        targets.forEach((element) => {
          element.classList.add('ajax-changed');
          if (response.asterisk) {
            element
              .querySelectorAll(response.asterisk)
              .forEach((marker) => markers.add(marker));
          }
        });
        markers.forEach((marker) => {
          const abbr = document.createElement('abbr');
          abbr.className = 'ajax-changed';
          abbr.title = Drupal.t('Changed');
          abbr.textContent = '*';
          marker.append(' ', abbr, ' ');
        });
      }
    },

    /**
     * Command to provide an alert.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The JSON response from the Ajax request.
     * @param {string} response.text
     *   The text that will be displayed in an alert dialog.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    alert(ajax, response, status) {
      window.alert(response.text);
    },

    /**
     * Command to provide triggers audio UAs to read the supplied text.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The JSON response from the Ajax request.
     * @param {string} [response.text]
     *   The text that will be read.
     * @param {string} [response.priority]
     *   An optional priority that will be used for the announcement.
     */
    announce(ajax, response) {
      if (response.priority) {
        Drupal.announce(response.text, response.priority);
      } else {
        Drupal.announce(response.text);
      }
    },

    /**
     * Command to set the window.location, redirecting the browser.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {string} response.url
     *   The URL to redirect to.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    redirect(ajax, response, status) {
      window.location = response.url;
    },

    /**
     * Command to set inline CSS properties.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {string} response.selector
     *   A selector string.
     * @param {object} response.argument
     *   An array of key/value pairs to set in the CSS for the selector.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    css(ajax, response) {
      const properties = Object.entries(response.argument || {});
      commandTargets(response.selector).forEach(({ style }) => {
        properties.forEach(([name, value]) => {
          if (value == null || Number.isNaN(value)) {
            return;
          }
          const property =
            name === 'cssFloat'
              ? 'float'
              : name.startsWith('--')
                ? name
                : name
                    .replace(/[A-Z]/g, '-$&')
                    .toLowerCase()
                    .replace(/^ms-/, '-ms-');
          const units =
            typeof value === 'number' && pixelProperties.test(property)
              ? 'px'
              : '';
          let cssValue = `${value}${units}`;
          if (nonNegativeProperties.test(property)) {
            const match = cssNumber.exec(cssValue);
            if (match) {
              cssValue = `${Math.max(0, Number(match[1]))}${match[2] || 'px'}`;
            }
          }
          style.setProperty(property, cssValue);
        });
      });
    },

    /**
     * Command to set the settings used for other commands in this response.
     *
     * This method will also remove expired `drupalSettings.ajax` settings.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {boolean} response.merge
     *   Determines whether the additional settings should be merged to the
     *   global settings.
     * @param {object} response.settings
     *   Contains additional settings to add to the global settings.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    settings(ajax, response, status) {
      const ajaxSettings = drupalSettings.ajax;

      // Clean up drupalSettings.ajax.
      if (ajaxSettings) {
        Drupal.ajax.expired().forEach((instance) => {
          // If the Ajax object has been created through drupalSettings.ajax
          // it will have a selector. When there is no selector the object
          // has been initialized with a special class name picked up by the
          // Ajax behavior.

          if (instance.selector) {
            const selector = instance.selector.replace('#', '');
            if (selector in ajaxSettings) {
              delete ajaxSettings[selector];
            }
          }
        });
      }

      if (response.merge) {
        extendDeep(drupalSettings, response.settings);
      } else {
        ajax.settings = response.settings;
      }
    },

    /**
     * Command to attach data to an element.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {string} response.name
     *   The name or key (in the key value pair) of the data attached to this
     *   selector.
     * @param {string} response.selector
     *   A selector string.
     * @param {string|object} response.value
     *   The value of to be attached.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    data(ajax, response) {
      commandTargets(response.selector).forEach((element) => {
        Drupal.ajax.elementData.set(element, response.name, response.value);
      });
    },

    /**
     * Command to focus the first tabbable element within a container.
     *
     * If no tabbable elements are found and the container is focusable, then
     * focus will move to that container.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {string} response.selector
     *   A query selector string of the container to focus within.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    focusFirst(ajax, response, status) {
      let focusChanged = false;
      const container = document.querySelector(response.selector);
      if (container) {
        // Find all tabbable elements within the container.
        const tabbableElements = tabbable(container);

        // Move focus to the first tabbable item found.
        if (tabbableElements.length) {
          tabbableElements[0].focus();
          focusChanged = true;
        } else if (isFocusable(container)) {
          // If no tabbable elements are found, but the container is focusable,
          // move focus to the container.
          container.focus();
          focusChanged = true;
        }
      }

      // If no items were available to receive focus, return focus to the
      // triggering element.
      if (ajax.hasOwnProperty('element') && !focusChanged) {
        ajax.element.focus();
      }
    },

    /**
     * Focuses each matched element in document order.
     */
    focus(ajax, response, status) {
      commandTargets(response.selector).forEach((element) => element.focus());
    },

    /**
     * Sets the value of each matched form control.
     */
    setValue(ajax, response, status) {
      commandTargets(response.selector).forEach((element) => {
        element.value = response.value;
      });
    },

    /**
     * Dispatches a bubbling, cancelable event on each matched element.
     */
    dispatchEvent(ajax, response, status) {
      commandTargets(response.selector).forEach((element) =>
        element.dispatchEvent(
          new Event(response.event, { bubbles: true, cancelable: true }),
        ),
      );
    },

    /**
     * Applies a class state on each matched element.
     */
    toggleClass(ajax, response, status) {
      commandTargets(response.selector).forEach((element) =>
        element.classList.toggle(response.className, response.enabled),
      );
    },

    /**
     * Sets an attribute, or removes it when the value is null.
     */
    setAttribute(ajax, response, status) {
      commandTargets(response.selector).forEach((element) => {
        if (response.value === null) {
          element.removeAttribute(response.name);
        } else {
          element.setAttribute(response.name, response.value);
        }
      });
    },

    /**
     * Command to restripe a table.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {string} response.selector
     *   A selector string.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    restripe(ajax, response) {
      // Include rows with or without a tbody. Skip nested tables.
      const selector = ':scope > tbody > tr, :scope > tr';
      const rows = [
        ...new Set(
          commandTargets(response.selector).flatMap((table) => [
            ...table.querySelectorAll(selector),
          ]),
        ),
      ].filter(Drupal.elementIsVisible);
      rows.forEach((row) => row.classList.remove('odd', 'even'));

      // Row indices are zero-based, but the first visible row is odd.
      for (const [start, className] of [
        [0, 'odd'],
        [1, 'even'],
      ]) {
        for (let index = start; index < rows.length; index += 2) {
          rows[index].classList.add(className);
        }
      }
    },

    /**
     * Command to update a form's build ID.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {string} response.old
     *   The old form build ID.
     * @param {string} response.new
     *   The new form build ID.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    update_build_id(ajax, response, status) {
      document
        .querySelectorAll(
          `input[name="form_build_id"][value="${response.old}"]`,
        )
        .forEach((item) => {
          item.value = response.new;
        });
    },

    /**
     * Command to add css.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {object[]} response.data
     *   An array of styles to be added.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    add_css(ajax, response, status) {
      const allUniqueBundleIds = response.data.map(function (style) {
        const uniqueBundleId = style.href;
        // Force file to load as a CSS stylesheet using 'css!' flag.
        if (!loadjs.isDefined(uniqueBundleId)) {
          loadjs(`css!${style.href}`, uniqueBundleId, {
            before(path, styleEl) {
              // This allows all attributes to be added, like media.
              Object.keys(style).forEach((attributeKey) => {
                styleEl.setAttribute(attributeKey, style[attributeKey]);
              });
            },
          });
        }
        return uniqueBundleId;
      });
      // Returns the promise so that the next AJAX command waits on the
      // completion of this one to execute, ensuring the CSS is loaded before
      // executing.
      return new Promise((resolve, reject) => {
        loadjs.ready(allUniqueBundleIds, {
          success() {
            // All CSS files were loaded. Resolve the promise and let the
            // remaining commands execute.
            resolve();
          },
          error(depsNotFound) {
            const message = Drupal.t(
              `The following files could not be loaded: @dependencies`,
              { '@dependencies': depsNotFound.join(', ') },
            );
            reject(message);
          },
        });
      });
    },

    /**
     * Command to add a message to the message area.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {string} response.messageWrapperQuerySelector
     *   The zone where to add the message. If null, the default will be used.
     * @param {string} response.message
     *   The message text.
     * @param {string} response.messageOptions
     *   The options argument for Drupal.Message().add().
     * @param {boolean} response.clearPrevious
     *   If true, clear previous messages.
     */
    message(ajax, response) {
      const messages = new Drupal.Message(
        document.querySelector(response.messageWrapperQuerySelector),
      );
      if (response.clearPrevious) {
        messages.clear();
      }
      messages.add(response.message, response.messageOptions);
    },

    /**
     * Command to add JS.
     *
     * @param {Drupal.Ajax} [ajax]
     *   {@link Drupal.Ajax} object created by {@link Drupal.ajax}.
     * @param {object} response
     *   The response from the Ajax request.
     * @param {Array} response.data
     *   An array of objects of script attributes.
     * @param {number} [status]
     *   The XMLHttpRequest status.
     */
    add_js(ajax, response, status) {
      const parentEl = document.querySelector(response.selector || 'body');
      const settings = ajax.settings || drupalSettings;
      const allUniqueBundleIds = response.data.map((script) => {
        const uniqueBundleId = script.src;
        if (!loadjs.isDefined(uniqueBundleId)) {
          loadjs(script.src, uniqueBundleId, {
            // The default loadjs behavior is to load script with async, in Drupal
            // we need to explicitly tell scripts to load async, this is set in
            // the before callback below if necessary.
            async: false,
            before(path, scriptEl) {
              // This allows all attributes to be added, like defer, async and
              // crossorigin.
              Object.keys(script).forEach((attributeKey) => {
                scriptEl.setAttribute(attributeKey, script[attributeKey]);
              });

              // By default, loadjs appends the script to the head. When scripts
              // are loaded via AJAX, their location has no impact on
              // functionality. But, since non-AJAX loaded scripts can choose
              // their parent element, we provide that option here for the sake of
              // consistency.
              parentEl.appendChild(scriptEl);
              // Return false to bypass loadjs' default DOM insertion mechanism.
              return false;
            },
          });
        }
        return uniqueBundleId;
      });
      // Returns the promise so that the next AJAX command waits on the
      // completion of this one to execute, ensuring the JS is loaded before
      // executing.
      return new Promise((resolve, reject) => {
        loadjs.ready(allUniqueBundleIds, {
          success() {
            Drupal.attachBehaviors(parentEl, settings);
            // All JS files were loaded and new and old behaviors have
            // been attached. Resolve the promise and let the remaining
            // commands execute.
            resolve();
          },
          error(depsNotFound) {
            const message = Drupal.t(
              `The following files could not be loaded: @dependencies`,
              { '@dependencies': depsNotFound.join(', ') },
            );
            reject(message);
          },
        });
      });
    },

    /**
     * Command to scroll the page to an html element.
     *
     * @param {Drupal.Ajax} [ajax]
     *   A {@link Drupal.ajax} object.
     * @param {object} response
     *   Ajax response.
     * @param {string} response.selector
     *   Selector to use.
     */
    scrollTop(ajax, response) {
      document.querySelector(response.selector)?.scrollIntoView();
    },
  };
})(window, Drupal, drupalSettings, loadjs, window.tabbable);
