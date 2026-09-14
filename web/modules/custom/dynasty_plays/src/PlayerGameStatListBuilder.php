<?php

namespace Drupal\dynasty_plays;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * Defines a class to build a listing of Player Game Stat entities.
 *
 * @ingroup dynasty_plays
 */
class PlayerGameStatListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['id'] = $this->t('ID');
    $header['name'] = $this->t('Name');
    $header['game'] = $this->t('Game');
    $header['quarter'] = $this->t('Quarter');
    $header['category'] = $this->t('Category');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\dynasty_plays\Entity\PlayerGameStatInterface $entity */
    $row['id'] = $entity->id();
    $row['name'] = Link::createFromRoute(
      $entity->label(),
      'entity.player_game_stat.edit_form',
      ['player_game_stat' => $entity->id()]
    );

    // Display the referenced game if it exists.
    $game = $entity->get('stat_game')->entity;
    $row['game'] = $game ? $game->label() : $this->t('None');

    $row['quarter'] = $entity->get('stat_quarter')->value;
    $row['category'] = $entity->get('stat_category')->value;

    return $row + parent::buildRow($entity);
  }

}
