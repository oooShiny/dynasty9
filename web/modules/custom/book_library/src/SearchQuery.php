<?php

namespace Drupal\book_library;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

/**
 * A parsed search box query.
 *
 * Supports plain words (all required), "quoted phrases", and -excluded
 * words. Plain words are prefix-matched ("run" also finds "running"),
 * since MySQL FULLTEXT has no stemming.
 *
 * Words InnoDB never indexes -- shorter than its default
 * innodb_ft_min_token_size (3), or on its default stopword list -- are
 * dropped from the required words rather than silently changing the
 * results: `+the*` would otherwise demand some word *starting* with "the"
 * ("their", "them", ...). Phrases may still contain them ("the speckled
 * band" works: InnoDB checks phrase adjacency against the stored text), as
 * long as the phrase has at least one indexed word.
 */
class SearchQuery {

  /**
   * Shortest word InnoDB FULLTEXT indexes by default.
   */
  const MIN_WORD_LENGTH = 3;

  /**
   * InnoDB's default stopwords (INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD)
   * of at least MIN_WORD_LENGTH characters.
   */
  const STOPWORDS = [
    'about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was',
    'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www',
  ];

  /**
   * Required single words (lowercase).
   *
   * @var string[]
   */
  protected array $words = [];

  /**
   * Required phrases, each a list of lowercase words.
   *
   * @var string[][]
   */
  protected array $phrases = [];

  /**
   * Excluded words (lowercase).
   *
   * @var string[]
   */
  protected array $excluded = [];

  public function __construct(protected string $input) {
    preg_match_all('/(-?)"([^"]*)"|(-?)(\S+)/u', $input, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      if (($match[2] ?? '') !== '') {
        $words = $this->words($match[2]);
        if (count($words) > 1 && $match[1] === '') {
          $this->phrases[] = $words;
        }
        elseif ($match[1] === '') {
          $this->words = array_merge($this->words, $words);
        }
        else {
          $this->excluded = array_merge($this->excluded, $words);
        }
      }
      elseif (isset($match[4])) {
        $words = $this->words($match[4]);
        if ($match[3] === '-') {
          $this->excluded = array_merge($this->excluded, $words);
        }
        else {
          $this->words = array_merge($this->words, $words);
        }
      }
    }
    $this->words = array_values(array_unique(array_filter($this->words, [$this, 'isIndexed'])));
    $this->phrases = array_values(array_filter($this->phrases, fn($phrase) => (bool) array_filter($phrase, [$this, 'isIndexed'])));
  }

  /**
   * Whether InnoDB FULLTEXT indexes a word (with default server settings).
   */
  protected function isIndexed(string $word): bool {
    return mb_strlen($word) >= self::MIN_WORD_LENGTH && !in_array($word, self::STOPWORDS, TRUE);
  }

  /**
   * The raw input, for redisplay in the search box.
   */
  public function input(): string {
    return $this->input;
  }

  /**
   * Whether there's anything searchable.
   */
  public function isEmpty(): bool {
    return !$this->words && !$this->phrases;
  }

  /**
   * The MySQL boolean-mode expression for MATCH ... AGAINST.
   */
  public function booleanExpression(): string {
    $terms = [];
    foreach ($this->words as $word) {
      $terms[] = '+' . $word . '*';
    }
    foreach ($this->phrases as $phrase) {
      $terms[] = '+"' . implode(' ', $phrase) . '"';
    }
    foreach ($this->excluded as $word) {
      $terms[] = '-' . $word;
    }
    return implode(' ', $terms);
  }

  /**
   * The search terms as a string for the reader's `hl` highlight parameter.
   */
  public function highlightParam(): string {
    $parts = $this->words;
    foreach ($this->phrases as $phrase) {
      $parts[] = '"' . implode(' ', $phrase) . '"';
    }
    return implode(' ', $parts);
  }

  /**
   * Returns an excerpt of $text around the first match, highlighted.
   *
   * @param string $text
   *   Plain passage text.
   * @param int $radius
   *   Characters of context either side of the first match.
   */
  public function snippet(string $text, int $radius = 220): Markup {
    $text = str_replace("\n\n", ' ¶ ', $text);
    $start = 0;
    $pattern = $this->pattern();
    if ($pattern && preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
      // PREG_OFFSET_CAPTURE offsets are bytes; convert to characters.
      $start = max(0, mb_strlen(substr($text, 0, $match[0][1])) - $radius);
    }
    $excerpt = mb_substr($text, $start, $radius * 2 + 80);
    // Trim partial words at either end.
    if ($start > 0) {
      $excerpt = preg_replace('/^\S*\s/u', '', $excerpt);
    }
    if ($start + mb_strlen($excerpt) < mb_strlen($text)) {
      $excerpt = preg_replace('/\s\S*$/u', '', $excerpt);
    }
    return $this->highlight(
      ($start > 0 ? '… ' : '') . $excerpt . ($start + mb_strlen($excerpt) < mb_strlen($text) ? ' …' : '')
    );
  }

  /**
   * HTML-escapes $text, wrapping every match in <mark>.
   *
   * <mark> is styled by the templates' `[&_mark]:` classes, since the
   * theme's Tailwind build only scans .twig/.js/.theme files, not PHP.
   */
  public function highlight(string $text): Markup {
    $pattern = $this->pattern();
    if (!$pattern) {
      return Markup::create(Html::escape($text));
    }
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';
    foreach ($parts as $i => $part) {
      // preg_split with one capture group alternates text/match/text/...
      $html .= $i % 2
        ? '<mark>' . Html::escape($part) . '</mark>'
        : Html::escape($part);
    }
    return Markup::create($html);
  }

  /**
   * A case-insensitive regex matching any word (by prefix) or phrase.
   */
  protected function pattern(): ?string {
    $alternatives = [];
    foreach ($this->phrases as $phrase) {
      $alternatives[] = implode('\W+', array_map(fn($w) => preg_quote($w, '/'), $phrase));
    }
    foreach ($this->words as $word) {
      $alternatives[] = preg_quote($word, '/') . '[\p{L}\p{N}\']*';
    }
    // Longest first, so a phrase wins over one of its own words.
    usort($alternatives, fn($a, $b) => strlen($b) <=> strlen($a));
    return $alternatives ? '/\b(' . implode('|', $alternatives) . ')/iu' : NULL;
  }

  /**
   * Splits text into lowercase words, dropping boolean-operator characters.
   *
   * Apostrophes split words ("Darcy's" -> "darcy", "s"), matching how
   * InnoDB's FULLTEXT tokenizer indexes them.
   *
   * @return string[]
   */
  protected function words(string $text): array {
    $text = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    return array_values(array_filter(explode(' ', trim($text))));
  }

}
