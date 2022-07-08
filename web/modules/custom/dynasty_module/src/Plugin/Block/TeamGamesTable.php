<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;
use Drupal\dynasty_module\DynastyHelpers;

/**
 * Provides a Block that displays games from this date in history.
 *
 * @Block(
 *   id = "team_games_table",
 *   admin_label = @Translation("Team Games Table"),
 *   category = @Translation("Dynasty"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *    }
 * )
 */
class TeamGamesTable extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $team = $this->getContextValue('node');
    $anonymous = \Drupal::currentUser()->isAnonymous();
    // Get opponent team info for block display.
    $opp['full'] = $team->label();
    $opp['css'] = strtolower(str_replace(' ', '-', $team->label()));
    $name_array = explode(' ', $team->label());
    $opp['short'] = end($name_array);

    $coaches = DynastyHelpers::get_term_names('head_coaches');
    // Get all games for this team node.
    $game_nodes = \Drupal::entityQuery('node')
      ->condition('type', 'game')
      ->condition('status', 1)
      ->condition('field_opponent', $team->id())
      ->condition('field_season', 1999, '>')
      ->execute();
    $games = [];
    $totals = [
      'pf' => 0,
      'pa' => 0,
      'games' => 0,
      'w' => 0,
      'l' => 0
    ];
    $g = 0;
    $offset = [];
    foreach (Node::loadMultiple($game_nodes) as $game) {
      $games[$game->id()] = [
        'title' => $game->label(),
        'home_away' => $game->get('field_home_away')->value,
        'date' => $game->get('field_date')->value,
        'month' => $game->get('field_month')->value,
        'opp_score' => $game->get('field_opponent_score')->value,
        'pats_score' => $game->get('field_patriots_score')->value,
        'result' => $game->get('field_result')->value,
        'opp_coach' => $coaches[$game->get('field_opposing_coach')->target_id],
        'over_under' => $game->get('field_over_under')->value,
        'vegas_line' => $game->get('field_vegas_line')->value,
      ];

      // If the user is logged in, add the video icon.
      if ($anonymous === FALSE) {
        if (!$game->field_game_video->isEmpty()) {
          $games[$game->id()]['video'] = 1;
        }
        else {
          $games[$game->id()]['video'] = 0;
        }
      }

      $totals['games'] += 1;
      $totals['pf'] += $game->get('field_patriots_score')->value;
      $totals['pa'] += $game->get('field_opponent_score')->value;
      if ($game->get('field_result')->value == 'Win') {
        $totals['w'] += 1;
        $g++;
        $offset[] = $g;
      }
      else {
        $totals['l'] += 1;
        $g--;
        $offset[] = $g;
      }
    }

    // Get all highlights for these game nodes.
    $highlights = \Drupal::entityQuery('node')
      ->condition('type', 'highlight')
      ->condition('status', 1)
      ->condition('field_game', array_keys($game_nodes), 'IN')
      ->execute();

    // Set game highlights field to true if there's a match.
    foreach (Node::loadMultiple($highlights) as $highlight) {
      $g = $highlight->get('field_game')->target_id;
      $games[$g]['highlights'] = TRUE;
    }

    return [
      '#theme' => 'team_games_table',
      '#games' => $games,
      '#totals' => $totals,
      '#opp' => $opp,
      '#offset' => $offset
    ];
  }

}
