<?php

namespace Drupal\dynasty_plays;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * Defines a class to build a listing of Play-by-Play entities.
 *
 * @ingroup dynasty_plays
 */
class PbpPlayListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['id'] = $this->t('ID');
    $header['name'] = $this->t('Name');
    $header['game'] = $this->t('Game');
    $header['quarter'] = $this->t('Quarter');
    $header['down_distance'] = $this->t('Down & Distance');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\dynasty_plays\Entity\PbpPlayInterface $entity */
    $row['id'] = $entity->id();
    $row['name'] = Link::createFromRoute(
      $entity->label(),
      'entity.pbp_play.edit_form',
      ['pbp_play' => $entity->id()]
    );

    $game = $entity->get('pbp_game')->entity;
    $row['game'] = $game ? $game->label() : $this->t('None');

    $row['quarter'] = $entity->get('pbp_quarter')->value;

    $down = $entity->get('pbp_down')->value;
    $distance = $entity->get('pbp_distance')->value;
    $row['down_distance'] = ($down && $distance) ? "$down & $distance" : '';

    return $row + parent::buildRow($entity);
  }

}
