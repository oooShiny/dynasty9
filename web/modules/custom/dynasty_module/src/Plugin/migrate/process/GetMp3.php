<?php

namespace Drupal\dynasty_module\Plugin\migrate\process;

use Drupal\migrate\Annotation\MigrateProcessPlugin;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Perform custom value transformations.
 *
 * @MigrateProcessPlugin(
 *   id = "get_mp3"
 * )
 *
 * Get MP3 id from mp3 enclosure tag:
 *
 * @code
 * field_text:
 *   plugin: get_mp3
 *   source: text
 * @endcode
 *
 */
class GetMp3 extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $att = $value->attributes();
    $url_bits = explode('/', $att['url']);
    $mp3 = substr($url_bits[7], 0, -4);
    return $mp3;
  }
}
