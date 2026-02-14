<?php

namespace Drupal\dynasty_transcript\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for mapping transcript files to podcast episodes.
 */
class TranscriptMappingForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a TranscriptMappingForm object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    ClientInterface $http_client
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dynasty_transcript_mapping_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $transcripts_dir = \Drupal::service('extension.list.module')->getPath('dynasty_transcript') . '/transcripts';
    $metadata = $this->getPodcastMetadata();

    // Build lookup of metadata by transcript_url
    $metadata_by_url = [];
    $metadata_by_title = [];
    foreach ($metadata as $episode) {
      if (!empty($episode['transcript_url'])) {
        $metadata_by_url[$episode['transcript_url']] = $episode;
      }
      $metadata_by_title[$episode['title']] = $episode;
    }

    // Get unmatched transcript files (files with no matching metadata)
    $transcript_files = glob($transcripts_dir . '/*.json');
    $unmatched_files = [];

    foreach ($transcript_files as $file) {
      $filename = basename($file);
      if ($filename === '.DS_Store') {
        continue;
      }
      if (!isset($metadata_by_url[$filename])) {
        $unmatched_files[$filename] = $filename;
      }
    }

    // Get episodes that can be mapped:
    // 1. Episodes without transcript_url
    // 2. Episodes whose transcript_url file doesn't exist
    $available_episodes = [];
    foreach ($metadata as $episode) {
      if (empty($episode['title'])) {
        continue;
      }

      $title = $episode['title'];
      $current_url = $episode['transcript_url'] ?? '';

      if (empty($current_url)) {
        // No transcript_url set
        $available_episodes[$title] = $title . ' (no transcript set)';
      }
      else {
        // Check if the file exists
        $filepath = $transcripts_dir . '/' . $current_url;
        if (!file_exists($filepath)) {
          // File doesn't exist - can be remapped
          $available_episodes[$title] = $title . ' (file missing: ' . $current_url . ')';
        }
      }
    }

    if (empty($unmatched_files)) {
      $form['no_unmatched'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--status">All transcript files have metadata matches!</div>',
      ];
      return $form;
    }

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>Map unmatched transcript files to episodes. This will rename the JSON file to match the expected filename pattern based on the episode title.</p>',
    ];

    $form['mappings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Map Transcript Files'),
      '#tree' => TRUE,
    ];

    // Sort files for better UX
    ksort($unmatched_files);

    foreach ($unmatched_files as $filename) {
      $form['mappings'][$filename] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mapping-row']],
      ];

      $form['mappings'][$filename]['file'] = [
        '#type' => 'markup',
        '#markup' => '<strong>' . $filename . '</strong>',
        '#prefix' => '<div class="mapping-file" style="display: inline-block; width: 300px; margin-right: 20px;">',
        '#suffix' => '</div>',
      ];

      $options = ['' => '- Select Episode -'] + $available_episodes;

      // Try to suggest a match based on filename
      $suggested = $this->suggestMatch($filename, array_keys($available_episodes));

      $form['mappings'][$filename]['episode'] = [
        '#type' => 'select',
        '#options' => $options,
        '#default_value' => $suggested,
        '#prefix' => '<div class="mapping-episode" style="display: inline-block; width: 400px;">',
        '#suffix' => '</div>',
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply Mappings'),
    ];

    // Add some basic styling
    $form['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
          .mapping-row {
            padding: 10px 0;
            border-bottom: 1px solid #ccc;
            display: flex;
            align-items: center;
          }
          .mapping-row:last-child { border-bottom: none; }
        ',
      ],
      'transcript-mapping-styles',
    ];

    return $form;
  }

  /**
   * Try to suggest a match based on filename similarity.
   */
  protected function suggestMatch(string $filename, array $episode_titles): string {
    // Remove extension and convert to comparable format
    $file_slug = strtolower(str_replace(['.json', '-', '_'], [' ', ' ', ' '], $filename));
    $file_slug = preg_replace('/\s+/', ' ', trim($file_slug));

    $best_match = '';
    $best_score = 0;

    foreach ($episode_titles as $title) {
      // Create comparable version of title
      $title_slug = strtolower($title);
      $title_slug = preg_replace('/[^a-z0-9\s]/', ' ', $title_slug);
      $title_slug = preg_replace('/\s+/', ' ', trim($title_slug));

      // Simple word matching score
      $file_words = explode(' ', $file_slug);
      $title_words = explode(' ', $title_slug);
      $matches = count(array_intersect($file_words, $title_words));

      // Also check for year-week pattern matching
      if (preg_match('/(\d{4})\s*week\s*(\d+)/', $file_slug, $file_match) &&
          preg_match('/(\d{4})\s*week\s*(\d+)/', $title_slug, $title_match)) {
        if ($file_match[1] === $title_match[1] && $file_match[2] === $title_match[2]) {
          $matches += 5; // Boost score for year+week match
        }
      }

      if ($matches > $best_score) {
        $best_score = $matches;
        $best_match = $title;
      }
    }

    // Only suggest if we have a reasonable match
    return $best_score >= 2 ? $best_match : '';
  }

  /**
   * Generate a filename from episode title.
   */
  protected function generateFilename(string $title): string {
    // Convert to lowercase
    $filename = strtolower($title);
    // Replace common patterns
    $filename = preg_replace('/[^a-z0-9\s\-]/', '', $filename);
    // Replace spaces with hyphens
    $filename = preg_replace('/\s+/', '-', trim($filename));
    // Remove double hyphens
    $filename = preg_replace('/-+/', '-', $filename);
    // Add extension
    return $filename . '.json';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $transcripts_dir = \Drupal::service('extension.list.module')->getPath('dynasty_transcript') . '/transcripts';
    $mappings = $form_state->getValue('mappings');
    $renamed_count = 0;

    foreach ($mappings as $old_filename => $mapping) {
      $episode_title = $mapping['episode'] ?? '';

      if (empty($episode_title)) {
        continue;
      }

      $new_filename = $this->generateFilename($episode_title);
      $old_path = $transcripts_dir . '/' . $old_filename;
      $new_path = $transcripts_dir . '/' . $new_filename;

      if (!file_exists($old_path)) {
        $this->messenger()->addWarning($this->t('File not found: @file', ['@file' => $old_filename]));
        continue;
      }

      if (file_exists($new_path) && $old_path !== $new_path) {
        $this->messenger()->addWarning($this->t('Target file already exists: @file', ['@file' => $new_filename]));
        continue;
      }

      if (rename($old_path, $new_path)) {
        $this->messenger()->addStatus($this->t('Renamed @old to @new', [
          '@old' => $old_filename,
          '@new' => $new_filename,
        ]));
        $renamed_count++;
      }
      else {
        $this->messenger()->addError($this->t('Failed to rename @file', ['@file' => $old_filename]));
      }
    }

    if ($renamed_count > 0) {
      $this->messenger()->addStatus($this->t('Successfully renamed @count files. You may need to update the podcast metadata to match the new filenames.', [
        '@count' => $renamed_count,
      ]));
    }
  }

  /**
   * Get podcast metadata from the site.
   */
  protected function getPodcastMetadata(): array {
    try {
      $base_url = \Drupal::request()->getSchemeAndHttpHost();
      $response = $this->httpClient->request('GET', $base_url . '/admin/dynasty/podcast-metadata', [
        'timeout' => 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return is_array($data) ? $data : [];
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to fetch metadata: @error', ['@error' => $e->getMessage()]));
      return [];
    }
  }

}
