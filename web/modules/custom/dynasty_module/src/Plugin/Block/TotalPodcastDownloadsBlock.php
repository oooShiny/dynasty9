<?php

namespace Drupal\dynasty_module\Plugin\Block;

use DateTime;
use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;

/**
 * Provides a Block that displays podcast downloads by month.
 *
 * @Block(
 *   id = "total_podcast_downloads_block",
 *   admin_label = @Translation("Total Podcast Downloads Block"),
 *   category = @Translation("Dynasty"),
 * )
 */
class TotalPodcastDownloadsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    $nids = \Drupal::entityQuery('node')
      ->condition('type','podcast_episode')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('created' , 'ASC')
      ->execute();

    $downloads = [];
    $months = [];
    // Load all podcast nodes.
    foreach (Node::loadMultiple($nids) as $node) {
      // Load all download paragraphs.
      $dls = 0;
      foreach ($node->field_monthly_downloads->referencedEntities() as $p) {
        $m = $p->get('field_month')->value;
        $y = $p->get('field_year')->value;
        $dateObj   = DateTime::createFromFormat('!m', $m);
        $monthName = $dateObj->format('F');
        if ($m < 10) {
          $m = '0'.$m;
        }
        $months[$y.$m] = [
          'year' => $y,
          'month' => $monthName,
        ];
        $dls += $p->get('field_downloads')->value;
        $downloads[$node->label()][] = $dls;
      }
    }

    ksort($months);

    return [
      '#theme' => 'total_podcast_downloads_block',
      '#downloads' => $downloads,
      '#months' => $months,
      '#attached' => [
        'library' => ['dynasty_module/highchart_js']
      ],
    ];
  }

}
