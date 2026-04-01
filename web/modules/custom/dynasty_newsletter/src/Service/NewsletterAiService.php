<?php

namespace Drupal\dynasty_newsletter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for AI-powered news curation and summarization.
 */
class NewsletterAiService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a NewsletterAiService object.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('dynasty_newsletter');
    $this->configFactory = $config_factory;
  }

  /**
   * Whether AI curation is enabled and configured.
   *
   * @return bool
   */
  public function isEnabled(): bool {
    $config = $this->configFactory->get('dynasty_newsletter.settings');
    return (bool) $config->get('llm_enabled') && !empty($config->get('llm_api_url'));
  }

  /**
   * Curate and summarize news items using a local LLM.
   *
   * Sends a pool of raw news items to an OpenAI-compatible API and asks the
   * model to select the most relevant ones and write short summaries. Falls
   * back to the original items unchanged on any error.
   *
   * @param array $rawItems
   *   Raw news items from getRecentNews(), each with keys:
   *   title, link, description, source, date.
   * @param int $targetCount
   *   How many items to keep after curation.
   *
   * @return array
   *   Curated items with AI-generated summaries replacing raw descriptions.
   *   Returns $rawItems unchanged if AI is unavailable or fails.
   */
  public function curateAndSummarizeNews(array $rawItems, int $targetCount = 5): array {
    if (empty($rawItems)) {
      return $rawItems;
    }

    $config = $this->configFactory->get('dynasty_newsletter.settings');
    $base_url = rtrim($config->get('llm_api_url') ?? '', '/');
    $model = $config->get('llm_model') ?? 'llama3.2';

    if (empty($base_url)) {
      return $rawItems;
    }

    $prompt = $this->buildPrompt($rawItems, $targetCount);

    try {
      $response = $this->httpClient->post($base_url . '/v1/chat/completions', [
        'json' => [
          'model' => $model,
          'messages' => [
            [
              'role' => 'system',
              'content' => 'You are an editorial assistant for a New England Patriots fan newsletter. Your job is to select the most relevant and interesting news items for Patriots fans and write concise summaries.',
            ],
            [
              'role' => 'user',
              'content' => $prompt,
            ],
          ],
          'temperature' => 0.3,
          'stream' => FALSE,
        ],
        'timeout' => 60,
        'connect_timeout' => 5,
      ]);

      $body = json_decode((string) $response->getBody(), TRUE);
      $content = $body['choices'][0]['message']['content'] ?? '';

      return $this->parseResponse($content, $rawItems, $targetCount);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('LLM request failed, falling back to unprocessed news items: @message', [
        '@message' => $e->getMessage(),
      ]);
      return array_slice($rawItems, 0, $targetCount);
    }
    catch (\Exception $e) {
      $this->logger->warning('LLM processing error, falling back to unprocessed news items: @message', [
        '@message' => $e->getMessage(),
      ]);
      return array_slice($rawItems, 0, $targetCount);
    }
  }

  /**
   * Build the curation prompt.
   */
  protected function buildPrompt(array $items, int $targetCount): string {
    $total = count($items);
    $lines = [];
    foreach ($items as $i => $item) {
      $snippet = mb_substr($item['description'], 0, 200);
      $lines[] = "{$i}. [{$item['source']}] \"{$item['title']}\" — {$snippet}";
    }
    $itemList = implode("\n", $lines);

    return <<<PROMPT
Below are {$total} recent Patriots news items. Select the {$targetCount} most relevant and interesting ones for a Patriots fan newsletter, then write a 1-2 sentence summary for each.

Return ONLY a JSON array using this exact schema — no markdown, no explanation:
[{"index": <original index>, "summary": "<summary text>"}]

Items:
{$itemList}
PROMPT;
  }

  /**
   * Parse the LLM response and map back to original items.
   *
   * @param string $content
   *   Raw text response from the LLM.
   * @param array $rawItems
   *   Original items array for index lookup.
   * @param int $targetCount
   *   Expected number of results.
   *
   * @return array
   *   Processed items, or a slice of $rawItems on parse failure.
   */
  protected function parseResponse(string $content, array $rawItems, int $targetCount): array {
    // Strip markdown code fences if the model wrapped the JSON.
    $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
    $content = preg_replace('/\s*```$/', '', $content);

    $decoded = json_decode(trim($content), TRUE);

    if (!is_array($decoded) || empty($decoded)) {
      $this->logger->warning('LLM returned unparseable response, falling back to original items.');
      return array_slice($rawItems, 0, $targetCount);
    }

    $result = [];
    foreach ($decoded as $entry) {
      $index = $entry['index'] ?? NULL;
      $summary = $entry['summary'] ?? NULL;

      if (!isset($rawItems[$index]) || empty($summary)) {
        continue;
      }

      $item = $rawItems[$index];
      $item['description'] = $summary;
      $result[] = $item;
    }

    if (empty($result)) {
      $this->logger->warning('LLM response contained no valid items, falling back to original items.');
      return array_slice($rawItems, 0, $targetCount);
    }

    $this->logger->info('LLM curated @count news items from a pool of @total.', [
      '@count' => count($result),
      '@total' => count($rawItems),
    ]);

    return $result;
  }

}
