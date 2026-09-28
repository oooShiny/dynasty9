<?php

namespace Drupal\book_library\Controller;

use Drupal\book_library\Entity\LibraryBook;
use Drupal\book_library\PassageStore;
use Drupal\book_library\SearchQuery;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The /books search page and the /books/{id} reader.
 *
 * Search runs server-side against MySQL FULLTEXT rather than the flat-JSON,
 * filter-in-the-browser pattern dynasty_search uses: the full text of even a
 * handful of books is tens of MB, far too much to ship to a phone.
 */
class LibraryController extends ControllerBase {

  /**
   * Search results per page.
   */
  const RESULTS_PER_PAGE = 20;

  /**
   * Passages per reader page.
   */
  const PASSAGES_PER_PAGE = 25;

  public function __construct(
    protected PassageStore $passageStore,
    protected PagerManagerInterface $pagerManager,
    protected RequestStack $requestStack,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('book_library.passage_store'),
      $container->get('pager.manager'),
      $container->get('request_stack'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * The library home: search box, results, and the list of books.
   */
  public function search(): array {
    $request = $this->requestStack->getCurrentRequest();
    $query = new SearchQuery(trim((string) $request->query->get('q', '')));
    // Not getInt(): the "All books" option submits an empty `book=`, and
    // InputBag::getInt() throws on any non-integer value, '' included.
    $book_param = (string) $request->query->get('book', '');
    $book_filter = ctype_digit($book_param) ? (int) $book_param : NULL;

    /** @var \Drupal\book_library\Entity\LibraryBook[] $books */
    $storage = $this->entityTypeManager()->getStorage('library_book');
    $books = $storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->sort('title')->execute());
    if ($book_filter !== NULL && !isset($books[$book_filter])) {
      $book_filter = NULL;
    }

    $results = [];
    $total = 0;
    $hits = [];
    $message = NULL;
    if ($query->input() !== '') {
      if ($query->isEmpty()) {
        $message = $this->t('Very short and very common words (like "the") aren\'t searchable; try more specific words.');
      }
      else {
        $expression = $query->booleanExpression();
        $hits = $this->passageStore->countByBook($expression);
        $total = $book_filter !== NULL ? ($hits[$book_filter] ?? 0) : array_sum($hits);
        $page = $this->pagerManager->createPager($total, self::RESULTS_PER_PAGE)->getCurrentPage();
        $found = $this->passageStore->search($expression, $book_filter, $page * self::RESULTS_PER_PAGE, self::RESULTS_PER_PAGE);
        foreach ($found['rows'] as $row) {
          $book = $books[$row->book_id] ?? NULL;
          if (!$book) {
            continue;
          }
          $results[] = [
            'book_title' => $book->label(),
            'author' => $book->get('author')->value,
            'chapter' => $row->chapter,
            'snippet' => $query->snippet($row->body),
            'url' => $this->passageUrl($book, (int) $row->position, $query)->toString(),
          ];
        }
        if (!$total) {
          $message = $this->t('No passages match %q.', ['%q' => $query->input()]);
        }
      }
    }

    $book_list = [];
    foreach ($books as $id => $book) {
      $book_list[] = [
        'id' => $id,
        'title' => $book->label(),
        'author' => $book->get('author')->value,
        'passages' => (int) $book->get('passage_count')->value,
        'url' => $book->toUrl()->toString(),
        'hits' => $hits[$id] ?? 0,
      ];
    }

    return [
      '#theme' => 'book_library_search',
      '#query' => $query->input(),
      '#books' => $book_list,
      '#selected_book' => $book_filter,
      '#results' => $results,
      '#total' => $total,
      '#message' => $message,
      '#pager' => $total ? ['#type' => 'pager', '#quantity' => 3] : NULL,
      '#upload_url' => $this->currentUser()->hasPermission('administer book library')
        ? Url::fromRoute('entity.library_book.add_form')->toString()
        : NULL,
      '#cache' => [
        'contexts' => ['url.query_args', 'user.permissions'],
        'tags' => ['library_book_list'],
      ],
    ];
  }

  /**
   * Reads a book one page of passages at a time.
   */
  public function read(LibraryBook $library_book): array {
    $request = $this->requestStack->getCurrentRequest();
    $highlight = new SearchQuery((string) $request->query->get('hl', ''));
    $book_id = (int) $library_book->id();

    $total = (int) $library_book->get('passage_count')->value;
    $page = $this->pagerManager->createPager($total, self::PASSAGES_PER_PAGE)->getCurrentPage();

    $passages = [];
    $previous_chapter = NULL;
    foreach ($this->passageStore->range($book_id, $page * self::PASSAGES_PER_PAGE, self::PASSAGES_PER_PAGE) as $row) {
      $paragraphs = [];
      foreach (explode("\n\n", $row->body) as $paragraph) {
        $paragraphs[] = $highlight->highlight($paragraph);
      }
      $passages[] = [
        'position' => (int) $row->position,
        // Show the chapter label where it changes, including at the top
        // of a page that starts mid-chapter.
        'chapter' => $row->chapter !== $previous_chapter ? $row->chapter : NULL,
        'paragraphs' => $paragraphs,
      ];
      $previous_chapter = $row->chapter;
    }

    $chapters = [];
    foreach ($this->passageStore->chapters($book_id) as $chapter) {
      $chapters[] = [
        'label' => $chapter['chapter'],
        'url' => $this->passageUrl($library_book, $chapter['position'])->toString(),
      ];
    }

    /** @var \Drupal\file\FileInterface|null $file */
    $file = $library_book->get('epub')->entity;

    return [
      '#theme' => 'book_library_reader',
      '#book_title' => $library_book->label(),
      '#author' => $library_book->get('author')->value,
      '#passages' => $passages,
      '#chapters' => $chapters,
      '#highlight' => $highlight->isEmpty() ? '' : $highlight->input(),
      '#clear_highlight_url' => $highlight->isEmpty() ? NULL : $library_book->toUrl('canonical', ['query' => ['page' => $page]])->toString(),
      '#search_url' => Url::fromRoute('book_library.search', [], ['query' => $highlight->isEmpty() ? [] : ['q' => $highlight->input()]])->toString(),
      '#download_url' => $file ? $this->fileUrlGenerator->generateString($file->getFileUri()) : NULL,
      '#pager' => ['#type' => 'pager', '#quantity' => 3],
      '#cache' => [
        'contexts' => ['url.query_args'],
        'tags' => $library_book->getCacheTags(),
      ],
    ];
  }

  /**
   * Title callback for the reader.
   */
  public function readTitle(LibraryBook $library_book): string {
    return $library_book->label();
  }

  /**
   * Links to the reader page containing a passage, scrolled to it.
   */
  protected function passageUrl(LibraryBook $book, int $position, ?SearchQuery $query = NULL): Url {
    $params = ['page' => intdiv($position, self::PASSAGES_PER_PAGE)];
    if ($query && !$query->isEmpty()) {
      $params['hl'] = $query->highlightParam();
    }
    return $book->toUrl('canonical', [
      'query' => $params,
      'fragment' => 'passage-' . $position,
    ]);
  }

}
