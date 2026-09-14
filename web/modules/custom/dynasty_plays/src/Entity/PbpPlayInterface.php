<?php

namespace Drupal\dynasty_plays\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for defining Play-by-Play entities.
 *
 * @ingroup dynasty_plays
 */
interface PbpPlayInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Gets the Play-by-Play entry's name.
   *
   * @return string
   *   Name of the Play-by-Play entry.
   */
  public function getName();

  /**
   * Sets the Play-by-Play entry's name.
   *
   * @param string $name
   *   The Play-by-Play entry's name.
   *
   * @return \Drupal\dynasty_plays\Entity\PbpPlayInterface
   *   The called Play-by-Play entry.
   */
  public function setName($name);

  /**
   * Gets the Play-by-Play entry's creation timestamp.
   *
   * @return int
   *   Creation timestamp of the Play-by-Play entry.
   */
  public function getCreatedTime();

  /**
   * Sets the Play-by-Play entry's creation timestamp.
   *
   * @param int $timestamp
   *   The Play-by-Play entry's creation timestamp.
   *
   * @return \Drupal\dynasty_plays\Entity\PbpPlayInterface
   *   The called Play-by-Play entry.
   */
  public function setCreatedTime($timestamp);

  /**
   * Returns the Play-by-Play entry's published status indicator.
   *
   * @return bool
   *   TRUE if the Play-by-Play entry is published.
   */
  public function isPublished();

  /**
   * Sets the published status of a Play-by-Play entry.
   *
   * @param bool $published
   *   TRUE to set this Play-by-Play entry to published, FALSE to set it to
   *   unpublished.
   *
   * @return \Drupal\dynasty_plays\Entity\PbpPlayInterface
   *   The called Play-by-Play entry.
   */
  public function setPublished($published);

}
