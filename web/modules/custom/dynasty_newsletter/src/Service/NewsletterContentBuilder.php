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
   * The newsletter AI service.
   *
   * @var \Drupal\dynasty_newsletter\Service\NewsletterAiService
   */
  protected $aiService;

  /**
   * Constructs a NewsletterContentBuilder object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    DateFormatterInterface $date_formatter,
    RendererInterface $renderer,
    $path_alias_manager,
    NewsletterAiService $ai_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->renderer = $renderer;
    $this->pathAliasManager = $path_alias_manager;
    $this->aiService = $ai_service;
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
    $news_iids = $config['news_iids'] ?? NULL;
    $podcast_nids = $config['podcast_nids'] ?? NULL;
    $external_podcast_iids = $config['external_podcast_iids'] ?? NULL;
    $skip_ai = $config['skip_ai'] ?? FALSE;
    $pre_processed_news = $config['pre_processed_news'] ?? NULL;

    $content = [
      'newsletter_title' => 'Patriots Dynasty Weekly - ' . date('F j, Y'),
      'newsletter_date' => date('F j, Y'),
      'news_items' => $pre_processed_news !== NULL
        ? $pre_processed_news
        : $this->getRecentNews(5, $news_iids, $skip_ai),
      'recent_games' => $this->getRecentGames(),
      'recent_podcasts' => $this->getRecentPodcasts(3, $podcast_nids),
      'external_podcasts' => $this->getExternalPodcasts(5, $external_podcast_iids),
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
      '#external_podcasts' => $content['external_podcasts'],
      '#on_this_date' => $content['on_this_date'],
      '#birthdays' => $content['birthdays'],
      '#historical_events' => $content['historical_events'],
    ];

    return $this->renderer->renderPlain($build);
  }

  /**
   * Get a raw pool of news items for remote AI curation.
   *
   * Unlike getRecentNews(), this method uses $limit directly (ignoring
   * news_items_limit config) and never runs AI processing. Intended for
   * the GET /api/newsletter/news-items endpoint.
   *
   * @param int $limit
   *   Number of items to retrieve.
   *
   * @return array
   *   Array of raw news items.
   */
  public function getNewsItemsForApi(int $limit = 20): array {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $podcast_feed_ids = $config->get('podcast_feed_ids') ?? [];
    $timestamp = strtotime('-7 days');

    $query = $this->database->select('aggregator_item', 'ai')
      ->fields('ai', ['iid', 'title', 'link', 'description', 'timestamp', 'fid'])
      ->condition('ai.timestamp', $timestamp, '>')
      ->orderBy('ai.timestamp', 'DESC')
      ->range(0, $limit);

    if (!empty($podcast_feed_ids)) {
      $query->condition('ai.fid', $podcast_feed_ids, 'NOT IN');
    }

    $items = $query->execute()->fetchAll();

    $news_items = [];
    foreach ($items as $item) {
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
   * Get recent news items from RSS aggregator.
   *
   * @param int $limit
   *   Number of items to retrieve.
   * @param array|null $iids
   *   Optional list of aggregator item IDs to fetch directly.
   *
   * @return array
   *   Array of news items.
   */
  protected function getRecentNews($limit = 5, ?array $iids = NULL, bool $skip_ai = FALSE) {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $limit = $config->get('news_items_limit') ?? $limit;

    if (!empty($iids)) {
      // Manual selection — fetch exactly those items.
      $items = $this->database->select('aggregator_item', 'ai')
        ->fields('ai', ['iid', 'title', 'link', 'description', 'timestamp', 'fid'])
        ->condition('ai.iid', $iids, 'IN')
        ->orderBy('ai.timestamp', 'DESC')
        ->execute()
        ->fetchAll();
    }
    else {
      // Automatic: fetch a larger pool when AI is enabled so the model has
      // more candidates to choose from.
      $use_ai = !$skip_ai && $this->aiService->isEnabled();
      $fetch_limit = $use_ai
        ? ($config->get('llm_news_pool_size') ?? 20)
        : $limit;

      $timestamp = strtotime('-7 days');
      $podcast_feed_ids = $config->get('podcast_feed_ids') ?? [];

      $query = $this->database->select('aggregator_item', 'ai')
        ->fields('ai', ['iid', 'title', 'link', 'description', 'timestamp', 'fid'])
        ->condition('ai.timestamp', $timestamp, '>')
        ->orderBy('ai.timestamp', 'DESC')
        ->range(0, $fetch_limit);

      if (!empty($podcast_feed_ids)) {
        $query->condition('ai.fid', $podcast_feed_ids, 'NOT IN');
      }

      $items = $query->execute()->fetchAll();
    }

    $news_items = [];
    foreach ($items as $item) {
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

    // When AI is enabled and this is an automatic run, curate and summarize.
    if (!$skip_ai && empty($iids) && $this->aiService->isEnabled()) {
      $news_items = $this->aiService->curateAndSummarizeNews($news_items, (int) $limit);
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
   * @param array|null $nids
   *   Optional list of node IDs to load directly.
   *
   * @return array
   *   Array of podcast data.
   */
  protected function getRecentPodcasts($limit = 3, ?array $nids = NULL) {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $limit = $config->get('recent_podcasts_limit') ?? $limit;

    if (!empty($nids)) {
      $podcast_nids = $nids;
    }
    else {
      $podcast_nids = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->condition('type', 'podcast_episode')
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
      $description = $podcast->get('body')->value ?? '';
      // Temporarily remove the survey prompt added by Acast.
      $description = preg_replace('/<p[^>]*>\s*We want to know what you think.*?<\/p>/s', '', $description);
      $description = trim($description);

      $recent_podcasts[] = [
        'title' => $podcast->getTitle(),
        'description' => $description,
        'url' => \Drupal::request()->getSchemeAndHttpHost() . $this->pathAliasManager->getAliasByPath('/node/' . $podcast->id()),
        'episode' => $podcast->get('field_episode')->value,
        'season' => $podcast->get('field_season')->value,
      ];
    }

    return $recent_podcasts;
  }

  /**
   * Get podcast episodes from external RSS feeds via the aggregator.
   *
   * @param int $limit
   *   Number of items to retrieve.
   * @param array|null $iids
   *   Optional list of aggregator item IDs to fetch directly.
   *
   * @return array
   *   Array of external podcast items.
   */
  protected function getExternalPodcasts($limit = 5, ?array $iids = NULL) {
    $config = \Drupal::config('dynasty_newsletter.settings');
    $podcast_feed_ids = $config->get('podcast_feed_ids') ?? [];

    if (!empty($iids)) {
      $items = $this->database->select('aggregator_item', 'ai')
        ->fields('ai', ['iid', 'title', 'link', 'description', 'timestamp', 'fid'])
        ->condition('ai.iid', $iids, 'IN')
        ->orderBy('ai.timestamp', 'DESC')
        ->execute()
        ->fetchAll();
    }
    elseif (!empty($podcast_feed_ids)) {
      $timestamp = strtotime('-14 days');
      $items = $this->database->select('aggregator_item', 'ai')
        ->fields('ai', ['iid', 'title', 'link', 'description', 'timestamp', 'fid'])
        ->condition('ai.fid', $podcast_feed_ids, 'IN')
        ->condition('ai.timestamp', $timestamp, '>')
        ->orderBy('ai.timestamp', 'DESC')
        ->range(0, $limit)
        ->execute()
        ->fetchAll();
    }
    else {
      return [];
    }

    $external_podcasts = [];
    foreach ($items as $item) {
      $feed_name = $this->database->select('aggregator_feed', 'af')
        ->fields('af', ['title'])
        ->condition('af.fid', $item->fid)
        ->execute()
        ->fetchField();

      $external_podcasts[] = [
        'title' => $item->title,
        'link' => $item->link,
        'description' => strip_tags($item->description),
        'source' => $feed_name,
        'date' => date('M j, Y', $item->timestamp),
      ];
    }

    return $external_podcasts;
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

    // Get current date range (upcoming Sunday + next 7 days)
    $start_date = date('m-d', strtotime('+2 days'));
    $end_date = date('m-d', strtotime('+8 days'));

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
    // Add each day from $start_date to $end_date to the $birthdays array.
    $current = strtotime('+2 days');
    $end = strtotime('+8 days');
    while ($current <= $end) {
      $birthdays[date('m', $current)][date('j', $current)] = [];
      $current = strtotime('+1 day', $current);
    }

    foreach ($players as $player) {
      $birthday_value = $player->get('field_birthday')->value;
      $position = $player->get('field_player_position')->entity;

      $birthdays[date('m', strtotime($birthday_value))][date('j', strtotime($birthday_value))][] = [
        'player_name' => $player->getTitle(),
        'position' => $position ? $position->getName() : '',
        'birth_year' => substr($birthday_value, 0, 4),
        'nid' => $player->id(),
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
