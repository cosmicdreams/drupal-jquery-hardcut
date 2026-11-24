<?php

declare(strict_types=1);

namespace Drupal\Tests\test_jquery_free\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\test_jquery_free\ReadinessScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the line-based jQuery detector.
 */
#[CoversClass(ReadinessScanner::class)]
#[Group('test_jquery_free')]
final class ReadinessHeuristicTest extends UnitTestCase {

  /**
   * Tests common references and the documented false positives.
   */
  #[DataProvider('referenceCases')]
  public function testReferencesJquery(string $line, bool $expected): void {
    $this->assertSame($expected, ReadinessScanner::referencesJquery($line));
  }

  /**
   * Provides lines of JavaScript source.
   */
  public static function referenceCases(): iterable {
    yield 'named global' => ['jQuery(node);', TRUE];
    yield 'named property' => ['window.jQuery.ajax(url);', TRUE];
    yield 'short call' => ['$(node).hide();', TRUE];
    yield 'short property' => ['$.ajax(url);', TRUE];
    yield 'IIFE parameter' => ['(function ($) {', TRUE];
    yield 'spaced call' => ['window.$ (node);', TRUE];
    yield 'comment' => ['// Originally used jQuery.', TRUE];
    yield 'string' => ['console.log("jQuery");', TRUE];
    yield 'native' => ['document.querySelector("main");', FALSE];
    yield 'larger name' => ['const my_jQuery_plugin = 1;', FALSE];
    yield 'local variable suffix' => ['format$(value);', FALSE];
    yield 'regular function' => ['(function (event) {', FALSE];
    yield 'empty line' => ['', FALSE];
  }

}
