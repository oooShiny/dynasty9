<?php

namespace Drupal\social_post\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for deleting Social Post user entities.
 *
 * @ingroup social_post
 */
class SocialPostEntityDeleteForm extends ContentEntityDeleteForm {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $routeMatch;

  /**
   * User UID.
   *
   * @var string|null
   */
  protected $uid;

  /**
   * Provider prefix.
   *
   * @var string
   */
  protected $provider;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('current_route_match'),
      $container->get('messenger')
    );
  }

  /**
   * SocialPostUserEntityDeleteForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $route_match
   *   The current route match.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(EntityRepositoryInterface $entity_repository,
                              EntityTypeBundleInfoInterface $entity_type_bundle_info,
                              TimeInterface $time,
                              CurrentRouteMatch $route_match,
                              MessengerInterface $messenger) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);

    $this->routeMatch = $route_match;
    $this->messenger = $messenger;

    $this->uid = $this->routeMatch->getParameter('user');
    $this->provider = $this->routeMatch->getParameter('provider');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->getEntity();

    $entity->delete();
    $form_state->setRedirectUrl($this->getRedirectUrl());

    $this->messenger->addMessage($this->getDeletionMessage());
    $this->logDeletionMessage();
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl() {
    // If a user id is passed as a parameter, the form is being invoked from a
    // user edit form.
    if ($this->uid) {
      return Url::fromRoute('entity.user.edit_form', ['user' => $this->uid]);
    }

    return Url::fromRoute('social_post_' . $this->provider . '.user.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    // If a user id is passed as a parameter, the form is being invoked from a
    // user edit form.
    if ($this->uid) {
      return Url::fromRoute('entity.user.edit_form', ['user' => $this->uid]);
    }

    return Url::fromRoute('social_post_' . $this->provider . '.user.collection');
  }

}
