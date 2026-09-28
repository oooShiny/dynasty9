<?php

namespace Drupal\book_library\Form;

use Drupal\book_library\BookIndexer;
use Drupal\book_library\EpubExtractor;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Upload/edit form for library_book.
 *
 * Title and author may be left blank; they're filled from the EPUB's own
 * metadata. Saving (re-)extracts the book's passages whenever the EPUB
 * file is new or has changed.
 */
class LibraryBookForm extends ContentEntityForm {

  protected EpubExtractor $extractor;

  protected BookIndexer $indexer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->extractor = $container->get('book_library.epub_extractor');
    $instance->indexer = $container->get('book_library.indexer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    // Required at the entity level, but filled from EPUB metadata in
    // buildEntity() when left blank.
    $form['title']['widget'][0]['value']['#required'] = FALSE;

    if (!$this->entity->isNew()) {
      $form['reindex'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Re-extract passages from the EPUB'),
        '#description' => $this->t('Happens automatically when the file is replaced; tick this to rebuild the search text for the current file.'),
        '#weight' => 10,
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\book_library\Entity\LibraryBook $entity */
    $entity = parent::buildEntity($form, $form_state);

    $fid = $entity->get('epub')->target_id;
    if ($fid && ($entity->get('title')->isEmpty() || $entity->get('author')->isEmpty())) {
      $metadata = $this->metadata((int) $fid, $form_state);
      if ($entity->get('title')->isEmpty()) {
        $entity->set('title', $metadata['title'] !== '' ? mb_substr($metadata['title'], 0, 255) : NULL);
      }
      if ($entity->get('author')->isEmpty() && $metadata['author'] !== '') {
        $entity->set('author', mb_substr($metadata['author'], 0, 255));
      }
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);
    $fid = $entity->get('epub')->target_id;
    if ($fid && ($error = $form_state->get(['book_library_metadata', $fid, 'error']))) {
      $form_state->setErrorByName('epub', $this->t('That file could not be read as an EPUB: @error', ['@error' => $error]));
    }
    elseif ($fid && $entity->get('title')->isEmpty()) {
      $form_state->setErrorByName('title', $this->t("The EPUB has no title in its metadata; please enter one."));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\book_library\Entity\LibraryBook $book */
    $book = $this->entity;
    $file_changed = $book->isNew()
      || $this->entityTypeManager->getStorage('library_book')->loadUnchanged($book->id())->get('epub')->target_id != $book->get('epub')->target_id;

    $status = parent::save($form, $form_state);

    if ($file_changed || $form_state->getValue('reindex')) {
      try {
        $count = $this->indexer->reindex($book);
        $this->messenger()->addStatus($this->t('%title is searchable: @count passages indexed.', [
          '%title' => $book->label(),
          '@count' => $count,
        ]));
      }
      catch (\Throwable $e) {
        $this->logger('book_library')->error('Indexing book @id failed: @message', ['@id' => $book->id(), '@message' => $e->getMessage()]);
        $this->messenger()->addError($this->t('%title was saved, but its text could not be extracted: @message', [
          '%title' => $book->label(),
          '@message' => $e->getMessage(),
        ]));
      }
    }
    else {
      $this->messenger()->addStatus($this->t('Saved %title.', ['%title' => $book->label()]));
    }

    $form_state->setRedirectUrl($book->toUrl());
    return $status;
  }

  /**
   * Reads (and memoizes per form submission) an uploaded file's metadata.
   */
  protected function metadata(int $fid, FormStateInterface $form_state): array {
    $cached = $form_state->get(['book_library_metadata', $fid]);
    if ($cached !== NULL) {
      return $cached;
    }
    $metadata = ['title' => '', 'author' => '', 'error' => NULL];
    $file = File::load($fid);
    if ($file) {
      try {
        $metadata = $this->extractor->metadata($file->getFileUri()) + ['error' => NULL];
      }
      catch (\RuntimeException $e) {
        $metadata['error'] = $e->getMessage();
      }
    }
    $form_state->set(['book_library_metadata', $fid], $metadata);
    return $metadata;
  }

}
