<?php

namespace Drupal\dynasty_plays\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Shared game/player matching logic for the dynasty_plays CSV importers.
 *
 * Both \Drupal\dynasty_plays\Commands\QuarterlyStatsImportCommands and
 * \Drupal\dynasty_plays\Commands\PlayByPlayImportCommands need to resolve a
 * CSV row's game date to a Game node, and (where a player is involved) a
 * CSV/text player label to a Player node. This service centralizes that
 * logic so it exists in exactly one place.
 */
class GamePlayerMatcher {

  /**
   * Suffixes to strip when comparing surnames.
   *
   * @var string
   */
  const SUFFIX_PATTERN = '/\s+(Jr\.?|Sr\.?|II|III|IV)$/i';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new GamePlayerMatcher object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Builds a map of Game node date ('Y-m-d') to node ID, across all
   * seasons (not just 2000+, since Game nodes already exist back to the
   * 1960s).
   *
   * @return array
   *   Array keyed by date string, valued by node ID.
   */
  public function buildGameDateMap() {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'game')
      ->accessCheck(FALSE)
      ->execute();

    $map = [];
    foreach ($storage->loadMultiple($nids) as $game) {
      if (!$game->get('field_date')->isEmpty()) {
        $date = substr($game->get('field_date')->value, 0, 10);
        $map[$date] = $game->id();
      }
    }
    return $map;
  }

  /**
   * Builds an index of Player nodes keyed by lowercase surname.
   *
   * @return array
   *   Array keyed by lowercase surname, valued by a list of
   *   [nid, lowercase first name] pairs.
   */
  public function buildPlayerIndex() {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'player')
      ->accessCheck(FALSE)
      ->execute();

    $index = [];
    foreach ($storage->loadMultiple($nids) as $player) {
      $title = trim($player->label());
      if ($title === '') {
        continue;
      }
      $tokens = explode(' ', $title);
      $first = array_shift($tokens);
      $last = implode(' ', $tokens);
      $last = trim(preg_replace(self::SUFFIX_PATTERN, '', $last));
      if ($last === '') {
        continue;
      }
      $key = mb_strtolower($last);
      $index[$key][] = [
        'nid' => $player->id(),
        'first' => mb_strtolower($first),
      ];
    }
    return $index;
  }

  /**
   * Attempts to match a CSV player label to a single Player node.
   *
   * Handles both the standard "X. Lastname" format and the truncated
   * disambiguator format used when two players share an initial in the same
   * season (e.g. "Jak.Johnson", "Ma.Jones").
   *
   * @param string $name
   *   The raw player label from the CSV (e.g. "D. Bledsoe").
   * @param array $player_index
   *   The index built by ::buildPlayerIndex().
   *
   * @return int|null
   *   The matched Player node ID, or NULL if no single confident match
   *   could be found.
   */
  public function matchPlayer($name, array $player_index) {
    if ($name === '' || strpos($name, '.') === FALSE) {
      return NULL;
    }

    [$prefix, $rest] = explode('.', $name, 2);
    $prefix = mb_strtolower(trim($prefix));
    $last = trim(preg_replace(self::SUFFIX_PATTERN, '', trim($rest)));
    $key = mb_strtolower($last);

    if ($prefix === '' || $key === '' || empty($player_index[$key])) {
      return NULL;
    }

    $candidates = array_filter($player_index[$key], function ($candidate) use ($prefix) {
      return str_starts_with($candidate['first'], $prefix);
    });

    if (count($candidates) === 1) {
      return reset($candidates)['nid'];
    }

    return NULL;
  }

  /**
   * Attempts to find exactly one confidently-matched Player mentioned in a
   * free-text play description.
   *
   * Unlike ::matchPlayer(), which parses a clean "X. Lastname" CSV column,
   * this scans unstructured text and handles two distinct detail-text
   * styles depending on source era:
   *
   * - Pre-2000 rows spell names out in full prose (e.g. "Steve Grogan pass
   *   complete to Stanley Morgan for 12 yards") -- matched as capitalized
   *   First Last bigrams, resolved against the player index by *exact*
   *   first name (free text spells names out in full, unlike the CSV's
   *   initials).
   * - 2000+ rows use Pro Football Reference's "<jersey>-<Initial(s)>.
   *   <Surname>" box-score shorthand instead (e.g. "9-C.Palmer pass
   *   complete to 81-T.Owens", or a truncated multi-letter initial for
   *   disambiguation like "14-Sh.Hill"). The "Initial(s).Surname" portion
   *   is the exact same shape as the quarterly-stats CSV's player column,
   *   so each token found is resolved by handing it to ::matchPlayer().
   *
   * Both passes accumulate into one set of distinct matched Player node
   * IDs; a match is returned only when exactly one distinct Player node is
   * identified across the whole string, regardless of which pattern(s)
   * found it.
   *
   * This is deliberately conservative: most rows describing a pass (passer
   * + receiver, often plus a parenthetical tackler) will resolve to more
   * than one distinct player and therefore return NULL by design, the same
   * way ::matchPlayer() leaves ambiguous CSV rows unmatched rather than
   * guessing. A low overall hit rate on pass plays is expected, not a bug.
   *
   * Known limitations: common-surname false positives are theoretically
   * possible (rare, given the full-name/initial + single-match
   * requirement); 3+-word surnames aren't matched by the prose pattern
   * (only two-token windows are scanned); nicknames/short first names miss
   * safely (return NULL rather than a wrong guess). Since this only
   * affects import-time population, the heuristic can be improved later
   * without any schema change.
   *
   * @param string $detail
   *   The raw play-by-play detail text.
   * @param array $player_index
   *   The index built by ::buildPlayerIndex().
   *
   * @return int|null
   *   The matched Player node ID, or NULL if zero or more than one
   *   distinct player could be confidently identified.
   */
  public function matchPlayerInDetail($detail, array $player_index) {
    if ($detail === '' || empty($player_index)) {
      return NULL;
    }

    $resolved = [];

    // Pre-2000 style: "First Last" prose bigrams.
    if (preg_match_all('/\b([A-Z][a-zA-Z\'\-]*)\s+([A-Z][a-zA-Z\'\-]*)\b/', $detail, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        [, $first, $last] = $match;
        $key = mb_strtolower($last);
        if (empty($player_index[$key])) {
          continue;
        }

        $first_lower = mb_strtolower($first);
        $candidates = array_filter($player_index[$key], function ($candidate) use ($first_lower) {
          return $candidate['first'] === $first_lower;
        });

        if (count($candidates) === 1) {
          $resolved[reset($candidates)['nid']] = TRUE;
        }
      }
    }

    // 2000+ style: "<jersey>-<Initial(s)>.<Surname>" PFR box-score tokens.
    if (preg_match_all('/\d{1,2}-([A-Za-z]{1,4}\.[A-Za-z\'\-]+)/', $detail, $matches2)) {
      foreach ($matches2[1] as $token) {
        $nid = $this->matchPlayer($token, $player_index);
        if ($nid) {
          $resolved[$nid] = TRUE;
        }
      }
    }

    return count($resolved) === 1 ? array_key_first($resolved) : NULL;
  }

}
