/**
 * @file
 * Supplies an extension-defined legacy selector for the States fixture.
 * @param {function(Element): object} jquery
 *   The explicitly attached library instance.
 */

((jquery) => {
  jquery.expr.pseudos.statesTest = (element) =>
    element.dataset.statesTestPseudo === 'yes';
})(window.jQuery);
