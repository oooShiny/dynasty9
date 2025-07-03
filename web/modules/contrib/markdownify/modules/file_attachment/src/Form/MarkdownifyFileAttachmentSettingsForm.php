<?php

declare(strict_types=1);

namespace Drupal\markdownify_file_attachment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin form for File Attachment settings.
 */
class MarkdownifyFileAttachmentSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'markdownify_file_attachment_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['markdownify_file_attachment.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('markdownify_file_attachment.settings');

    $form['allowed_extensions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed file extensions'),
      '#description' => $this->t('Enter one extension per line.'),
      '#default_value' => implode("\n", $config->get('allowed_extensions')),
    ];

    $form['max_file_embed_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum file embed size (in bytes)'),
      '#description' => $this->t('Files larger than this size will not be embedded inline.'),
      '#default_value' => $config->get('max_file_embed_size'),
      '#min' => 0,
    ];

    return parent::buildForm($form, $form_state) + $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('markdownify_file_attachment.settings')
      ->set('allowed_extensions', array_map('trim', explode("\n", $form_state->getValue('allowed_extensions'))))
      ->set('max_file_embed_size', $form_state->getValue('max_file_embed_size'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
