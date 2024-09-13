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
      ->accessCheck(FALSE)
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
      $covered_spread = FALSE;
      $pats_score = $game->get('field_patriots_score')->value;
      $opp_score = $game->get('field_opponent_score')->value;
      $diff = $pats_score - $opp_score;
      $vegas = $game->get('field_vegas_line')->value;

      if ($vegas < 0 && $diff > abs($vegas) // If the Pats are favored and they won by more than the spread.
        || $vegas > 0 && abs($diff) < $vegas) { // Pats not favored, but diff is less than the spread.
        $covered_spread = TRUE;
      }

      $over = FALSE;
      $o_u = $game->get('field_over_under')->value;
      if (($opp_score + $pats_score) > $o_u) {
        $over = TRUE;
      }


      $games[$game->id()] = [
        'title' => $game->label(),
        'home_away' => $game->get('field_home_away')->value,
        'date' => $game->get('field_date')->value,
        'month' => $game->get('field_month')->value,
        'opp_score' => $opp_score,
        'pats_score' => $pats_score,
        'result' => $game->get('field_result')->value,
        'opp_coach' => $coaches[$game->get('field_opposing_coach')->target_id],
        'over_under' => $o_u,
        'vegas_line' => $vegas,
        'spread' => $covered_spread,
        'over' => $over
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
      ->accessCheck(FALSE)
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
