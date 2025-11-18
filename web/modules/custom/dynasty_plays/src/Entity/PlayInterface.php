<?php

namespace Drupal\dynasty_plays\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for defining Play entities.
 *
 * @ingroup dynasty_plays
 */
interface PlayInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Gets the Play name.
   *
   * @return string
   *   Name of the Play.
   */
  public function getName();

  /**
   * Sets the Play name.
   *
   * @param string $name
   *   The Play name.
   *
   * @return \Drupal\dynasty_plays\Entity\PlayInterface
   *   The called Play entity.
   */
  public function setName($name);

  /**
   * Gets the Play creation timestamp.
   *
   * @return int
   *   Creation timestamp of the Play.
   */
  public function getCreatedTime();

  /**
   * Sets the Play creation timestamp.
   *
   * @param int $timestamp
   *   The Play creation timestamp.
   *
   * @return \Drupal\dynasty_plays\Entity\PlayInterface
   *   The called Play entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Returns the Play published status indicator.
   *
   * @return bool
   *   TRUE if the Play is published.
   */
  public function isPublished();

  /**
   * Sets the published status of a Play.
   *
   * @param bool $published
   *   TRUE to set this Play to published, FALSE to set it to unpublished.
   *
   * @return \Drupal\dynasty_plays\Entity\PlayInterface
   *   The called Play entity.
   */
  public function setPublished($published);

}
