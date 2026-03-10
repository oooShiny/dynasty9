<?php

namespace Drupal\dynasty_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns a rendered random highlight block for AJAX loading.
 */
class RandomHighlightController extends ControllerBase {

  public function content(): Response {
    $block_manager = \Drupal::service('plugin.manager.block');
    $block = $block_manager->createInstance('views_block:random_highlight-block_1', []);

    $render = $block->build();
    $html = \Drupal::service('renderer')->renderInIsolation($render);

    return new Response((string) $html);
  }

}
