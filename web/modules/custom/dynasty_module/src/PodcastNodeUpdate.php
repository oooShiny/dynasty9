<?php

namespace Drupal\dynasty_module;


use Drupal\node\Entity\Node;
use Drupal\dynasty_transcript\Entity\DynastyTranscript;
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

  /**
   * Create a new dynasty_transcript custom entity.
   *
   * @param $episode
   * @param $transcript
   * @param $context
   *
   * @return void
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public static function createTranscriptLine($episode, $transcript, &$context) {
    // Create a new transcript line entity.
    $line = DynastyTranscript::create();

    $start = $transcript['start']; // "01:03:37,060"
    $time_pieces = explode(':', $start);

    // If we have the hour marker, set it.
    $hours = 0;
    if (count($time_pieces) > 2) {
      $hours = $time_pieces[0];
      $minutes = $time_pieces[1];

      // Break up the seconds.
      $seconds = explode(',', $time_pieces[2]);
    }
    else {
      $minutes = $time_pieces[0];
      // Break up the seconds.
      $seconds = explode(',', $time_pieces[1]);
    }

    $timestamp = ($hours * 3600) + ($minutes * 60) + $seconds[0];

    $line->set('field_hours', $hours);
    $line->set('field_minutes', $minutes);
    $line->set('field_seconds', $seconds[0]);
    $line->set('field_milliseconds', $seconds[1] ?? 0);
    $line->set('field_timestamp', $timestamp);
    $line->set('field_transcript', $transcript['text']);
    $line->set('field_podcast_episode', ['target_id' => $episode]);
    $line->set('title', substr($transcript['text'], 0, 254));

    $results[] = $line->save();
    $context['results'] = $results;
  }

}
