<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

/**
 * Counts jQuery calls on mixed pages.
 *
 * Plan section 6.4 instruments jQuery dispatch, $.fn.init and selectors. A
 * mixed page with no legacy use must make none of these calls from core code.
 */
trait JqueryCallCounterTrait {

  /**
   * Returns JavaScript that defines window.jqueryCalls.
   *
   * Call jqueryCalls.start() before the measured code and
   * jqueryCalls.stop() after it. stop() restores the instance and returns
   * the counts keyed by dispatch, init, find and data.
   *
   * @return string
   *   A JavaScript statement.
   */
  protected function jqueryCallCounter(): string {
    return <<<'JS'
      window.jqueryCalls = {
        start(instance = window.jQuery) {
          const counts = { dispatch: 0, init: 0, find: 0, data: 0 };
          const originals = {
            dispatch: instance.event.dispatch,
            init: instance.fn.init,
            find: instance.find,
            data: instance._data,
          };
          instance.event.dispatch = function (...args) {
            counts.dispatch += 1;
            return originals.dispatch.apply(this, args);
          };
          const init = function (...args) {
            counts.init += 1;
            return new originals.init(...args);
          };
          init.prototype = originals.init.prototype;
          instance.fn.init = init;
          const find = function (...args) {
            counts.find += 1;
            return originals.find.apply(this, args);
          };
          Object.assign(find, originals.find);
          instance.find = find;
          instance._data = function (...args) {
            counts.data += 1;
            return originals.data.apply(this, args);
          };
          this.stop = () => {
            instance.event.dispatch = originals.dispatch;
            instance.fn.init = originals.init;
            instance.find = originals.find;
            instance._data = originals.data;
            return counts;
          };
        },
        stop() {
          return null;
        },
      };
      JS;
  }

  /**
   * Asserts that counts from jqueryCalls.stop() are all zero.
   *
   * @param mixed $counts
   *   The counts returned by the browser.
   * @param string $message
   *   The failure message.
   */
  protected function assertNoJqueryCalls(mixed $counts, string $message = ''): void {
    $this->assertIsArray($counts, $message);
    ksort($counts);
    $this->assertSame(['data' => 0, 'dispatch' => 0, 'find' => 0, 'init' => 0], $counts, $message);
  }

}
