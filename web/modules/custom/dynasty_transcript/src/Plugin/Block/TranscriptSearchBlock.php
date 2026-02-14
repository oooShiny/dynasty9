<?php

namespace Drupal\dynasty_transcript\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a transcript search block.
 *
 * @Block(
 *   id = "transcript_search_block",
 *   admin_label = @Translation("Transcript Search"),
 *   category = @Translation("Dynasty")
 * )
 */
class TranscriptSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'compact_mode' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['compact_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Compact mode'),
      '#description' => $this->t('Use a more compact layout for the search widget.'),
      '#default_value' => $this->configuration['compact_mode'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['compact_mode'] = $form_state->getValue('compact_mode');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $wrapper_classes = [];
    if ($this->configuration['compact_mode']) {
      $wrapper_classes[] = 'ts-compact';
    }

    return [
      '#theme' => 'transcript_search',
      '#is_block' => TRUE,
      '#wrapper_classes' => implode(' ', $wrapper_classes),
      '#attached' => [
        'library' => [
          'dynasty_transcript/transcript_search',
        ],
      ],
    ];
  }

}
