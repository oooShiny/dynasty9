<?php

namespace Drupal\dynasty_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\node;

class PatsCalendarController extends ControllerBase {

  /**
   * Display the markup.
   *
   * @return array
   */
  public function content() {

    // Get all game nodes.
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type','game')
      ->execute();
    $games = Node::loadMultiple($nids);
    $empty_months = [
      '08' => [],
      '09' => [],
      '10' => [],
      '11' => [],
      '12' => [],
      '01' => [],
      '02' => [],
    ];
    $months = $this->populate_month_array($empty_months);
    foreach ($games as $game) {
      $date = $game->get('field_date')->value;
      $month = date('m', strtotime($date));
      $day = date('j', strtotime($date));

      $months[$month][$day]['games'][] = [
        'nid' => \Drupal::service('path_alias.manager')->getAliasByPath('/node/'.$game->id()),
        'title' => $game->getTitle(),
        'result' => strtolower($game->get('field_result')->value)
      ];
      $result = substr(strtolower($game->get('field_result')->value), 0, 1);
      $months[$month][$day]['record'] = [
        'w' => $months[$month][$day]['record']['w'] ?? 0,
        'l' => $months[$month][$day]['record']['l'] ?? 0,
        't' => $months[$month][$day]['record']['t'] ?? 0,
      ];
      switch ($result) {
        case 'w':
          $months[$month][$day]['record']['w'] += 1;
          break;
        case 'l':
          $months[$month][$day]['record']['l'] += 1;
          break;
        case 't':
          $months[$month][$day]['record']['t'] += 1;
          break;
      }
      $months[$month][$day]['record'] = [
        'w' => $months[$month][$day]['record']['w'] ?? 0,
        'l' => $months[$month][$day]['record']['l'] ?? 0,
        't' => $months[$month][$day]['record']['t'] ?? 0,
      ];
      rsort($months[$month][$day]['games']);
    }
    $month_names = [
      '08' => 'August',
      '09' => 'September',
      '10' => 'October',
      '11' => 'November',
      '12' => 'December',
      '01' => 'January',
      '02' => 'February',
    ];
    $build = [
      '#theme' => 'pats_calendar',
      '#months' => $months,
      '#monthnames' => $month_names
    ];
    return $build;
  }

  private function populate_month_array($months) {
    $days = [
      '08' => 31,
      '09' => 30,
      '10' => 31,
      '11' => 30,
      '12' => 31,
      '01' => 31,
      '02' => 29
    ];
    foreach ($months as $month => $day) {
      $count = $days[$month];
      for ($i = 1; $i <= $count; $i++) {
        $months[$month][$i] = [];
      }
    }
    return $months;
  }

  function startsWith($haystack, $needle)
  {
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
  }
}
