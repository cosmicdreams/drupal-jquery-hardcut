/**
 * @file
 * Overrides vertical tabs theming to enable Default Admin designs.
 */

((Drupal) => {
  /**
   * Themes a tab using Default Admin's native markup.
   */
  Drupal.theme.verticalTabElements = ({ title }) => {
    const template = document.createElement('template');
    template.innerHTML =
      '<li class="vertical-tabs__menu-item" tabindex="-1"><a href="#" class="vertical-tabs__menu-link"><span class="vertical-tabs__menu-link-content"><span class="vertical-tabs__menu-item-title"></span><span class="vertical-tabs__menu-link-summary"></span></span></a></li>';
    const itemElement = template.content.firstElementChild;
    const linkElement = itemElement.firstElementChild;
    const content = linkElement.firstElementChild;
    const titleElement = content.firstElementChild;
    const summaryElement = content.lastElementChild;
    titleElement.textContent = title;
    return { itemElement, linkElement, titleElement, summaryElement };
  };
})(Drupal);
