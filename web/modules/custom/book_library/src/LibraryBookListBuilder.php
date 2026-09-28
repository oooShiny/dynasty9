<?php

namespace Drupal\book_library;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Admin list of books at /admin/content/books.
 */
class LibraryBookListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function load() {
    $ids = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('title')
      ->pager($this->limit)
      ->execute();
    return $this->storage->loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'title' => $this->t('Title'),
      'author' => $this->t('Author'),
      'passages' => $this->t('Passages'),
      'indexed' => $this->t('Last indexed'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\book_library\Entity\LibraryBook $entity */
    $indexed = $entity->get('indexed')->value;
    return [
      'title' => $entity->toLink(),
      'author' => $entity->get('author')->value,
      'passages' => $entity->get('passage_count')->value,
      'indexed' => $indexed ? \Drupal::service('date.formatter')->format($indexed, 'short') : $this->t('Never'),
    ] + parent::buildRow($entity);
  }

}
