<?php

namespace Drupal\dynasty_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\node;

class PatsAdminController extends ControllerBase {

  /**
   * Display the markup.
   *
   * @return array
   */
  public function content() {
    $links = [
      'podcast' => [
        '/admin/dynasty/podcast/no-transcripts' => 'Episodes without Transcripts',
        '/admin/dynasty/podcast/transcripts' => 'Import Podcast Transcript',
        '/admin/dynasty/podcast/analytics' => 'Upload Podcast Analytics',
      ],
      'videos' => [
        '/admin/dynasty/map-highlights' => 'Map Highlights to Games',
        '/admin/dynasty/muse/import' => 'Import Gfycat Videos from Muse.ai',
        '/admin/dynasty/muse/youtube' => 'Import Youtube Videos from Muse.ai',
        '/admin/dynasty/missing-youtube-highlights' => 'Missing Youtube highlights',
      ],
      'games' => [
        '/calendar' => 'Patriots Game Calendar',
        '/admin/dynasty/oc-dc-admin' => 'Add OC/DC to games',
        '/admin/dynasty/tweeted-highlights' => 'Tweeted Highlights',
      ],

    ];

    return [
      '#theme' => 'dynasty_admin_page',
      '#admin_links' => $links,
    ];
  }
}
