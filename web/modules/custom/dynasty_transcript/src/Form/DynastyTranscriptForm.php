<?php

namespace Drupal\dynasty_transcript\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the dynasty_transcript entity edit forms.
 */
class DynastyTranscriptForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {

    $entity = $this->getEntity();
    $result = $entity->save();
    $link = $entity->toLink($this->t('View'))->toRenderable();

    $message_arguments = ['%label' => $this->entity->label()];
    $logger_arguments = $message_arguments + ['link' => render($link)];

    if ($result == SAVED_NEW) {
      $this->messenger()->addStatus($this->t('New dynasty_transcript %label has been created.', $message_arguments));
      $this->logger('dynasty_transcript')->notice('Created new dynasty_transcript %label', $logger_arguments);
    }
    else {
      $this->messenger()->addStatus($this->t('The dynasty_transcript %label has been updated.', $message_arguments));
      $this->logger('dynasty_transcript')->notice('Updated new dynasty_transcript %label.', $logger_arguments);
    }

    $form_state->setRedirect('entity.dynasty_transcript.canonical', ['dynasty_transcript' => $entity->id()]);
  }

}
