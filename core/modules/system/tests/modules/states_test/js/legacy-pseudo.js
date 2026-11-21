/**
 * @file
 * Supplies an extension-defined legacy selector for the States fixture.
 */

((jquery) => {
  jquery.expr.pseudos.statesTest = (element) =>
    element.dataset.statesTestPseudo === 'yes';
})(window.jQuery);
