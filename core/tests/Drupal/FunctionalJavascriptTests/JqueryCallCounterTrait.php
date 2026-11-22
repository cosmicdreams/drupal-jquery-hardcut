<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

/**
 * Counts jQuery calls on mixed pages.
 *
 * Plan section 6.4 instruments jQuery dispatch, $.fn.init and selectors. A
 * mixed page with no legacy use must make none of these calls from core code.
 * The counter also covers event construction and the data API.
 */
trait JqueryCallCounterTrait {

  /**
   * Returns JavaScript that defines window.jqueryCalls.
   *
   * Call jqueryCalls.start() before the measured code and
   * jqueryCalls.stop() after it. stop() restores each member that nothing
   * wrapped since, and returns the counts keyed by member name. A member a
   * later script wrapped keeps the counter, which stops counting.
   *
   * @return string
   *   A JavaScript statement.
   */
  protected function jqueryCallCounter(): string {
    return <<<'JS'
      window.jqueryCalls = {
        start(instance = window.jQuery) {
          const counts = {};
          const restores = [];
          let active = true;
          const count = (owner, name, key, construct = false) => {
            const original = owner[name];
            counts[key] = 0;
            const wrapper = function (...args) {
              if (active) {
                counts[key] += 1;
              }
              return construct
                ? new original(...args)
                : original.apply(this, args);
            };
            Object.assign(wrapper, original);
            wrapper.prototype = original.prototype;
            owner[name] = wrapper;
            restores.push(() => {
              if (owner[name] === wrapper) {
                owner[name] = original;
              }
            });
          };
          count(instance.event, 'dispatch', 'dispatch');
          count(instance.fn, 'init', 'init', true);
          count(instance, 'find', 'find');
          count(instance, '_data', '_data');
          count(instance, 'Event', 'Event', true);
          count(instance, 'data', 'data');
          count(instance.fn, 'data', 'fn.data');
          count(instance, 'hasData', 'hasData');
          this.stop = () => {
            active = false;
            restores.forEach((restore) => restore());
            this.stop = () => null;
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
    $expected = array_fill_keys(['Event', '_data', 'data', 'dispatch', 'find', 'fn.data', 'hasData', 'init'], 0);
    ksort($expected);
    ksort($counts);
    $this->assertSame($expected, $counts, $message);
  }

}
