<?php

declare(strict_types=1);

namespace Drupal\csrf_race_test\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller to test concurrent CSRF token generation.
 */
class TestController extends ControllerBase {

  /**
   * Controller constructor.
   */
  public function __construct(
    protected CsrfTokenGenerator $tokenGenerator,
  ) {
  }

  /**
   * Helper page to attach a late JavaScript library.
   *
   * @return array
   *   Empty page that attaches the debounce utility.
   */
  public function testMethod() {
    return [
      '#markup' => '',
      '#attached' => [
        'library' => ['core/drupal.debounce'],
      ],
    ];
  }

  /**
   * Just return generated CSRF token for concurrent requests.
   *
   * We delay the response to the first request to make sure the second request
   * is made when the first is not yet finished.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   CSRF token.
   */
  public function getCsrfToken(int $num) {
    sleep($num);
    return new JsonResponse($this->tokenGenerator->get());
  }

}
