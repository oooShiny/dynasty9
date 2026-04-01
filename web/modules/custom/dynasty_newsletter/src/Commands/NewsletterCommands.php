<?php

namespace Drupal\dynasty_newsletter\Commands;

use Drupal\dynasty_newsletter\Service\NewsletterAiService;
use Drupal\dynasty_newsletter\Service\NewsletterContentBuilder;
use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

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
   * The newsletter AI service.
   *
   * @var \Drupal\dynasty_newsletter\Service\NewsletterAiService
   */
  protected $aiService;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a NewsletterCommands object.
   */
  public function __construct(
    NewsletterContentBuilder $content_builder = NULL,
    NewsletterAiService $ai_service = NULL,
    ClientInterface $http_client = NULL
  ) {
    parent::__construct();
    $this->contentBuilder = $content_builder ?: \Drupal::service('dynasty_newsletter.content_builder');
    $this->aiService = $ai_service ?: \Drupal::service('dynasty_newsletter.ai_service');
    $this->httpClient = $http_client ?: \Drupal::httpClient();
  }

  /**
   * Generate a draft newsletter.
   *
   * @command dynasty-newsletter:generate
   * @aliases dnews-gen
   * @option no-ai Skip LLM curation even if it is configured in settings.
   * @option remote Fetch news from the remote production site, curate locally
   *   with the LLM, and create the draft on the remote site via HTTP API.
   *   Requires environment variables: NEWSLETTER_REMOTE_URL,
   *   NEWSLETTER_REMOTE_USER, NEWSLETTER_REMOTE_PASS.
   * @usage dynasty-newsletter:generate
   *   Generate a newsletter draft locally using AI curation if configured.
   * @usage dynasty-newsletter:generate --no-ai
   *   Generate a newsletter draft without AI curation.
   * @usage dynasty-newsletter:generate --remote
   *   Fetch news from the live site, curate with local LLM, create draft on live site.
   */
  public function generate(array $options = ['no-ai' => FALSE, 'remote' => FALSE]) {
    $skip_ai = (bool) $options['no-ai'];
    $remote = (bool) $options['remote'];

    if ($remote) {
      return $this->generateRemote($skip_ai);
    }

    if ($skip_ai) {
      $this->output()->writeln('AI curation disabled via --no-ai flag.');
    }

    try {
      $html = $this->contentBuilder->buildNewsletterContent(['skip_ai' => $skip_ai]);

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
        'status' => 0,
      ]);
      $newsletter->save();

      $this->output()->writeln('Newsletter generated successfully: ' . $newsletter->id());
      $this->output()->writeln('Title: ' . $newsletter->getTitle());
      $this->output()->writeln('Edit: ' . $newsletter->toUrl('edit-form', ['absolute' => TRUE])->toString());
    }
    catch (\Exception $e) {
      $this->output()->writeln('Failed to generate newsletter: ' . $e->getMessage());
      $this->logger()->error('Newsletter generation failed: @message', ['@message' => $e->getMessage()]);
      return DrushCommands::EXIT_FAILURE;
    }

    return DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Fetch news from the remote site, curate with local LLM, create draft remotely.
   */
  protected function generateRemote(bool $skip_ai): int {
    $remote_url = rtrim(getenv('NEWSLETTER_REMOTE_URL') ?: '', '/');
    $remote_user = getenv('NEWSLETTER_REMOTE_USER') ?: '';
    $remote_pass = getenv('NEWSLETTER_REMOTE_PASS') ?: '';

    if (empty($remote_url) || empty($remote_user) || empty($remote_pass)) {
      $this->output()->writeln('<error>Missing required environment variables: NEWSLETTER_REMOTE_URL, NEWSLETTER_REMOTE_USER, NEWSLETTER_REMOTE_PASS</error>');
      return DrushCommands::EXIT_FAILURE;
    }

    $config = \Drupal::config('dynasty_newsletter.settings');
    $pool_size = (int) ($config->get('llm_news_pool_size') ?? 20);
    $news_limit = (int) ($config->get('news_items_limit') ?? 5);

    $auth = [$remote_user, $remote_pass];

    // Step 1: Fetch news pool from live site.
    $this->output()->writeln("Fetching news items from {$remote_url}...");
    try {
      $response = $this->httpClient->get("{$remote_url}/api/newsletter/news-items", [
        'auth' => $auth,
        'query' => ['limit' => $pool_size],
        'timeout' => 15,
      ]);
      $news_items = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->output()->writeln('<error>Failed to fetch news items: ' . $e->getMessage() . '</error>');
      return DrushCommands::EXIT_FAILURE;
    }

    if (empty($news_items)) {
      $this->output()->writeln('<comment>No news items returned from remote site. The newsletter will have an empty news section.</comment>');
      $news_items = [];
    }
    else {
      $this->output()->writeln('Fetched ' . count($news_items) . ' news items.');
    }

    // Step 2: Curate and summarize with local LLM.
    if (!$skip_ai && !empty($news_items) && $this->aiService->isEnabled()) {
      $this->output()->writeln('Running LLM curation...');
      $news_items = $this->aiService->curateAndSummarizeNews($news_items, $news_limit);
      $this->output()->writeln('LLM selected ' . count($news_items) . ' items.');
    }
    elseif ($skip_ai) {
      $this->output()->writeln('AI curation skipped (--no-ai).');
      $news_items = array_slice($news_items, 0, $news_limit);
    }
    else {
      $news_items = array_slice($news_items, 0, $news_limit);
    }

    // Step 3: POST curated items to live site to create the draft.
    $this->output()->writeln('Creating draft on remote site...');
    try {
      $response = $this->httpClient->post("{$remote_url}/api/newsletter/create-draft", [
        'auth' => $auth,
        'json' => ['news_items' => $news_items],
        'timeout' => 60,
      ]);
      $result = json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->output()->writeln('<error>Failed to create remote draft: ' . $e->getMessage() . '</error>');
      return DrushCommands::EXIT_FAILURE;
    }

    if (!empty($result['edit_url'])) {
      $this->output()->writeln('Draft created successfully!');
      $this->output()->writeln('Title: ' . ($result['title'] ?? 'Unknown'));
      $this->output()->writeln('Edit:  ' . $result['edit_url']);
    }
    else {
      $this->output()->writeln('<error>Unexpected response from remote site.</error>');
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
      if (!$podcast->get('field_publication_date')->isEmpty()) {
        continue;
      }

      $publication_date = NULL;

      if (!$podcast->get('field_game')->isEmpty()) {
        $game = $podcast->get('field_game')->entity;
        if ($game && !$game->get('field_date')->isEmpty()) {
          $publication_date = $game->get('field_date')->value;
        }
      }

      if (!$publication_date) {
        $created_timestamp = $podcast->getCreatedTime();
        $publication_date = date('Y-m-d', $created_timestamp);
      }

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
