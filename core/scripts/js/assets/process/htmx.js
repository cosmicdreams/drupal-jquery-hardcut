/**
 * @file
 * Preserves Drupal's guarded htmx swaps during vendor updates.
 */

const swapAnchors = {
  'htmx.js': {
    before: '                this.#handleHistoryUpdate(ctx);',
    after: `                // Public listeners can invalidate a response before behaviors are detached.
                if (ctx.swapGuard && !ctx.swapGuard()) return;
                if (!this.#triggerExtensions(ctx.sourceElement, "htmx:before:insert", {ctx, tasks})) return;

                this.#handleHistoryUpdate(ctx);
                if (ctx.swapGuard && !ctx.swapGuard()) return;`,
  },
  'htmx.min.js': {
    before: 'this.#We(e);let l=[],h=[];for(let t of i)',
    after:
      'if(e.swapGuard&&!e.swapGuard())return;if(!this.#$(e.sourceElement,"htmx:before:insert",{ctx:e,tasks:i}))return;this.#We(e);if(e.swapGuard&&!e.swapGuard())return;let l=[],h=[];for(let t of i)',
  },
};

module.exports = ({ file, contents }) => {
  // The extension hook must run after the public before-swap event and before
  // history handling. Recheck freshness after history listeners run, too.
  const { before, after } = swapAnchors[file.to];
  if (contents.split(before).length !== 2) {
    throw new Error(`Expected one htmx swap anchor in ${file.to}. Update the vendor patch.`);
  }
  return [{ contents: contents.replace(before, () => after) }];
};
