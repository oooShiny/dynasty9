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
    $nids = \Drupal::entityQuery('node')->condition('type','game')->execute();
    $games = Node::loadMultiple($nids);
    $empty_months = [
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

      $months[$month][$day][] = [
        'nid' => \Drupal::service('path_alias.manager')->getAliasByPath('/node/'.$game->id()),
        'title' => $game->getTitle(),
        'result' => strtolower($game->get('field_result')->value)
      ];
    }
    $month_names = [
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
