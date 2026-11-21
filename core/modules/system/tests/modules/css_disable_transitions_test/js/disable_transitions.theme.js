/**
 * @file
 * Disables effects on explicitly loaded jQuery test pages.
 */

((jquery) => {
  if (jquery?.fx) {
    jquery.fx.off = true;
  }
})(window.jQuery);
