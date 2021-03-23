<?php
/**
 * @file
 * Contains Drupal\dynasty_module\Form\GfycatMuseImportForm.
 */
namespace Drupal\dynasty_module\Form;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use GuzzleHttp\Exception\RequestException;

class GfycatMuseImportForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return [
      'dynasty_module.videoimport',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'video_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['instructions'] = [
      '#type' => 'item',
      '#markup' => '<p class="messages messages--status">Import the latest videos from <a href="https://muse.ai/">Muse.ai</a> by clicking the
                    <strong>Save configuration</strong> button below.</p>'
    ];
    $collections = $this->get_muse_collections();

    $form['collection'] = [
      '#type' => 'select',
      '#title' => $this->t('Video Collection'),
      '#description' => $this->t('Select the video collection to pull from.'),
      '#options' => $collections,
      '#empty_value' => ''
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    // Get node defaults from form.
    $fields = $form_state->getValues();
    // TODO: Get the collection from the form.
    $collection = $fields['collection'];
    // Get list of videos from muse.ai.
    $muse_vids = $this->get_muse_videos($collection);

    // Get list of videos in Drupal.
    $vid_links = [];
    $nids = \Drupal::entityQuery('node')->condition('type','highlight')->execute();
    $highlights =  Node::loadMultiple($nids);
    foreach ($highlights as $video) {
      $vid_links[strtolower($video->get('field_gfycat_id')->value)] = $video->id();
    }

    // Save the data as new video nodes.
    $operations = [];
    foreach ($muse_vids->videos as $muse_video) {
      $muse_title = strtolower($muse_video->title);

      if (array_key_exists($muse_title, $vid_links)) {
        $v = [
          'title' => $muse_video->title,
          'muse_id' => $muse_video->svid,
          'nid' => $vid_links[$muse_title],
        ];
        $operations[] = ['\Drupal\dynasty_module\AddMuseHighlight::updateNode', [$v, $fields]];
      }
    }
    $batch = [
      'title' => 'Adding Muse video to Highlight nodes',
      'operations' => $operations,
      'progress_message' => 'Processed @current out of @total.',
    ];
    batch_set($batch);
  }

  private function get_muse_videos($collection) {
    $url = 'https://muse.ai/api/files/collections/' . $collection;
    $client = \Drupal::httpClient();
    $data = NULL;
    try{
      $response = $client->request('GET', $url, [
        'headers' => ['Key' => 'vvjguqSQzGQu9cJXtRg1QfI85f7b9e4f']
      ]);
      $data = $response->getBody()->getContents();
    }
    catch (RequestException $e) {
      watchdog_exception('patsfilm', $e->getMessage());
    }
  return json_decode($data);
  }

  private function get_muse_collections() {
    $url = 'https://muse.ai/api/files/collections';
    $client = \Drupal::httpClient();
    $data = NULL;
    try{
      $response = $client->request('GET', $url, [
        'headers' => ['Key' => 'vvjguqSQzGQu9cJXtRg1QfI85f7b9e4f']
      ]);
      $data = $response->getBody()->getContents();
    }
    catch (RequestException $e) {
      watchdog_exception('dynasty_module', $e->getMessage());
    }
    $collections = [];
    foreach (json_decode($data) as $col) {
      $collections[$col->scid] = $col->name;
    }
    asort($collections);
    return $collections;
  }

  private function _get_terms($taxonomy) {
    $term_array = [];
    $terms =  \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($taxonomy);
    foreach ($terms as $term) {
      $term_array[$term->tid] = $term->name;
    }
    return $term_array;
  }
}
