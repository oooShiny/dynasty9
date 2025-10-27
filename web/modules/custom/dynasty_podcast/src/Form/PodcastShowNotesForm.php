<?php

namespace Drupal\dynasty_podcast\Form;

use Drupal\node\NodeForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for the podcast show notes form mode.
 */
class PodcastShowNotesForm extends NodeForm {

  /**
   * {@inheritdoc}
   */
  protected function init(FormStateInterface $form_state) {
    parent::init($form_state);

    // Set the form mode to podcast_info.
    $form_display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load('node.' . $this->entity->bundle() . '.podcast_info');

    if ($form_display) {
      $this->setFormDisplay($form_display, $form_state);
    }
  }

}
