<?php

namespace Drupal\book_library\Commands;

use Drupal\book_library\BookIndexer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the book library.
 */
class BookLibraryCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BookIndexer $indexer,
  ) {
    parent::__construct();
  }

  /**
   * Re-extracts searchable passages from one or all books' EPUB files.
   *
   * Uploading a book through the form already indexes it; this is for
   * rebuilding after a change to the extractor itself.
   *
   * @param string|null $id
   *   A library_book ID; omit to reindex every book.
   *
   * @command book-library:reindex
   * @usage drush book-library:reindex
   *   Reindex every book.
   * @usage drush book-library:reindex 3
   *   Reindex book 3 only.
   */
  public function reindex(?string $id = NULL): void {
    $storage = $this->entityTypeManager->getStorage('library_book');
    $books = $id !== NULL ? $storage->loadMultiple([$id]) : $storage->loadMultiple();
    if (!$books) {
      $this->logger()->warning($id !== NULL ? "No book with ID $id." : 'No books to index.');
      return;
    }
    foreach ($books as $book) {
      $start = microtime(TRUE);
      try {
        $count = $this->indexer->reindex($book);
        $this->logger()->success(sprintf('%s: %d passages (%.1fs, peak memory %dMB)',
          $book->label(), $count, microtime(TRUE) - $start, memory_get_peak_usage(TRUE) / 1048576));
      }
      catch (\Throwable $e) {
        $this->logger()->error(sprintf('%s: %s', $book->label(), $e->getMessage()));
      }
    }
  }

}
