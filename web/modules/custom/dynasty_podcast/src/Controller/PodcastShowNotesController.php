<?php

namespace Drupal\dynasty_podcast\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for displaying the Podcast Show Notes form mode.
 */
class PodcastShowNotesController extends ControllerBase {

  /**
   * The entity form builder.
   *
   * @var \Drupal\Core\Entity\EntityFormBuilderInterface
   */
  protected $entityFormBuilder;

  /**
   * Constructs a PodcastShowNotesController object.
   *
   * @param \Drupal\Core\Entity\EntityFormBuilderInterface $entity_form_builder
   *   The entity form builder.
   */
  public function __construct(EntityFormBuilderInterface $entity_form_builder) {
    $this->entityFormBuilder = $entity_form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.form_builder')
    );
  }

  /**
   * Displays the podcast_info form mode for a Game node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   A render array containing the form.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the node is not of type 'game'.
   */
  public function showPodcastForm(NodeInterface $node) {
    // Verify this is a Game node.
    if ($node->bundle() !== 'game') {
      throw new AccessDeniedHttpException('This page is only available for Game nodes.');
    }

    // Get the entity form display for the podcast_info form mode.
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display */
    $form_display = \Drupal::entityTypeManager()
      ->getStorage('entity_form_display')
      ->load('node.game.podcast_info');

    if (!$form_display) {
      throw new \Exception('The podcast_info form mode is not configured for Game nodes.');
    }

    // Get the form object and set the entity.
    $form_object = \Drupal::entityTypeManager()
      ->getFormObject('node', 'default');
    $form_object->setEntity($node);

    // Create a form state.
    $form_state = new FormState();

    // Set the form display BEFORE building the form.
    $form_object->setFormDisplay($form_display, $form_state);

    // Build and return the form.
    return \Drupal::formBuilder()->getForm($form_object, $form_state);
  }

}
