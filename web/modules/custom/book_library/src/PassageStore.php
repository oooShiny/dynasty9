<?php

namespace Drupal\book_library;

use Drupal\Core\Database\Connection;

/**
 * Reads and writes {book_library_passage} rows.
 *
 * Passages are plain rows rather than entities: they're derived data,
 * rebuilt wholesale from the EPUB on every re-index, and searched with
 * MySQL's FULLTEXT MATCH ... AGAINST, which the Entity Query API can't
 * express. Same raw-SQL approach as dynasty_query/dynasty_search.
 */
class PassageStore {

  /**
   * Rows per multi-row INSERT when replacing a book's passages.
   */
  const INSERT_BATCH = 200;

  public function __construct(protected Connection $database) {}

  /**
   * Replaces all passages for a book, in one transaction.
   *
   * @param int $book_id
   *   The library_book ID.
   * @param array $passages
   *   Passages in reading order, each ['chapter' => string, 'body' => string].
   */
  public function replace(int $book_id, array $passages): void {
    $transaction = $this->database->startTransaction();
    try {
      $this->deleteBooks([$book_id]);
      foreach (array_chunk($passages, self::INSERT_BATCH, TRUE) as $chunk) {
        $insert = $this->database->insert('book_library_passage')
          ->fields(['book_id', 'position', 'chapter', 'body']);
        foreach ($chunk as $position => $passage) {
          $insert->values([$book_id, $position, $passage['chapter'], $passage['body']]);
        }
        $insert->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Deletes all passages for the given books.
   *
   * @param int[] $book_ids
   *   library_book IDs.
   */
  public function deleteBooks(array $book_ids): void {
    if ($book_ids) {
      $this->database->delete('book_library_passage')
        ->condition('book_id', $book_ids, 'IN')
        ->execute();
    }
  }

  /**
   * Runs a full-text search.
   *
   * @param string $expression
   *   A MySQL boolean-mode expression, from SearchQuery::booleanExpression().
   * @param int|null $book_id
   *   Restrict to one book, or NULL for all books.
   * @param int $offset
   *   Result offset.
   * @param int $limit
   *   Maximum results.
   *
   * @return array
   *   ['total' => int, 'rows' => object[]], rows having book_id, position,
   *   chapter, body, score -- best matches first.
   */
  public function search(string $expression, ?int $book_id, int $offset, int $limit): array {
    $where = 'MATCH(body) AGAINST(:expr IN BOOLEAN MODE)';
    $args = [':expr' => $expression];
    if ($book_id !== NULL) {
      $where .= ' AND book_id = :book_id';
      $args[':book_id'] = $book_id;
    }

    $total = (int) $this->database->query("SELECT COUNT(*) FROM {book_library_passage} WHERE $where", $args)->fetchField();
    if (!$total) {
      return ['total' => 0, 'rows' => []];
    }

    $rows = $this->database->queryRange(
      "SELECT book_id, position, chapter, body, MATCH(body) AGAINST(:expr IN BOOLEAN MODE) AS score
        FROM {book_library_passage}
        WHERE $where
        ORDER BY score DESC, book_id, position",
      $offset,
      $limit,
      $args
    )->fetchAll();

    return ['total' => $total, 'rows' => $rows];
  }

  /**
   * Counts matching passages per book, for the results' book filter.
   *
   * @return int[]
   *   Keyed by book ID.
   */
  public function countByBook(string $expression): array {
    return $this->database->query(
      'SELECT book_id, COUNT(*) FROM {book_library_passage} WHERE MATCH(body) AGAINST(:expr IN BOOLEAN MODE) GROUP BY book_id',
      [':expr' => $expression]
    )->fetchAllKeyed();
  }

  /**
   * Loads a contiguous range of a book's passages.
   *
   * @return object[]
   *   Rows having position, chapter, body, in reading order.
   */
  public function range(int $book_id, int $start, int $count): array {
    return $this->database->queryRange(
      'SELECT position, chapter, body FROM {book_library_passage} WHERE book_id = :book_id AND position >= :start ORDER BY position',
      0,
      $count,
      [':book_id' => $book_id, ':start' => $start]
    )->fetchAll();
  }

  /**
   * Lists a book's chapters with the position each one starts at.
   *
   * @return array
   *   [['chapter' => string, 'position' => int], ...] in reading order.
   *   Consecutive passages with the same label collapse into one entry.
   */
  public function chapters(int $book_id): array {
    $chapters = [];
    $previous = NULL;
    $result = $this->database->query(
      'SELECT position, chapter FROM {book_library_passage} WHERE book_id = :book_id ORDER BY position',
      [':book_id' => $book_id]
    );
    foreach ($result as $row) {
      if ($row->chapter !== $previous) {
        if ($row->chapter !== '') {
          $chapters[] = ['chapter' => $row->chapter, 'position' => (int) $row->position];
        }
        $previous = $row->chapter;
      }
    }
    return $chapters;
  }

}
