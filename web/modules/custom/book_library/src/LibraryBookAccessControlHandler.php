<?php

namespace Drupal\book_library;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for library_book entities.
 *
 * `view` also gates downloading the private EPUB file itself: core's
 * file_file_download() only serves a private file to someone who can view
 * an entity referencing it.
 */
class LibraryBookAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermissions($account, ['access book library', 'administer book library'], 'OR');
    }
    return AccessResult::allowedIfHasPermission($account, 'administer book library');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'administer book library');
  }

}
