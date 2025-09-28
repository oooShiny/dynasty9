<?php

namespace Drupal\storybook\Cache;

use Drupal\storybook\Util;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines a stub cache implementation.
 *
 * The stub implementation is needed when database access is not yet available.
 * Because Drupal's caching system never requires that cached data be present,
 * these stub functions can short-circuit the process and sidestep the need for
 * any persistent storage. Using this cache implementation during normal
 * operations would have a negative impact on performance.
 *
 * This also can be used for testing purposes.
 *
 * @ingroup cache
 */
class SkippableBackend implements CacheBackendInterface {

  /**
   * The backend when not skipping.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $backend;

  /**
   * The cache bin.
   *
   * @var string
   */
  private string $bin;

  /**
   * Weather or not to skip using cached values for this request.
   *
   * @var bool
   */
  private bool $skipCache = FALSE;

  /**
   * Constructs a SkippableBackend object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $backend
   *   The back-end.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param bool $development_mode
   *   Indicates if CL Server is in development mode.
   */
  public function __construct(CacheBackendInterface $backend, RequestStack $request_stack, bool $development_mode) {
    $this->backend = $backend;
    $request = $request_stack->getCurrentRequest();
    $this->skipCache = $development_mode && $request && Util::isRenderController($request);
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return $this->skipCache ? FALSE : $this->backend->get($cid, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    return $this->skipCache
      ? []
      : $this->backend->getMultiple($cids, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $this->backend->set($cid, $data, $expire, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items = []) {
    $this->backend->setMultiple($items);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    $this->backend->delete($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    $this->backend->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->backend->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    $this->backend->invalidate($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    $this->backend->invalidateMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    $this->backend->invalidateAll();
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    $this->backend->garbageCollection();
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->backend->removeBin();
  }

}
