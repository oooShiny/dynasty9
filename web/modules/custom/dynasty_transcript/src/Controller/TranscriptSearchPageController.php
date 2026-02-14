<?php

namespace Drupal\dynasty_transcript\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for the transcript search page.
 */
class TranscriptSearchPageController extends ControllerBase {

  /**
   * Renders the transcript search page.
   *
   * @return array
   *   A render array for the search page.
   */
  public function page(): array {
    return [
      '#theme' => 'transcript_search',
      '#is_block' => FALSE,
      '#wrapper_classes' => 'max-w-4xl mx-auto p-4',
      '#attached' => [
        'library' => [
          'dynasty_transcript/transcript_search',
        ],
      ],
    ];
  }

}
