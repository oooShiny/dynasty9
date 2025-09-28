<?php

namespace Drupal\storybook\PageCache;

use Drupal\storybook\Util;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Do not serve a page from cache if serving from the rendering controller.
 *
 * @internal
 */
class DisallowPageCache implements RequestPolicyInterface {

  /**
   * Indicates weather to skipe the cache.
   *
   * @var bool
   */
  protected bool $skipCache;

  /**
   * Creates a new object.
   *
   * @param bool $development_mode
   *   Indicates if CL Server is in development mode.
   */
  public function __construct(bool $development_mode) {
    $this->skipCache = $development_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function check(Request $request) {
    return $this->skipCache && Util::isRenderController($request)
      ? static::DENY
      : NULL;
  }

}
