<?php

namespace Drupal\dynasty_plays\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dynasty_plays\Service\GamePlayerMatcher;
use Drush\Commands\DrushCommands;

/**
 * Drush command linking `pbp_play` rows to their `highlight` node, where one
 * can be identified unambiguously.
 *
 * `highlight` nodes are hand-curated content (a game, quarter, down,
 * distance, and the players involved), independent of `pbp_play`'s raw
 * play-by-play import. This command is the bridge between the two: given a
 * highlight's structured fields, it looks for a single matching `pbp_play`
 * row and records the link on `pbp_play.pbp_highlight` (the direction the
 * rest of the codebase already reads from -- see
 * SearchDataController::stats()/playByPlay()).
 *
 * Matching is deliberately conservative, mirroring
 * \Drupal\dynasty_plays\Service\GamePlayerMatcher's existing
 * "only match when unambiguous" philosophy: game+quarter+down+distance
 * alone often isn't enough (common situations like "1st & 10" repeat many
 * times within a single game), so when it doesn't uniquely resolve, the
 * player(s) tagged on the highlight are used as a tie-break against
 * ::matchPlayerInDetail()'s free-text detection on each candidate's
 * pbp_detail -- never as a hard filter, since only 44% of pbp_play rows
 * have pbp_player set at import time. Anything still ambiguous, or missing
 * the structured fields needed to even attempt a match, is left unmatched.
 */
class HighlightMatchCommands extends DrushCommands {

  /**
   * Maps `highlight.field_quarter`'s integer value onto `pbp_play`'s
   * `pbp_quarter` string.
   */
  const QUARTER_MAP = [
    1 => 'Q1',
    2 => 'Q2',
    3 => 'Q3',
    4 => 'Q4',
    5 => 'OT',
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The shared game/player matcher.
   *
   * @var \Drupal\dynasty_plays\Service\GamePlayerMatcher
   */
  protected $matcher;

  /**
   * Constructs a new HighlightMatchCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\dynasty_plays\Service\GamePlayerMatcher $matcher
   *   The shared game/player matcher.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, GamePlayerMatcher $matcher) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->matcher = $matcher;
  }

  /**
   * Link `pbp_play` rows to their matching `highlight` node.
   *
   * Safe to re-run: rows that already have `pbp_highlight` set (whether
   * from a previous run of this command, or manually curated) are never
   * overwritten, so running this again after new highlights are added only
   * fills in the new ones.
   *
   * @param array $options
   *   Command options.
   *
   * @command dynasty_plays:match-highlights
   * @aliases dpmh
   * @option dry-run Report match counts without saving any pbp_play changes.
   * @usage dynasty_plays:match-highlights --dry-run
   *   Preview how many highlights would match, without writing anything.
   */
  public function match(array $options = ['dry-run' => FALSE]) {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $pbp_storage = $this->entityTypeManager->getStorage('pbp_play');

    $highlight_ids = $node_storage->getQuery()
      ->condition('type', 'highlight')
      ->condition('status', 1)
      ->condition('field_game', NULL, 'IS NOT NULL')
      ->condition('field_quarter', NULL, 'IS NOT NULL')
      ->condition('field_down', NULL, 'IS NOT NULL')
      ->condition('field_distance', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    if (!$highlight_ids) {
      $this->logger()->warning('No highlights with field_game/field_quarter/field_down/field_distance all set.');
      return;
    }

    $player_index = $this->matcher->buildPlayerIndex();

    $matched = 0;
    $already_linked = 0;
    $skipped_ambiguous = 0;
    $skipped_no_candidates = 0;
    $skipped_bad_quarter = 0;

    foreach (array_chunk($highlight_ids, 200) as $slice) {
      foreach ($node_storage->loadMultiple($slice) as $highlight) {
        $game = $highlight->get('field_game')->entity;
        $quarter_value = (int) $highlight->get('field_quarter')->value;
        $down = (int) $highlight->get('field_down')->value;
        $distance = (int) $highlight->get('field_distance')->value;
        $quarter = self::QUARTER_MAP[$quarter_value] ?? NULL;

        if (!$game || !$quarter) {
          $skipped_bad_quarter++;
          continue;
        }

        $candidate_ids = $pbp_storage->getQuery()
          ->condition('status', 1)
          ->condition('pbp_game', $game->id())
          ->condition('pbp_quarter', $quarter)
          ->condition('pbp_down', $down)
          ->condition('pbp_distance', $distance)
          ->accessCheck(FALSE)
          ->execute();

        if (!$candidate_ids) {
          $skipped_no_candidates++;
          continue;
        }

        $candidates = $pbp_storage->loadMultiple($candidate_ids);

        // Already linked to this exact highlight from a previous run --
        // nothing to do, but not a fresh match either.
        $already = FALSE;
        foreach ($candidates as $candidate) {
          if ((int) $candidate->get('pbp_highlight')->target_id === (int) $highlight->id()) {
            $already = TRUE;
            break;
          }
        }
        if ($already) {
          $already_linked++;
          continue;
        }

        // Never overwrite a row that already points at a *different*
        // highlight (manual curation or an earlier run takes precedence).
        $candidates = array_filter($candidates, function ($candidate) {
          return $candidate->get('pbp_highlight')->isEmpty();
        });

        if (!$candidates) {
          $skipped_no_candidates++;
          continue;
        }

        $winner = NULL;
        if (count($candidates) === 1) {
          $winner = reset($candidates);
        }
        else {
          $involved_player_ids = array_map(
            fn ($player) => (int) $player->id(),
            $highlight->get('field_players_involved')->referencedEntities()
          );

          if ($involved_player_ids) {
            $narrowed = array_filter($candidates, function ($candidate) use ($involved_player_ids, $player_index) {
              $detected = $this->matcher->matchPlayerInDetail((string) $candidate->get('pbp_detail')->value, $player_index);
              return $detected && in_array($detected, $involved_player_ids, TRUE);
            });

            if (count($narrowed) === 1) {
              $winner = reset($narrowed);
            }
          }
        }

        if (!$winner) {
          $skipped_ambiguous++;
          continue;
        }

        $matched++;
        if (!$options['dry-run']) {
          $winner->set('pbp_highlight', $highlight->id());
          $winner->save();
        }
      }
    }

    $this->logger()->success(sprintf(
      '%s %d highlights to a pbp_play row. Already linked: %d. Skipped (ambiguous): %d. Skipped (no candidate rows): %d. Skipped (missing/unmapped quarter or game): %d.',
      $options['dry-run'] ? 'Would match' : 'Matched',
      $matched,
      $already_linked,
      $skipped_ambiguous,
      $skipped_no_candidates,
      $skipped_bad_quarter
    ));
  }

}
