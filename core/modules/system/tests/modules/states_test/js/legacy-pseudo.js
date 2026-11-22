/**
 * @file
 * Supplies an extension-defined legacy selector for the States fixture.
 * @param {function(HTMLElement): object} jquery
 *   The explicitly loaded jQuery instance.
 */

((jquery) => {
  jquery.expr.pseudos.statesTest = (element) =>
    element.dataset.statesTestPseudo === 'yes';
})(window.jQuery);
