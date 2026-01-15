<?php

namespace Drupal\dynasty_newsletter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for newsletter preview.
 */
class NewsletterPreviewController extends ControllerBase {

  /**
   * The newsletter content builder service.
   *
   * @var \Drupal\dynasty_newsletter\Service\NewsletterContentBuilder
   */
  protected $contentBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->contentBuilder = $container->get('dynasty_newsletter.content_builder');
    return $instance;
  }

  /**
   * Preview a newsletter.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The newsletter node.
   *
   * @return array
   *   Render array.
   */
  public function preview(NodeInterface $node) {
    if ($node->getType() !== 'simplenews_issue') {
      return [
        '#markup' => $this->t('This is not a newsletter node.'),
      ];
    }

    return [
      '#markup' => $node->get('body')->value,
      '#allowed_tags' => ['a', 'div', 'p', 'h1', 'h2', 'h3', 'strong', 'em', 'br', 'table', 'tr', 'td', 'th', 'img', 'ul', 'li', 'ol', 'span', 'style'],
    ];
  }

}
