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

    $form['instructions'] = [
      '#type' => 'item',
      '#markup' => '<p class="messages messages--status">
        Import podcast transcripts by clicking the
        <strong>Save configuration</strong> button below.</p>'
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitForm($form, $form_state);

    $operations = [];
    $podcasts = [];
    $pods = \Drupal::entityQuery('node')
      ->condition('type', 'podcast_episode')
      ->execute();
    foreach (Node::loadMultiple($pods) as $pod) {
      $podcasts[$pod->label()] = $pod->id();
    }

    // Get list of transcript csv files.
    $folder = '/var/www/html/web/sites/default/files/transcripts';
    $csvs = \Drupal::service('file_system')->scanDirectory($folder, '/.*/');
    foreach ($csvs as $csv) {
      // Figure out which episode this is.
      $file = $csv->name; // "2001-week-1" or "2003-afc-championship"
      $file_title = str_replace('-', ' ', $file);
      $pod_id = '';
      foreach ($podcasts as $title => $id) {
        if (str_contains(strtolower($title), $file_title . ':')) {
          $pod_id = $id;
          // Get all transcript lines from CSV.
          $transcript = $this->parse_csv($csv->uri);
          foreach ($transcript as $line) {
            // Add operation to batch.
            $operations[] = ['\Drupal\dynasty_module\PodcastNodeUpdate::createTranscriptLine', [$pod_id, $line]];
          }
        }
      }
    }

    $batch = [
      'title' => 'Importing Podcast Transcript Lines',
      'operations' => $operations,
      'progress_message' => 'Processed @current out of @total.',
    ];
    batch_set($batch);
  }

  private function parse_csv($csv) {
    $lines = [];
    $row = 1;
    if (($handle = fopen($csv, "r")) !== FALSE) {
      // Go through each row of the CSV.
      while (($data = fgetcsv($handle, 0, ";")) !== FALSE) {
        if ($row !== 1) {
          $lines[] = [
            'start' => $data[0],
            'end' => $data[1],
            'text' => $data[2],
          ];
        }
        $row++;
      }
      return $lines;
    }
  }
}
