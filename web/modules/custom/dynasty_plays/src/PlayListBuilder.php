<?php

namespace Drupal\dynasty_plays;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * Defines a class to build a listing of Play entities.
 *
 * @ingroup dynasty_plays
 */
class PlayListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['id'] = $this->t('Play ID');
    $header['name'] = $this->t('Name');
    $header['game'] = $this->t('Game');
    $header['highlight'] = $this->t('Highlight');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\dynasty_plays\Entity\PlayInterface $entity */
    $row['id'] = $entity->id();
    $row['name'] = Link::createFromRoute(
      $entity->label(),
      'entity.play.edit_form',
      ['play' => $entity->id()]
    );

    // Display the referenced game if it exists.
    $game = $entity->get('play_game')->entity;
    if ($game) {
      $row['game'] = $game->label();
    }
    else {
      $row['game'] = $this->t('None');
    }

    // Display the referenced highlight if it exists.
    $highlight = $entity->get('play_highlight')->entity;
    if ($highlight) {
      $row['highlight'] = $highlight->label();
    }
    else {
      $row['highlight'] = $this->t('None');
    }

    return $row + parent::buildRow($entity);
  }

}
