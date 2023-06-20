<?php

namespace Drupal\dynasty_transcript;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the dynasty_transcript entity type.
 */
class DynastyTranscriptAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {

    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermission($account, 'view dynasty_transcript');

      case 'update':
        return AccessResult::allowedIfHasPermissions($account, ['edit dynasty_transcript', 'administer dynasty_transcript'], 'OR');

      case 'delete':
        return AccessResult::allowedIfHasPermissions($account, ['delete dynasty_transcript', 'administer dynasty_transcript'], 'OR');

      default:
        // No opinion.
        return AccessResult::neutral();
    }

  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['create dynasty_transcript', 'administer dynasty_transcript'], 'OR');
  }

}
