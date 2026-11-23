/**
 * @file
 * Keeps the taxonomy weight dividers up to date after a row moves.
 */

((Drupal) => {
  Drupal.behaviors.termDrag = {
    attach(context, settings) {
      const { backStep, forwardStep } = settings.taxonomy;
      const tableDrag = Drupal.tableDrag.taxonomy;
      const table = document.getElementById('taxonomy');
      const rows = table.querySelectorAll('tr').length;

      const markPreviewRows = () => {
        const classes = [
          'taxonomy-term-preview',
          'taxonomy-term-divider-top',
          'taxonomy-term-divider-bottom',
        ];
        table
          .querySelectorAll(classes.map((name) => `tr.${name}`).join(', '))
          .forEach((row) => row.classList.remove(...classes));
        const body = table.tBodies[0];
        if (backStep) {
          for (let index = 0; index < backStep; index++) {
            body.rows[index]?.classList.add('taxonomy-term-preview');
          }
          body.rows[backStep - 1]?.classList.add('taxonomy-term-divider-top');
          body.rows[backStep]?.classList.add('taxonomy-term-divider-bottom');
        }
        if (forwardStep) {
          for (let index = rows - forwardStep - 1; index < rows - 1; index++) {
            body.rows[index]?.classList.add('taxonomy-term-preview');
          }
          body.rows[rows - forwardStep - 2]?.classList.add(
            'taxonomy-term-divider-top',
          );
          body.rows[rows - forwardStep - 1]?.classList.add(
            'taxonomy-term-divider-bottom',
          );
        }
      };
      tableDrag.row.prototype.onSwap = markPreviewRows;
    },
  };
})(Drupal);
