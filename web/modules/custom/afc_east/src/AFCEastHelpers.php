<?php

namespace Drupal\afc_east;

class AFCEastHelpers {

  /**
   * Get all pre-2002 divisions for teams that moved.
   */
  public static function old_divisions() {
    return [
      'arizona-cardinals' => ['conference' => 'NFC', 'division' => 'East'],
      'indianapolis-colts' => ['conference' => 'AFC', 'division' => 'East'],
      'seattle-seahawks' => ['conference' => 'AFC', 'division' => 'West'],
      'new-orleans-saints'=> ['conference' => 'NFC', 'division' => 'West'],
      'tennessee-titans'=> ['conference' => 'AFC', 'division' => 'Central'],
      'baltimore-ravens'=> ['conference' => 'AFC', 'division' => 'Central'],
      'pittsburgh-steelers'=> ['conference' => 'AFC', 'division' => 'Central'],
      'jacksonville-jaguars'=> ['conference' => 'AFC', 'division' => 'Central'],
      'cincinnati-bengals'=> ['conference' => 'AFC', 'division' => 'Central'],
      'cleveland-browns'=> ['conference' => 'AFC', 'division' => 'Central'],
      'minnesota-vikings'=> ['conference' => 'NFC', 'division' => 'Central'],
      'tampa-bay-buccaneers'=> ['conference' => 'NFC', 'division' => 'Central'],
      'green-bay-packers'=> ['conference' => 'NFC', 'division' => 'Central'],
      'detroit-lions'=> ['conference' => 'NFC', 'division' => 'Central'],
      'chicago-bears'=> ['conference' => 'NFC', 'division' => 'Central'],
    ];
  }

  /**
   * Returns an array of all records by team, organized by conference and division.
   */
  public static function get_records() {
    $team_nids = Drupal::entityQuery('node')->condition('type','team')->execute();
    $team_nodes =  \Drupal\node\Entity\Node::loadMultiple($team_nids);

    $divisions = get_terms('division');
    $conferences = get_terms('conference');
    $records = [];

    $old_divisions = old_divisions();
    foreach ($team_nodes as $node) {
      $team_name = preg_replace('@[^a-z0-9-]+@','-', strtolower($node->getTitle()));
      $w = 0;
      $l = 0;
      $t = 0;
      // Get each Standing paragraph and total up yearly standings.
      $standings = $node->field_team_standings->getValue();
      foreach ($standings as $year) {
        $conf = $conferences[$node->get('field_conference')->target_id];
        $div = $divisions[$node->get('field_division')->target_id];
        $p = \Drupal\paragraphs\Entity\Paragraph::load($year['target_id'] );
        $w += $p->get('field_team_wins')->value;
        $l += $p->get('field_team_losses')->value;
        $t += $p->get('field_team_ties')->value;

        // Check if this was before the 2002 division restructure.
        if (in_array($p->get('field_season')->value, ['2000', '2001']) &&
          array_key_exists($team_name, $old_divisions)) {
          $conf = $old_divisions[$team_name]['conference'];
          $div = $old_divisions[$team_name]['division'];
        }

        if ($p->get('field_division_winner')->value == 1) {
          $records[$conf . ' ' . $div]['winner']['w'] += $p->get('field_team_wins')->value;
          $records[$conf . ' ' . $div]['winner']['l'] += $p->get('field_team_losses')->value;
          $records[$conf . ' ' . $div]['winner']['t'] += $p->get('field_team_ties')->value;
        }
        // Add totals to conference and division numbers.
        $records[$conf . ' ' . $div]['w'] += $p->get('field_team_wins')->value;
        $records[$conf . ' ' . $div]['l'] += $p->get('field_team_losses')->value;
        $records[$conf . ' ' . $div]['t'] += $p->get('field_team_ties')->value;
      }
      $records['teams'][$team_name]['div'] = $conf . ' ' . $div;
      $records['teams'][$team_name]['w'] = $w;
      $records['teams'][$team_name]['l'] = $l;
      $records['teams'][$team_name]['t'] = $t;
      $records['teams'][$team_name]['pct'] = $w / ($w + $l + $t);

    }
    return $records;
  }

  public static function get_10_win_teams() {
    $team_nids = Drupal::entityQuery('node')->condition('type','team')->execute();
    $team_nodes =  \Drupal\node\Entity\Node::loadMultiple($team_nids);

    $divisions = get_terms('division');
    $conferences = get_terms('conference');
    $old_divisions = old_divisions();
    $records = [];

    foreach ($team_nodes as $node) {
      $team_name = preg_replace('@[^a-z0-9-]+@','-', strtolower($node->getTitle()));
      // Get each Standing paragraph and total up yearly standings.
      $standings = $node->field_team_standings->getValue();
      foreach ($standings as $year) {
        $conf = $conferences[$node->get('field_conference')->target_id];
        $div = $divisions[$node->get('field_division')->target_id];

        $p = \Drupal\paragraphs\Entity\Paragraph::load($year['target_id'] );
        $w = $p->get('field_team_wins')->value;
        $s = $p->get('field_season')->value;

        // Check if this was before the 2002 division restructure.
        if (in_array($p->get('field_season')->value, ['2000', '2001']) &&
          array_key_exists($team_name, $old_divisions)) {
          $conf = $old_divisions[$team_name]['conference'];
          $div = $old_divisions[$team_name]['division'];
        }

        if ($w > 9) {
          $records[$conf . ' ' . $div][$s] += 1;
        }
      }
    }
    $winners = [];
    foreach ($records as $div => $seasons) {
      foreach ($seasons as $season => $wins) {
        if ($wins > 1) {
          $winners[$div]['seasons'][] = $season;
          $winners[$div]['count'] += 1;
        }
        elseif ($div == 'AFC East' && $season !== '2002') {
          $winners[$div]['seasons'][] = $season;
          $winners[$div]['count'] += 1;
        }
      }

    }
    // Sort by number of seasons (descending).
    uasort($winners, function($a, $b) {
      return $b['count'] <=> $a['count'];
    });
    // Sort actual seasons for each division.
    foreach ($winners as &$division) {
      usort($division['seasons'], function($a, $b) {
        return $a <=> $b;
      });
    }
    return $winners;
  }

}
