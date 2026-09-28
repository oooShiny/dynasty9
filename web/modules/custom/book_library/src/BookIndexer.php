<?php

namespace Drupal\book_library;

use Drupal\book_library\Entity\LibraryBook;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * Rebuilds a book's searchable passages from its EPUB.
 *
 * Runs synchronously on form save: extraction only touches the EPUB's text
 * entries, so it's far inside the production PHP-FPM limits (measured: a
 * 24MB illustrated Pride and Prejudice EPUB -> 555 passages in 0.3s at
 * 26MB peak memory), and a queue would only have added a wait-for-cron
 * delay before a newly uploaded book became searchable.
 */
class BookIndexer {

  public function __construct(
    protected EpubExtractor $extractor,
    protected PassageStore $passageStore,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Extracts a book's EPUB and replaces its passages.
   *
   * @return int
   *   The number of passages indexed.
   *
   * @throws \RuntimeException
   *   If the book has no file or it isn't a readable EPUB. Existing passages
   *   are left untouched in that case.
   */
  public function reindex(LibraryBook $book): int {
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $book->get('epub')->entity;
    if (!$file) {
      throw new \RuntimeException(sprintf('Book %d has no EPUB file.', $book->id()));
    }

    $result = $this->extractor->extract($file->getFileUri());
    $this->passageStore->replace((int) $book->id(), $result['passages']);

    $count = count($result['passages']);
    $book->set('passage_count', $count)
      ->set('indexed', \Drupal::time()->getRequestTime())
      ->save();
    // The save above covers this book's own tags; search results pages
    // list every book, so also clear the list tag explicitly in case a
    // caller reindexes without a field change.
    $this->cacheTagsInvalidator->invalidateTags(['library_book_list']);

    return $count;
  }

}
