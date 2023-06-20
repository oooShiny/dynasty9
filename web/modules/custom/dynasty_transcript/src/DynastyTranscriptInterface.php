<?php

namespace Drupal\dynasty_transcript;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Provides an interface defining a dynasty_transcript entity type.
 */
interface DynastyTranscriptInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Gets the dynasty_transcript title.
   *
   * @return string
   *   Title of the dynasty_transcript.
   */
  public function getTitle();

  /**
   * Sets the dynasty_transcript title.
   *
   * @param string $title
   *   The dynasty_transcript title.
   *
   * @return \Drupal\dynasty_transcript\DynastyTranscriptInterface
   *   The called dynasty_transcript entity.
   */
  public function setTitle($title);

  /**
   * Gets the dynasty_transcript creation timestamp.
   *
   * @return int
   *   Creation timestamp of the dynasty_transcript.
   */
  public function getCreatedTime();

  /**
   * Sets the dynasty_transcript creation timestamp.
   *
   * @param int $timestamp
   *   The dynasty_transcript creation timestamp.
   *
   * @return \Drupal\dynasty_transcript\DynastyTranscriptInterface
   *   The called dynasty_transcript entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Returns the dynasty_transcript status.
   *
   * @return bool
   *   TRUE if the dynasty_transcript is enabled, FALSE otherwise.
   */
  public function isEnabled();

  /**
   * Sets the dynasty_transcript status.
   *
   * @param bool $status
   *   TRUE to enable this dynasty_transcript, FALSE to disable.
   *
   * @return \Drupal\dynasty_transcript\DynastyTranscriptInterface
   *   The called dynasty_transcript entity.
   */
  public function setStatus($status);

}
