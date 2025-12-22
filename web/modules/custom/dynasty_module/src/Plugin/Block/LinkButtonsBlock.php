<?php

namespace Drupal\dynasty_module\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Provides a Block that displays a list of links as buttons.
 *
 * @Block(
 *   id = "link_buttons_block",
 *   admin_label = @Translation("Link Buttons Block"),
 *   category = @Translation("Dynasty"),
 * )
 */
class LinkButtonsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $list = $this->configuration['dynasty_link_button_list'];
    $links = [
      'games' => [
        ['link' => '/search/games', 'text' => 'Search Games'],
        ['link' => 'https://patriots.games', 'text' => 'Watch Games'],
      ],
      'plays' => [
        ['link' => '/top-plays', 'text' => 'Top Plays'],
        ['link' => '/search/plays', 'text' => 'Search Plays'],
        ['link' => '/players', 'text' => 'Players'],
      ],
      'pod' => [
        ['link' => '/podcast', 'text' => 'Episode Search'],
        ['link' => '/search/transcripts', 'text' => 'Clip Search'],
        ['link' => '/podcast', 'text' => 'Feedback'],
      ],
      'blog' => [
        ''
      ],
    ];
    return [
      '#theme' => 'link_buttons_block',
      '#buttons' => $links[$list],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'dynasty_link_button_list' => 'games',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $form['dynasty_link_button_list'] = [
      '#type' => 'select',
      '#title' => $this->t('Link List'),
      '#description' => $this->t('The list of link buttons to display'),
      '#default_value' => $this->configuration['dynasty_link_button_list'],
      '#options' => [
        'games' => 'Games Links',
        'plays' => 'Plays Links',
        'pod' => 'Podcast Links',
        'blog' => 'Article Links',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->configuration['dynasty_link_button_list'] = $values['dynasty_link_button_list'];
  }
}

