<?php

namespace Drupal\book_library;

use Drupal\Core\File\FileSystemInterface;

/**
 * Extracts metadata and plain-text passages from an EPUB file.
 *
 * An EPUB is a zip of XHTML documents plus an OPF package file listing
 * them in reading order (the "spine"). This walks the spine, turns each
 * document's block-level elements into plain-text paragraphs, and groups
 * consecutive paragraphs into passages of roughly PASSAGE_TARGET_CHARS --
 * big enough that a search hit shows useful context, small enough that
 * "read in context" lands close to the match.
 *
 * Chapter labels come from the book's own table of contents (EPUB3 nav
 * document, falling back to the EPUB2 NCX), matched by file and, where the
 * TOC points into the middle of a file (common in Project Gutenberg and
 * other single-file-per-several-chapters EPUBs), by fragment ID. A new
 * passage always starts at a chapter boundary or a heading, so a passage
 * never straddles two chapters.
 *
 * Only ever reads the zip's XML/XHTML entries -- images and fonts are
 * never decompressed, so memory use tracks the text size, not the file
 * size (a 25MB illustrated EPUB is mostly images).
 */
class EpubExtractor {

  /**
   * Passages are flushed once they reach at least this many characters.
   */
  const PASSAGE_TARGET_CHARS = 1000;

  /**
   * Elements treated as paragraph-level text blocks.
   */
  const BLOCK_TAGS = [
    'p', 'div', 'section', 'article', 'blockquote', 'pre', 'li', 'ul', 'ol',
    'dl', 'dt', 'dd', 'table', 'tr', 'td', 'th', 'caption', 'figure',
    'figcaption', 'aside', 'header', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5',
    'h6', 'hr', 'body',
  ];

  /**
   * Elements whose content is never book text.
   */
  const SKIP_TAGS = ['head', 'script', 'style', 'svg', 'math', 'noscript'];

  /**
   * Headings that start a new passage.
   */
  const HEADING_TAGS = ['h1', 'h2', 'h3', 'h4'];

  /**
   * The open EPUB archive.
   */
  protected \ZipArchive $zip;

  /**
   * Chapter label for the passage currently being built.
   */
  protected string $chapter = '';

  /**
   * Paragraphs accumulated for the passage currently being built.
   *
   * @var string[]
   */
  protected array $buffer = [];

  /**
   * Character length of $buffer.
   */
  protected int $bufferLength = 0;

  /**
   * Finished passages, each ['chapter' => string, 'body' => string].
   *
   * @var array[]
   */
  protected array $passages = [];

  public function __construct(protected FileSystemInterface $fileSystem) {}

  /**
   * Reads only the book's title and author.
   *
   * @param string $uri
   *   A stream-wrapper URI or local path to the EPUB.
   *
   * @return array
   *   ['title' => string, 'author' => string]; either may be ''.
   *
   * @throws \RuntimeException
   *   If the file isn't a readable EPUB.
   */
  public function metadata(string $uri): array {
    $this->open($uri);
    try {
      $package = $this->loadPackage();
      return ['title' => $package['title'], 'author' => $package['author']];
    }
    finally {
      $this->zip->close();
    }
  }

  /**
   * Extracts metadata and all passages, in reading order.
   *
   * @param string $uri
   *   A stream-wrapper URI or local path to the EPUB.
   *
   * @return array
   *   ['title' => string, 'author' => string, 'passages' => array[]], each
   *   passage being ['chapter' => string, 'body' => string] with its
   *   paragraphs separated by blank lines.
   *
   * @throws \RuntimeException
   *   If the file isn't a readable EPUB.
   */
  public function extract(string $uri): array {
    $this->open($uri);
    $this->chapter = '';
    $this->buffer = [];
    $this->bufferLength = 0;
    $this->passages = [];

    try {
      $package = $this->loadPackage();
      $anchors = $this->loadTableOfContents($package);

      foreach ($package['spine'] as $path) {
        $doc = $this->loadDocument($path);
        if (!$doc) {
          continue;
        }
        $file_anchors = $anchors[$path] ?? [];
        // A TOC entry pointing at the file itself (no fragment) labels
        // everything from the top of the file.
        if (isset($file_anchors[''])) {
          $this->startChapter($file_anchors['']);
        }
        $body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        if ($body) {
          $this->walk($body, $file_anchors);
        }
      }
      $this->flush();

      return [
        'title' => $package['title'],
        'author' => $package['author'],
        'passages' => $this->passages,
      ];
    }
    finally {
      $this->zip->close();
    }
  }

  /**
   * Opens the EPUB archive.
   */
  protected function open(string $uri): void {
    $path = $this->fileSystem->realpath($uri) ?: $uri;
    $this->zip = new \ZipArchive();
    if ($this->zip->open($path, \ZipArchive::RDONLY) !== TRUE) {
      throw new \RuntimeException('The file is not a readable EPUB (zip) archive.');
    }
  }

  /**
   * Parses the OPF package: metadata, manifest, spine, and TOC locations.
   */
  protected function loadPackage(): array {
    $container = $this->loadXml('META-INF/container.xml');
    if (!$container) {
      throw new \RuntimeException('The EPUB has no META-INF/container.xml.');
    }
    $opf_path = (new \DOMXPath($container))->evaluate("string(//*[local-name()='rootfile']/@full-path)");
    $opf = $opf_path ? $this->loadXml($opf_path) : NULL;
    if (!$opf) {
      throw new \RuntimeException('The EPUB package (OPF) file is missing or unreadable.');
    }
    $xpath = new \DOMXPath($opf);
    $base = $this->dirname($opf_path);

    $authors = [];
    foreach ($xpath->query("//*[local-name()='metadata']/*[local-name()='creator']") as $creator) {
      $name = $this->normalize($creator->textContent);
      if ($name !== '') {
        $authors[] = $name;
      }
    }

    $manifest = [];
    $nav = NULL;
    foreach ($xpath->query("//*[local-name()='manifest']/*[local-name()='item']") as $item) {
      $href = $this->resolve($base, $item->getAttribute('href'));
      $manifest[$item->getAttribute('id')] = $href;
      if (in_array('nav', explode(' ', $item->getAttribute('properties')), TRUE)) {
        $nav = $href;
      }
    }

    $spine = [];
    foreach ($xpath->query("//*[local-name()='spine']/*[local-name()='itemref']") as $itemref) {
      $href = $manifest[$itemref->getAttribute('idref')] ?? NULL;
      if ($href !== NULL && $itemref->getAttribute('linear') !== 'no') {
        $spine[] = $href;
      }
    }
    if (!$spine) {
      throw new \RuntimeException('The EPUB has no readable content documents.');
    }

    $ncx_id = $xpath->evaluate("string(//*[local-name()='spine']/@toc)");

    return [
      'title' => $this->normalize($xpath->evaluate("string(//*[local-name()='metadata']/*[local-name()='title'][1])")),
      'author' => implode(', ', array_unique($authors)),
      'spine' => $spine,
      'nav' => $nav,
      'ncx' => $ncx_id !== '' ? ($manifest[$ncx_id] ?? NULL) : NULL,
    ];
  }

  /**
   * Builds a map of [document path => [fragment ID or '' => chapter label]].
   *
   * Prefers the EPUB3 nav document; falls back to the EPUB2 NCX. Only the
   * first label for each exact target is kept, so a nested TOC's
   * sub-entries don't overwrite their parent chapter's label.
   */
  protected function loadTableOfContents(array $package): array {
    $entries = [];

    if ($package['nav'] && ($doc = $this->loadDocument($package['nav']))) {
      $xpath = new \DOMXPath($doc);
      $toc = $xpath->query("//*[local-name()='nav'][@*[local-name()='type']='toc']")->item(0)
        ?? $xpath->query("//*[local-name()='nav']")->item(0);
      if ($toc) {
        $base = $this->dirname($package['nav']);
        foreach ($xpath->query(".//*[local-name()='a'][@href]", $toc) as $link) {
          $entries[] = [$this->resolve($base, $link->getAttribute('href'), TRUE), $link->textContent];
        }
      }
    }

    if (!$entries && $package['ncx'] && ($ncx = $this->loadXml($package['ncx']))) {
      $xpath = new \DOMXPath($ncx);
      $base = $this->dirname($package['ncx']);
      foreach ($xpath->query("//*[local-name()='navPoint']") as $point) {
        $label = $xpath->evaluate("string(*[local-name()='navLabel']/*[local-name()='text'])", $point);
        $src = $xpath->evaluate("string(*[local-name()='content']/@src)", $point);
        if ($src !== '') {
          $entries[] = [$this->resolve($base, $src, TRUE), $label];
        }
      }
    }

    $anchors = [];
    foreach ($entries as [[$path, $fragment], $label]) {
      $label = mb_substr($this->normalize($label), 0, 255);
      if ($label !== '' && !isset($anchors[$path][$fragment])) {
        $anchors[$path][$fragment] = $label;
      }
    }
    return $anchors;
  }

  /**
   * Walks an element in document order, emitting paragraphs.
   *
   * @param \DOMNode $node
   *   The element to walk.
   * @param array $anchors
   *   [fragment ID => chapter label] for the current document.
   */
  protected function walk(\DOMNode $node, array $anchors): void {
    foreach ($node->childNodes as $child) {
      if ($child instanceof \DOMText) {
        // Loose text directly inside a container that also has block
        // children (e.g. <div>text<p>...</p></div>).
        $this->addParagraph($child->textContent);
        continue;
      }
      if (!$child instanceof \DOMElement) {
        continue;
      }
      $tag = strtolower($child->localName);
      if (in_array($tag, self::SKIP_TAGS, TRUE)) {
        continue;
      }

      $id = $child->getAttribute('id');
      if ($id !== '' && isset($anchors[$id])) {
        $this->startChapter($anchors[$id]);
      }

      if (!in_array($tag, self::BLOCK_TAGS, TRUE) || !$this->hasBlockChild($child)) {
        // A leaf block (or stray inline content): its whole text is one
        // paragraph, but a TOC anchor may be nested inside it, e.g.
        // <h2><a id="chapter-3"></a>Chapter 3</h2>.
        if ($anchors) {
          foreach ($child->getElementsByTagName('*') as $descendant) {
            $descendant_id = $descendant->getAttribute('id');
            if ($descendant_id !== '' && isset($anchors[$descendant_id])) {
              $this->startChapter($anchors[$descendant_id]);
            }
          }
        }
        if (in_array($tag, self::HEADING_TAGS, TRUE)) {
          $this->flush();
        }
        $this->addParagraph($child->textContent);
        continue;
      }

      $this->walk($child, $anchors);
    }
  }

  /**
   * Whether an element contains any block-level child element.
   */
  protected function hasBlockChild(\DOMElement $element): bool {
    foreach ($element->childNodes as $child) {
      if ($child instanceof \DOMElement && in_array(strtolower($child->localName), self::BLOCK_TAGS, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Begins a new chapter, closing out the current passage.
   */
  protected function startChapter(string $label): void {
    $this->flush();
    $this->chapter = $label;
  }

  /**
   * Adds one paragraph to the current passage.
   */
  protected function addParagraph(string $text): void {
    $text = $this->normalize($text);
    if ($text === '') {
      return;
    }
    $this->buffer[] = $text;
    $this->bufferLength += mb_strlen($text);
    if ($this->bufferLength >= self::PASSAGE_TARGET_CHARS) {
      $this->flush();
    }
  }

  /**
   * Closes out the current passage, if it has any text.
   */
  protected function flush(): void {
    if ($this->buffer) {
      $this->passages[] = [
        'chapter' => $this->chapter,
        'body' => implode("\n\n", $this->buffer),
      ];
    }
    $this->buffer = [];
    $this->bufferLength = 0;
  }

  /**
   * Loads a zip entry as strict XML (container, OPF, NCX).
   */
  protected function loadXml(string $path): ?\DOMDocument {
    $xml = $this->zip->getFromName($path);
    if ($xml === FALSE) {
      return NULL;
    }
    $doc = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    // No LIBXML_NOENT/LIBXML_DTDLOAD: external entities are never resolved.
    $loaded = $doc->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $loaded ? $doc : NULL;
  }

  /**
   * Loads a content document, falling back to the lenient HTML parser.
   *
   * EPUB content is meant to be XHTML, but real-world files often use HTML
   * named entities (&nbsp;, &mdash;) that strict XML parsing rejects.
   */
  protected function loadDocument(string $path): ?\DOMDocument {
    $doc = $this->loadXml($path);
    if ($doc) {
      return $doc;
    }
    $html = $this->zip->getFromName($path);
    if ($html === FALSE || trim($html) === '') {
      return NULL;
    }
    $doc = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $loaded ? $doc : NULL;
  }

  /**
   * Resolves an href relative to a directory inside the archive.
   *
   * @return string|array
   *   The normalized archive path, or [path, fragment] if $with_fragment.
   */
  protected function resolve(string $base, string $href, bool $with_fragment = FALSE): string|array {
    [$path, $fragment] = array_pad(explode('#', $href, 2), 2, '');
    $parts = [];
    foreach (explode('/', ($base !== '' ? $base . '/' : '') . rawurldecode($path)) as $segment) {
      if ($segment === '..') {
        array_pop($parts);
      }
      elseif ($segment !== '' && $segment !== '.') {
        $parts[] = $segment;
      }
    }
    $resolved = implode('/', $parts);
    return $with_fragment ? [$resolved, $fragment] : $resolved;
  }

  /**
   * Directory part of an archive path ('' for the archive root).
   */
  protected function dirname(string $path): string {
    $dir = dirname($path);
    return $dir === '.' ? '' : $dir;
  }

  /**
   * Collapses all whitespace (including non-breaking spaces) to single spaces.
   */
  protected function normalize(string $text): string {
    return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
  }

}
