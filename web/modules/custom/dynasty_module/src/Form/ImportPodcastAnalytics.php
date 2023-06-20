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

class ImportPodcastAnalytics extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return [
      'dynasty.podcastanalytics',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'import_podcast_analytics_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {

    $form['instructions'] = [
      '#type' => 'item',
      '#markup' => '<p class="messages messages--status">Import podcast analytics by uploading a monthly episode listen
count and clicking the <strong>Save configuration</strong> button below.</p>'
    ];

    $form['podcast_csv'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Analytics CSV file'),
      '#upload_location' => 'public://csv',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitForm($form, $form_state);

    $form_file = $form_state->getValue('podcast_csv', 0);
    // Save the CSV file.
    $filename = '';
    if (isset($form_file[0]) && !empty($form_file[0])) {
      $file = File::load($form_file[0]);
      $file->setPermanent();
      $file->save();
      $uri = $file->getFileUri();
      $filename = $file->getFilename();


      // Compile downloads list from CSV.
      $stats = [];
      // Get month and year from CSV filename.
      $year = substr($filename, 0, 4);
      $month = ltrim(substr($filename, 5, 2), '0');

      $stats = $this->parse_csv($uri);

      $operations = [];
      $pods = \Drupal::entityQuery('node')->condition('type', 'podcast_episode')->execute();
      foreach ($pods as $id => $pod) {
        $operations[] = ['\Drupal\dynasty_module\PodcastNodeUpdate::updateNode', [$id, $stats, $month, $year]];
      }
    }
    $batch = [
      'title' => 'Importing Podcast Analytics',
      'operations' => $operations,
      'progress_message' => 'Processed @current out of @total.',
    ];
    batch_set($batch);
  }

  private function parse_csv($csv) {
    $stats = [];
    $row = 1;
    if (($handle = fopen($csv, "r")) !== FALSE) {
      // Go through each row of the CSV.
      while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        if ($row !== 1) {
          $cells = explode(';', $data[0]);
          $stats[$cells[0]] = $cells[2];
        }
        $row++;
      }
      return $stats;
    }
  }
}
