<?php

namespace Drupal\dynasty_newsletter\Commands;

use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Dynasty Newsletter.
 */
class NewsletterCommands extends DrushCommands {

  /**
   * The newsletter content builder service.
   *
   * @var \Drupal\dynasty_newsletter\Service\NewsletterContentBuilder
   */
  protected $contentBuilder;

  /**
   * Constructs a NewsletterCommands object.
   *
   * @param \Drupal\dynasty_newsletter\Service\NewsletterContentBuilder $content_builder
   *   The newsletter content builder service.
   */
  public function __construct($content_builder = NULL) {
    parent::__construct();
    $this->contentBuilder = $content_builder ?: \Drupal::service('dynasty_newsletter.content_builder');
  }

  /**
   * Generate a draft newsletter.
   *
   * @command dynasty-newsletter:generate
   * @aliases dnews-gen
   * @usage dynasty-newsletter:generate
   *   Generate a new newsletter draft.
   */
  public function generate() {
    try {
      // Build newsletter content
      $html = $this->contentBuilder->buildNewsletterContent();

      // Create Simplenews issue node
      $newsletter = Node::create([
        'type' => 'simplenews_issue',
        'title' => 'Patriots Dynasty Weekly - ' . date('F j, Y'),
        'body' => [
          'value' => $html,
          'format' => 'full_html',
        ],
        'simplenews_issue' => [
          'target_id' => 'patriots_dynasty_weekly',
        ],
        'status' => 0, // Unpublished draft
      ]);
      $newsletter->save();

      $this->output()->writeln('Newsletter generated successfully: ' . $newsletter->id());
      $this->output()->writeln('Title: ' . $newsletter->getTitle());
      $this->output()->writeln('Edit: ' . $newsletter->toUrl('edit-form', ['absolute' => TRUE])->toString());

    }
    catch (\Exception $e) {
      $this->output()->writeln('Failed to generate newsletter: ' . $e->getMessage());
      $this->logger()->error('Newsletter generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return DrushCommands::EXIT_FAILURE;
    }

    return DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Backfill publication dates for podcast episodes.
   *
   * @command dynasty-newsletter:backfill-dates
   * @aliases dnews-backfill
   * @usage dynasty-newsletter:backfill-dates
   *   Backfill publication dates for all podcast episodes.
   */
  public function backfillDates() {
    $entity_type_manager = \Drupal::entityTypeManager();

    // Get all podcast episode nodes
    $podcast_nids = $entity_type_manager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'podcast_episode')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($podcast_nids)) {
      $this->output()->writeln('No podcast episodes found.');
      return DrushCommands::EXIT_SUCCESS;
    }

    $podcasts = $entity_type_manager
      ->getStorage('node')
      ->loadMultiple($podcast_nids);

    $updated = 0;
    foreach ($podcasts as $podcast) {
      // Skip if publication date is already set
      if (!$podcast->get('field_publication_date')->isEmpty()) {
        continue;
      }

      $publication_date = NULL;

      // Priority 1: Use referenced game's date
      if (!$podcast->get('field_game')->isEmpty()) {
        $game = $podcast->get('field_game')->entity;
        if ($game && !$game->get('field_date')->isEmpty()) {
          $publication_date = $game->get('field_date')->value;
        }
      }

      // Priority 2: Use node created timestamp
      if (!$publication_date) {
        $created_timestamp = $podcast->getCreatedTime();
        $publication_date = date('Y-m-d', $created_timestamp);
      }

      // Set the publication date
      if ($publication_date) {
        $podcast->set('field_publication_date', $publication_date);
        $podcast->save();
        $updated++;
      }
    }

    $this->output()->writeln("Backfilled publication dates for $updated podcast episodes.");
    return DrushCommands::EXIT_SUCCESS;
  }

}
