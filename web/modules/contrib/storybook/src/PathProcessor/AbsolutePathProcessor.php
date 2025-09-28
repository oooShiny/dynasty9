<?php

namespace Drupal\storybook\PathProcessor;

use Drupal\storybook\Util;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Symfony\Component\HttpFoundation\Request;

/**
 * Processes the inbound path using path clServer lookups.
 */
class AbsolutePathProcessor implements OutboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], Request $request = NULL, BubbleableMetadata $bubbleable_metadata = NULL) {
    if (!$request) {
      return $path;
    }
    if (!Util::isRenderController($request)) {
      return $path;
    }
    // Make all the URLs absolute when rendering the components in isolation for
    // the component library.
    $options['absolute'] = TRUE;
    return $path;
  }

}
