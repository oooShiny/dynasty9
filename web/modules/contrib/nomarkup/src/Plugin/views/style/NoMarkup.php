<?php

namespace Drupal\nomarkup\Plugin\views\style;

use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Views style plugin to display rows without any additional markup.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "nomarkup",
 *   title = @Translation("No markup"),
 *   help = @Translation("Displays rows without any additional markup."),
 *   theme = "views_view_nomarkup",
 *   display_types = {"normal"}
 * )
 */
class NoMarkup extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesGrouping = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesOptions = FALSE;

}
