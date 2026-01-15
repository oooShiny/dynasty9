<?php

namespace Drupal\dynasty_newsletter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for historical data queries.
 */
class NewsletterHistoricalData {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a NewsletterHistoricalData object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * Get games that happened on a specific date across all years.
   *
   * @param string $month
   *   Month number (01-12).
   * @param string $day
   *   Day number (01-31).
   *
   * @return array
   *   Array of game node IDs.
   */
  public function getGamesByDate($month, $day) {
    return $this->database->select('node__field_date', 'fd')
      ->fields('fd', ['entity_id'])
      ->condition('fd.bundle', 'game')
      ->where("MONTH(fd.field_date_value) = :month", [':month' => $month])
      ->where("DAY(fd.field_date_value) = :day", [':day' => $day])
      ->orderBy('fd.field_date_value', 'DESC')
      ->execute()
      ->fetchCol();
  }

  /**
   * Get events that happened on a specific date across all years.
   *
   * @param string $month
   *   Month number (01-12).
   * @param string $day
   *   Day number (01-31).
   *
   * @return array
   *   Array of event node IDs.
   */
  public function getEventsByDate($month, $day) {
    return $this->database->select('node__field_event_date', 'fed')
      ->fields('fed', ['entity_id'])
      ->condition('fed.bundle', 'event')
      ->where("MONTH(fed.field_event_date_value) = :month", [':month' => $month])
      ->where("DAY(fed.field_event_date_value) = :day", [':day' => $day])
      ->orderBy('fed.field_event_date_value', 'DESC')
      ->execute()
      ->fetchCol();
  }

}
