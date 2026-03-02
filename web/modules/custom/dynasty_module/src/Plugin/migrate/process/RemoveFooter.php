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
 *   id = "remove_footer"
 * )
 *
 * Remove Acast footer from body text:
 *
 * @code
 * field_text:
 *   plugin: remove_footer
 *   source: text
 * @endcode
 *
 */
class RemoveFooter extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // Remove old Acast footer format.
    $footer = "<br /><hr><p style='color:grey; font-size:0.75em;'>";
    $body_array = explode($footer, $value);
    $value = $body_array[0];

    // Remove new Acast footer format: "Support this show" paragraph and
    // everything that follows (the <hr> and privacy notice).
    $value = preg_replace('/<p[^>]*>\s*Support this show.*$/s', '', $value);

    return trim($value);
  }
}
