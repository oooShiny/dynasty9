<?php

namespace Drupal\dynasty_module;


use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

class PodcastNodeUpdate {

  public static function updateNode($episode, $stats, $month, $year, &$context) {
    $results = [];
    $node = Node::load($episode);
    if (!is_null($node)) {
      $title = $node->label();

      if (array_key_exists($node->label(), $stats)) {
        $dls = str_replace('"', '', $stats[$node->label()]);
      }
      else {
        $dls = 0;
      }

      if ($node->hasField('field_total_downloads') && !$node->field_total_downloads->isEmpty()){
        $total = $node->get('field_total_downloads')->value;
      }
      else {
        $total = 0;
      }
      $node->set('field_total_downloads', $total + $dls);
      // Create new paragraph.
      $paragraph = Paragraph::create([
        'type' => 'podcast_download',
        'field_downloads' => $dls,
        'field_month' => $month,
        'field_year' => $year,
      ]);
      $paragraph->isNew();
      $paragraph->save();

      // Add paragraph to node.
      $downloads = $node->get('field_monthly_downloads')->getValue();
      $downloads[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
      $node->set('field_monthly_downloads', $downloads);
      $results[] = $node->save();
      $context['results'] = $results;

    }
  }

}
