<?php

namespace Drupal\dynasty_newsletter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Path\PathAliasManagerInterface;
use Drupal\node\Entity\Node;

/**
 * Service for building newsletter content.
 */
class NewsletterContentBuilder {

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
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The path alias manager.
   *
   * @var \Drupal\Core\Path\PathAliasManagerInterface
   */
  protected $pathAliasManager;

  /**
   * Constructs a NewsletterContentBuilder object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    DateFormatterInterface $date_formatter,
    RendererInterface $renderer,
    $path_alias_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->renderer = $renderer;
    $this->pathAliasManager = $path_alias_manager;
  }

  /**
   * Build complete newsletter content.
   *
   * @param array $config
   *   Configuration options.
   *
   * @return string
   *   Rendered HTML for newsletter.
   */
  public function buildNewsletterContent(array $config = []) {
    $content = [
      'newsletter_title' => 'Patriots Dynasty Weekly - ' . date('F j, Y'),
      'newsletter_date' => date('F j, Y'),
      'news_items' => $this->getRecentNews(),
      'recent_games' => $this->getRecentGames(),
      'recent_podcasts' => $this->getRecentPodcasts(),
      'on_this_date' => $this->getHistoricalContent(),
      'birthdays' => $this->getPlayerBirthdays(),
      'historical_events' => $this->getHistoricalEvents(),
    ];

    // Render template
    $build = [
      '#theme' => 'newsletter_issue',
      '#newsletter_title' => $content['newsletter_title'],
      '#newsletter_date' => $content['newsletter_date'],
      '#news_items' => $content['news_items'],
      '#recent_games' => $content['recent_games'],
      '#recent_podcasts' => $content['recent_podcasts'],
      '#on_this_date' => $content['on_this_date'],
      '#birthdays' => $content['birthdays'],
      '#historical_events' => $content['historical_events'],
    ];

    return $this->renderer->renderPlain($build);
  }

  /**
   * Get recent news items from RSS aggregator.
   *
   * @param int $limit
   *   Number of items to retrieve.
   *
   * @return array
   *   Array of news items.
   */
  protected function getRecentNews($limit = 5) {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $limit = $config->get('news_items_limit') ?? $limit;

    // Query aggregator items from last 7 days
    $timestamp = strtotime('-7 days');

    $items = $this->database->select('aggregator_item', 'ai')
      ->fields('ai', ['iid', 'title', 'link', 'description', 'timestamp', 'fid'])
      ->condition('ai.timestamp', $timestamp, '>')
      ->orderBy('ai.timestamp', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();

    $news_items = [];
    foreach ($items as $item) {
      // Get feed name
      $feed = $this->database->select('aggregator_feed', 'af')
        ->fields('af', ['title'])
        ->condition('af.fid', $item->fid)
        ->execute()
        ->fetchField();

      $news_items[] = [
        'title' => $item->title,
        'link' => $item->link,
        'description' => strip_tags($item->description),
        'source' => $feed,
        'date' => date('M j, Y', $item->timestamp),
      ];
    }

    return $news_items;
  }

  /**
   * Get recent game results.
   *
   * @param int $limit
   *   Number of games to retrieve.
   *
   * @return array
   *   Array of game data.
   */
  protected function getRecentGames($limit = 3) {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $limit = $config->get('recent_games_limit') ?? $limit;

    // Query games from last 7 days
    $timestamp = strtotime('-7 days');
    $date_string = date('Y-m-d', $timestamp);

    $game_nids = $this->database->select('node__field_date', 'fd')
      ->fields('fd', ['entity_id'])
      ->condition('fd.bundle', 'game')
      ->condition('fd.field_date_value', $date_string, '>')
      ->orderBy('fd.field_date_value', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchCol();

    if (empty($game_nids)) {
      return [];
    }

    $games = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($game_nids);

    $recent_games = [];
    foreach ($games as $game) {
      $opponent = $game->get('field_opponent')->entity;
      $opponent_name = $opponent ? $opponent->getTitle() : 'Unknown';

      // Extract just the team name (last word)
      $opponent_parts = explode(' ', $opponent_name);
      $opponent_short = end($opponent_parts);

      $recent_games[] = [
        'title' => $game->getTitle(),
        'url' => \Drupal::request()->getSchemeAndHttpHost() . $this->pathAliasManager->getAliasByPath('/node/' . $game->id()),
        'date' => $game->get('field_date')->value,
        'patriots_score' => $game->get('field_patriots_score')->value,
        'opponent_score' => $game->get('field_opponent_score')->value,
        'opponent_name' => $opponent_short,
        'result' => $game->get('field_result')->value,
        'week' => $game->get('field_week')->entity ? $game->get('field_week')->entity->getName() : '',
        'season' => $game->get('field_season')->value,
      ];
    }

    return $recent_games;
  }

  /**
   * Get recent podcast episodes.
   *
   * @param int $limit
   *   Number of episodes to retrieve.
   *
   * @return array
   *   Array of podcast data.
   */
  protected function getRecentPodcasts($limit = 3) {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $limit = $config->get('recent_podcasts_limit') ?? $limit;

    // First try to query by publication date field (if it exists)
    $timestamp = strtotime('-7 days');
    $date_string = date('Y-m-d', $timestamp);

    try {
      // Try publication date field first
      $podcast_nids = $this->database->select('node__field_publication_date', 'fpd')
        ->fields('fpd', ['entity_id'])
        ->condition('fpd.bundle', 'podcast_episode')
        ->condition('fpd.field_publication_date_value', $date_string, '>')
        ->orderBy('fpd.field_publication_date_value', 'DESC')
        ->range(0, $limit)
        ->execute()
        ->fetchCol();
    }
    catch (\Exception $e) {
      // Fall back to created date if publication date field doesn't exist yet
      $podcast_nids = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->condition('type', 'podcast_episode')
        ->condition('created', $timestamp, '>')
        ->sort('created', 'DESC')
        ->range(0, $limit)
        ->accessCheck(TRUE)
        ->execute();
    }

    if (empty($podcast_nids)) {
      return [];
    }

    $podcasts = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($podcast_nids);

    $recent_podcasts = [];
    foreach ($podcasts as $podcast) {
      $recent_podcasts[] = [
        'title' => $podcast->getTitle(),
        'subtitle' => $podcast->get('field_subtitle')->value ?? '',
        'url' => \Drupal::request()->getSchemeAndHttpHost() . $this->pathAliasManager->getAliasByPath('/node/' . $podcast->id()),
        'episode' => $podcast->get('field_episode')->value,
        'season' => $podcast->get('field_season')->value,
      ];
    }

    return $recent_podcasts;
  }

  /**
   * Get "On This Date" historical games.
   *
   * @param int $limit
   *   Number of historical games to retrieve.
   *
   * @return array
   *   Array of historical game data.
   */
  protected function getHistoricalContent($limit = 5) {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $limit = $config->get('historical_games_limit') ?? $limit;

    $today = date('m-d');
    [$month, $day] = explode('-', $today);

    // Query games matching current month/day from any year
    // Exclude current year to focus on historical content
    $current_year = date('Y');

    $game_nids = $this->database->select('node__field_date', 'fd')
      ->fields('fd', ['entity_id'])
      ->condition('fd.bundle', 'game')
      ->where("MONTH(fd.field_date_value) = :month", [':month' => $month])
      ->where("DAY(fd.field_date_value) = :day", [':day' => $day])
      ->where("YEAR(fd.field_date_value) < :year", [':year' => $current_year])
      ->orderBy('fd.field_date_value', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchCol();

    if (empty($game_nids)) {
      return [];
    }

    $games = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($game_nids);

    $historical_games = [];
    foreach ($games as $game) {
      $opponent = $game->get('field_opponent')->entity;
      $opponent_name = $opponent ? $opponent->getTitle() : 'Unknown';

      // Extract just the team name
      $opponent_parts = explode(' ', $opponent_name);
      $opponent_short = end($opponent_parts);

      $date = $game->get('field_date')->value;
      $year = substr($date, 0, 4);

      $description = sprintf(
        'Patriots %s, %s %s',
        $game->get('field_patriots_score')->value,
        $opponent_short,
        $game->get('field_opponent_score')->value
      );

      if ($game->get('field_result')->value === 'W') {
        $description = 'Win: ' . $description;
      }
      elseif ($game->get('field_result')->value === 'L') {
        $description = 'Loss: ' . $description;
      }

      $historical_games[] = [
        'year' => $year,
        'description' => $description,
        'url' => \Drupal::request()->getSchemeAndHttpHost() . $this->pathAliasManager->getAliasByPath('/node/' . $game->id()),
        'week' => $game->get('field_week')->entity ? $game->get('field_week')->entity->getName() : '',
      ];
    }

    return $historical_games;
  }

  /**
   * Get player birthdays this week.
   *
   * @param int $limit
   *   Number of birthdays to retrieve.
   *
   * @return array
   *   Array of player birthday data.
   */
  protected function getPlayerBirthdays($limit = 10) {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $limit = $config->get('birthdays_limit') ?? $limit;

    // Get current date range (today + next 7 days)
    $start_date = date('m-d');
    $end_date = date('m-d', strtotime('+7 days'));

    [$start_month, $start_day] = explode('-', $start_date);
    [$end_month, $end_day] = explode('-', $end_date);

    // Query player birthdays
    $query = $this->database->select('node__field_birthday', 'fb');
    $query->fields('fb', ['entity_id']);
    $query->condition('fb.bundle', 'player');

    // Handle year wrap-around (e.g., Dec 28 - Jan 3)
    if ($start_month > $end_month) {
      $or = $query->orConditionGroup();
      $or->condition($query->andConditionGroup()
        ->where("MONTH(fb.field_birthday_value) = :start_month", [':start_month' => $start_month])
        ->where("DAY(fb.field_birthday_value) >= :start_day", [':start_day' => $start_day])
      );
      $or->condition($query->andConditionGroup()
        ->where("MONTH(fb.field_birthday_value) = :end_month", [':end_month' => $end_month])
        ->where("DAY(fb.field_birthday_value) <= :end_day", [':end_day' => $end_day])
      );
      $query->condition($or);
    }
    else {
      // Same month or adjacent months
      if ($start_month == $end_month) {
        $query->where("MONTH(fb.field_birthday_value) = :month", [':month' => $start_month]);
        $query->where("DAY(fb.field_birthday_value) BETWEEN :start_day AND :end_day",
          [':start_day' => $start_day, ':end_day' => $end_day]);
      }
      else {
        $or = $query->orConditionGroup();
        $or->condition($query->andConditionGroup()
          ->where("MONTH(fb.field_birthday_value) = :start_month", [':start_month' => $start_month])
          ->where("DAY(fb.field_birthday_value) >= :start_day", [':start_day' => $start_day])
        );
        $or->condition($query->andConditionGroup()
          ->where("MONTH(fb.field_birthday_value) = :end_month", [':end_month' => $end_month])
          ->where("DAY(fb.field_birthday_value) <= :end_day", [':end_day' => $end_day])
        );
        $query->condition($or);
      }
    }

    $query->range(0, $limit);

    $player_nids = $query->execute()->fetchCol();

    if (empty($player_nids)) {
      return [];
    }

    $players = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($player_nids);

    $birthdays = [];
    foreach ($players as $player) {
      $birthday_value = $player->get('field_birthday')->value;
      $position = $player->get('field_player_position')->entity;

      $birthdays[] = [
        'player_name' => $player->getTitle(),
        'date' => date('F j', strtotime($birthday_value)),
        'position' => $position ? $position->getName() : '',
        'birth_year' => substr($birthday_value, 0, 4),
      ];
    }

    return $birthdays;
  }

  /**
   * Get historical events this week.
   *
   * @param int $limit
   *   Number of events to retrieve.
   *
   * @return array
   *   Array of event data.
   */
  protected function getHistoricalEvents($limit = 5) {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $limit = $config->get('events_limit') ?? $limit;

    // Get current date range (today + next 7 days)
    $start_date = date('m-d');
    $end_date = date('m-d', strtotime('+7 days'));

    [$start_month, $start_day] = explode('-', $start_date);
    [$end_month, $end_day] = explode('-', $end_date);

    // Query events
    $query = $this->database->select('node__field_event_date', 'fed');
    $query->fields('fed', ['entity_id']);
    $query->condition('fed.bundle', 'event');

    // Handle year wrap-around
    if ($start_month > $end_month) {
      $or = $query->orConditionGroup();
      $or->condition($query->andConditionGroup()
        ->where("MONTH(fed.field_event_date_value) = :start_month", [':start_month' => $start_month])
        ->where("DAY(fed.field_event_date_value) >= :start_day", [':start_day' => $start_day])
      );
      $or->condition($query->andConditionGroup()
        ->where("MONTH(fed.field_event_date_value) = :end_month", [':end_month' => $end_month])
        ->where("DAY(fed.field_event_date_value) <= :end_day", [':end_day' => $end_day])
      );
      $query->condition($or);
    }
    else {
      if ($start_month == $end_month) {
        $query->where("MONTH(fed.field_event_date_value) = :month", [':month' => $start_month]);
        $query->where("DAY(fed.field_event_date_value) BETWEEN :start_day AND :end_day",
          [':start_day' => $start_day, ':end_day' => $end_day]);
      }
      else {
        $or = $query->orConditionGroup();
        $or->condition($query->andConditionGroup()
          ->where("MONTH(fed.field_event_date_value) = :start_month", [':start_month' => $start_month])
          ->where("DAY(fed.field_event_date_value) >= :start_day", [':start_day' => $start_day])
        );
        $or->condition($query->andConditionGroup()
          ->where("MONTH(fed.field_event_date_value) = :end_month", [':end_month' => $end_month])
          ->where("DAY(fed.field_event_date_value) <= :end_day", [':end_day' => $end_day])
        );
        $query->condition($or);
      }
    }

    $query->orderBy('fed.field_event_date_value', 'DESC');
    $query->range(0, $limit);

    $event_nids = $query->execute()->fetchCol();

    if (empty($event_nids)) {
      return [];
    }

    $events = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($event_nids);

    $historical_events = [];
    foreach ($events as $event) {
      $event_date = $event->get('field_event_date')->value;
      $year = substr($event_date, 0, 4);

      $body = $event->get('body')->value;
      $description = strip_tags($body);
      // Truncate to 200 characters
      if (strlen($description) > 200) {
        $description = substr($description, 0, 200) . '...';
      }

      $historical_events[] = [
        'title' => $event->getTitle(),
        'year' => $year,
        'date' => date('F j', strtotime($event_date)),
        'description' => $description,
      ];
    }

    return $historical_events;
  }

}
