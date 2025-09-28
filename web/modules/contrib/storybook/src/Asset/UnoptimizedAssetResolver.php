<?php

namespace Drupal\storybook\Asset;

use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\storybook\Util;
use Drupal\Core\Asset\AttachedAssetsInterface;
use Drupal\Core\Language\LanguageInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The asset resolver that forces skipping optimizations.
 */
class UnoptimizedAssetResolver implements AssetResolverInterface {

  /**
   * The decorated resolver.
   *
   * @var \Drupal\Core\Asset\AssetResolverInterface
   */
  private $resolver;

  /**
   * Weather or not to skip optimization.
   *
   * @var bool
   */
  private bool $skipOptimization = FALSE;

  /**
   * Creates a new asset resolver.
   *
   * @param \Drupal\Core\Asset\AssetResolverInterface $resolver
   *   The actual resolver.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param bool $development_mode
   *   Indicates if CL Server is in development mode.
   */
  public function __construct(AssetResolverInterface $resolver, RequestStack $request_stack, bool $development_mode) {
    $this->resolver = $resolver;
    $request = $request_stack->getCurrentRequest();
    $this->skipOptimization = $development_mode && $request && Util::isRenderController($request);
  }

  /**
   * @inheritDoc
   */
  public function getCssAssets(AttachedAssetsInterface $assets, $optimize, LanguageInterface $language = NULL) {
    return $this->resolver->getCssAssets(
      $assets,
      $this->skipOptimization ? FALSE : $optimize,
      $language
    );
  }

  /**
   * @inheritDoc
   */
  public function getJsAssets(AttachedAssetsInterface $assets, $optimize, LanguageInterface $language = NULL) {
    return $this->resolver->getJsAssets(
      $assets,
      $this->skipOptimization ? FALSE : $optimize,
      $language
    );
  }

}
