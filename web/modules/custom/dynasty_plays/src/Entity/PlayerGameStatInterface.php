<?php

namespace Drupal\dynasty_plays\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for defining Player Game Stat entities.
 *
 * @ingroup dynasty_plays
 */
interface PlayerGameStatInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Gets the Player Game Stat name.
   *
   * @return string
   *   Name of the Player Game Stat.
   */
  public function getName();

  /**
   * Sets the Player Game Stat name.
   *
   * @param string $name
   *   The Player Game Stat name.
   *
   * @return \Drupal\dynasty_plays\Entity\PlayerGameStatInterface
   *   The called Player Game Stat entity.
   */
  public function setName($name);

  /**
   * Gets the Player Game Stat creation timestamp.
   *
   * @return int
   *   Creation timestamp of the Player Game Stat.
   */
  public function getCreatedTime();

  /**
   * Sets the Player Game Stat creation timestamp.
   *
   * @param int $timestamp
   *   The Player Game Stat creation timestamp.
   *
   * @return \Drupal\dynasty_plays\Entity\PlayerGameStatInterface
   *   The called Player Game Stat entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Returns the Player Game Stat published status indicator.
   *
   * @return bool
   *   TRUE if the Player Game Stat is published.
   */
  public function isPublished();

  /**
   * Sets the published status of a Player Game Stat.
   *
   * @param bool $published
   *   TRUE to set this Player Game Stat to published, FALSE to set it to
   *   unpublished.
   *
   * @return \Drupal\dynasty_plays\Entity\PlayerGameStatInterface
   *   The called Player Game Stat entity.
   */
  public function setPublished($published);

}
