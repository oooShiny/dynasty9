<?php
/**
 * @file
 * Contains Drupal\dynasty\Form\MapHighlightsToGamesForm.
 */
namespace Drupal\dynasty_module\Form;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

class MapHighlightsToGamesForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return [
      'dynasty.maphighlights',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'map_highlights_games_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['instructions'] = [
      '#type' => 'item',
      '#markup' => '<p class="messages messages--status">Map any highlights without an associated game node by clicking the
                    <strong>Save configuration</strong> button below.</p>'
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    // Get list of games in Drupal.
    $game_links = [];
    $nids = \Drupal::entityQuery('node')->condition('type','game')->execute();
    $games =  Node::loadMultiple($nids);
    foreach ($games as $game) {
      $game_links[$game->get('field_season')->value][$game->get('field_week')->target_id] = $game->id();
    }

    // Save the data as new video nodes.
    $operations = [];
    $nids = \Drupal::entityQuery('node')->condition('type','highlight')->execute();
    $videos =  Node::loadMultiple($nids);
    foreach ($videos as $video) {
      if ($video->get('field_game')->isEmpty()) {
        $v_season = $video->get('field_season')->value;
        $v_week = $video->get('field_week')->target_id;
        $game_node = $game_links[$v_season][$v_week];
        $v = [
          'highlight' => $video->id(),
          'game' => $game_node,
        ];
        $operations[] = ['\Drupal\dynasty_module\MapHighlightToGame::updateNode', [$v]];
      }
    }
    $batch = [
      'title' => 'Mapping Highlights to Games',
      'operations' => $operations,
      'progress_message' => 'Processed @current out of @total.',
    ];
    batch_set($batch);
  }
}
