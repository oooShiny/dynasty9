<?php
/**
 * @file
 * Contains Drupal\dynasty\Form\MapHighlightsToGamesForm.
 */
namespace Drupal\dynasty_module\Form;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

class ImportTranscripts extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return [
      'dynasty.podcasttranscripts',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'import_podcast_transcripts_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $episodes = [];
    $storage = \Drupal::service('entity_type.manager')->getStorage('node');
    $ep_nodes = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'podcast_episode')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();

    foreach ($storage->loadMultiple($ep_nodes) as $episode) {
      if ($episode->field_episode_transcript->isEmpty()) {
        $episodes[$episode->id()] = $episode->label();
      }
    }
    $form['instructions'] = [
      '#type' => 'item',
      '#markup' => '<p class="messages messages--status">
        Import podcast transcripts by clicking the
        <strong>Save configuration</strong> button below.</p>'
    ];
    $form['podcast_csv'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Transcript CSV file'),
      '#upload_location' => 'public://csv',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ]
    ];
    $form['podcast_episode'] = [
    '#type' => 'select',
    '#title' => 'Podcast Episode',
    '#options' => $episodes,
    '#required' => true,
    ];

    $form['podcast_speaker'] = [
      '#type' => 'checkbox',
      '#title' => 'Transcript has a speaker column',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitForm($form, $form_state);

    $pod_id = $form_state->getValue('podcast_episode');
    $form_file = $form_state->getValue('podcast_csv', 0);
    $speakers = $form_state->getValue('podcast_speaker');
    // Save the CSV file.
    if (!empty($form_file[0])) {
      $csv = File::load($form_file[0]);
      $csv->setPermanent();
      $csv->save();
      $uri = $csv->getFileUri();

      $operations = [];
      $transcript = $this->parse_csv($uri, $speakers);
      foreach ($transcript as $line) {
        // Add operation to batch.
        $operations[] = ['\Drupal\dynasty_module\PodcastNodeUpdate::createTranscriptLine', [$pod_id, $line]];
      }
    }
    $batch = [
      'title' => 'Importing Podcast Transcript',
      'operations' => $operations,
      'progress_message' => 'Processed @current out of @total.',
    ];
    batch_set($batch);
  }

  private function parse_csv($csv, $speakers = FALSE) {
    $lines = [];
    $row = 1;
    if (($handle = fopen($csv, "r")) !== FALSE) {
      // Go through each row of the CSV.
      while (($data = fgetcsv($handle, 0, ";")) !== FALSE) {
        if ($row !== 1) {
          if ($speakers) {
            $lines[] = [
              'start' => $data[1],
              'end' => $data[2],
              'text' => $data[3],
            ];
          }
          else {
            $lines[] = [
              'start' => $data[0],
              'end' => $data[1],
              'text' => $data[2],
            ];
          }
        }
        $row++;
      }
      return $lines;
    }
    return FALSE;
  }
}
