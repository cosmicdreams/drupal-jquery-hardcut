<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

use Behat\Mink\Driver\Selenium2Driver;
use Behat\Mink\Element\NodeElement;
use WebDriver\Element;

/**
 * Drags Sortable items with WebDriver pointer actions.
 */
trait SortableTestTrait {

  /**
   * Drags an item from its handle to another item or region.
   *
   * @param \Behat\Mink\Element\NodeElement $source
   *   The draggable handle or item.
   * @param \Behat\Mink\Element\NodeElement $target
   *   The destination item or region.
   */
  protected function dragSortableItem(NodeElement $source, NodeElement $target): void {
    $driver = $this->getSession()->getDriver();
    assert($driver instanceof Selenium2Driver);
    $webdriver = $driver->getWebDriverSession();
    $from = $webdriver->element('xpath', $source->getXpath());
    $to = $webdriver->element('xpath', $target->getXpath());
    $webdriver->execute([
      'script' => 'arguments[0].scrollIntoView({block: "center", inline: "nearest"})',
      'args' => [$from],
    ]);

    // Start the drag with a short move, then allow the browser to deliver
    // dragover events at the destination before releasing the pointer.
    $webdriver->postActions([
      'actions' => [
        [
          'type' => 'pointer',
          'id' => 'mouse',
          'parameters' => ['pointerType' => 'mouse'],
          'actions' => [
            ['type' => 'pointerMove', 'origin' => [Element::WEB_ELEMENT_ID => $from->getID()], 'x' => 0, 'y' => 0],
            ['type' => 'pointerDown', 'button' => 0],
            ['type' => 'pointerMove', 'origin' => 'pointer', 'x' => 0, 'y' => 8, 'duration' => 100],
            [
              'type' => 'pointerMove',
              'origin' => [Element::WEB_ELEMENT_ID => $to->getID()],
              'x' => 0,
              'y' => 0,
              'duration' => 400,
            ],
            ['type' => 'pause', 'duration' => 250],
            ['type' => 'pointerUp', 'button' => 0],
          ],
        ],
      ],
    ]);
    $webdriver->deleteActions();
  }

}
