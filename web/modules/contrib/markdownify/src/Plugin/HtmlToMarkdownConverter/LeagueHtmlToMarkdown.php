<?php

namespace Drupal\markdownify\Plugin\HtmlToMarkdownConverter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\markdownify\Attribute\HtmlToMarkdownConverter;
use Drupal\markdownify\HtmlToMarkdownConverterBase;
use Drupal\markdownify\MarkdownifyHtmlConverterInterface;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * The plugin to convert html to markdown, that uses league package.
 */
#[HtmlToMarkdownConverter(
  id: 'league',
  label: new TranslatableMarkup('League Html to Markdown'),
)]
class LeagueHtmlToMarkdown extends HtmlToMarkdownConverterBase implements MarkdownifyHtmlConverterInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'header_style' => 'atx',
      'suppress_errors' => TRUE,
      'strip_tags' => TRUE,
      'strip_placeholder_links' => FALSE,
      'bold_style' => '**',
      'italic_style' => '*',
      'remove_nodes' => '',
      'hard_break' => FALSE,
      'list_item_style' => '-',
      'preserve_comments' => FALSE,
      'use_autolinks' => TRUE,
      'table_pipe_escape' => '\|',
      'table_caption_side' => 'top',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function convert(string $html, ?BubbleableMetadata $metadata = NULL): string {
    // Initialize the HTML-to-Markdown converter with specific options.
    $converter = new HtmlConverter($this->configuration);
    // Convert the HTML to Markdown.
    return $converter->convert($html);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['header_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Header style'),
      '#description' => $this->t('Set to "atx" to output H1 and H2 headers as # Header1 and ## Header2.'),
      '#options' => [
        'atx' => $this->t('atx'),
        'setext' => $this->t('setext'),
      ],
      '#default_value' => $this->configuration['header_style'] ?? 'atx',
    ];
    $form['suppress_errors'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Suppress errors'),
      '#description' => $this->t('Set to false to show warnings when loading malformed HTML.'),
      '#default_value' => $this->configuration['suppress_errors'] ?? TRUE,
      '#return_value' => TRUE,
    ];
    $form['strip_tags'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Strip tags'),
      '#description' => $this->t("Set to true to strip tags that don't have markdown equivalents. N.B. Strips tags, not their content. Useful to clean MS Word HTML output."),
      '#default_value' => $this->configuration['strip_tags'] ?? TRUE,
      '#return_value' => TRUE,
    ];
    $form['strip_placeholder_links'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Strip placeholder links'),
      '#description' => $this->t("Set to true to remove 'a' that doesn't have href."),
      '#default_value' => $this->configuration['strip_placeholder_links'] ?? FALSE,
      '#return_value' => TRUE,
    ];
    $form['bold_style'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bold style'),
      '#description' => $this->t("DEPRECATED: Set to '__' if you prefer the underlined style."),
      '#default_value' => $this->configuration['bold_style'] ?? '',
    ];
    $form['italic_style'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Italic style'),
      '#description' => $this->t("DEPRECATED: Set to '_' if you prefer the underlined style."),
      '#default_value' => $this->configuration['italic_style'] ?? '',
    ];
    $form['remove_nodes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Remove nodes'),
      '#description' => $this->t("Space-separated list of dom nodes that should be removed. example: 'meta style script'"),
      '#default_value' => $this->configuration['remove_nodes'] ?? '',
    ];
    $form['hard_break'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hard break'),
      '#description' => $this->t("Set to true to turn 'br' into a simple newline '\\n' instead of two spaces followed by newline '&nbsp;  \\n'"),
      '#default_value' => $this->configuration['hard_break'] ?? FALSE,
      '#return_value' => TRUE,
    ];
    $form['list_item_style'] = [
      '#type' => 'select',
      '#title' => $this->t('List item style'),
      '#description' => $this->t("Set the default character for each 'li' in a 'ul'."),
      '#options' => [
        '-' => '-',
        '+' => '+',
        '*' => '*',
      ],
      '#default_value' => $this->configuration['list_item_style'] ?? '*',
    ];
    $form['preserve_comments'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Preserve comments'),
      '#description' => $this->t("Set to true to preserve comments, or set to an array of strings to preserve specific comments"),
      '#default_value' => $this->configuration['preserve_comments'] ?? FALSE,
      '#return_value' => TRUE,
    ];
    $form['use_autolinks'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use autolinks'),
      '#description' => $this->t("Set to true to use simple link syntax if possible. Will always use []() if set to false"),
      '#default_value' => $this->configuration['use_autolinks'] ?? FALSE,
      '#return_value' => TRUE,
    ];
    $form['table_pipe_escape'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Table pipe escape'),
      '#description' => $this->t('Replacement string for pipe characters inside markdown table cells'),
      '#default_value' => $this->configuration['table_pipe_escape'] ?? '',
    ];
    $form['table_caption_side'] = [
      '#type' => 'select',
      '#title' => $this->t('Table caption side'),
      '#description' => $this->t("Set to 'top' or 'bottom' to show 'caption' content before or after table, null to suppress"),
      '#options' => [
        '' => $this->t('No value'),
        'top' => $this->t('Top'),
        'bottom' => $this->t('Bottom'),
      ],
      '#default_value' => $this->configuration['table_caption_side'] ?? '',
    ];
    return $form;
  }

}
