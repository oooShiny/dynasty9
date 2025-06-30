<?php
/**
 * @file
 * Contains Drupal\dynasty\Form\MapHighlightsToGamesForm.
 */
namespace Drupal\dynasty_module\Form;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Utility\Error;
use Drupal\node\Entity\Node;
use GuzzleHttp\Exception\RequestException;

class YoutubeHighlightVideoEmbedForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return [
      'dynasty.youtubehighlights',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'youtube_highlights_embed_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['instructions'] = [
      '#type' => 'item',
      '#markup' => '<p class="messages messages--status">
                    Import videos from muse.ai that have been copied from youtube
                    by clicking the <strong>Save configuration</strong> button below.
                    </p>'
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    // Get videos from Muse.ai.
    $muse_vids = $this->get_muse_videos('figxfpq');

    // Get list of games in Drupal.
    $game_links = [];
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'game')
      ->execute();
    foreach (Node::loadMultiple($nids) as $game) {
      // If we have a YouTube highlight link, but no local URL, add the video.
      if (!$game->get('field_youtube_highlights')->isEmpty()
        && $game->get('field_highlight_video_url')->isEmpty()) {
        // Get the YouTube ID from the video link.
        $youtube_query = parse_url($game->get('field_youtube_highlights')->uri, PHP_URL_QUERY);
        // Find the ID in the title of the muse video.
        $yt_id = ltrim($youtube_query, 'v=');

        // Add the muse video URL to the game.
        $video = $muse_vids[$yt_id];
        $game->field_highlight_video_url = $muse_vids[$yt_id];
        $game->save();
      }
    }
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
    catch (RequestException $exception) {
      $logger = \Drupal::logger('highlights');
      Error::logException($logger, $exception);
    }
    $json = json_decode($data);
    $videos = [];
    foreach ($json->videos as $video) {
      $title_array = explode('[', $video->title);
      $yt_id = rtrim($title_array[1], ']');
      $videos[$yt_id] = $video->svid;
    }
    return $videos;
  }
}

