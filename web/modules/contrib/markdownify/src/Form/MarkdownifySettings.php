<?php

namespace Drupal\markdownify\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contains configuration page for markdownify settings.
 */
class MarkdownifySettings extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The converter plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected PluginManagerInterface $converterPluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->converterPluginManager = $container->get('plugin.manager.html_to_markdown_converter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['markdownify.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'markdownify_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('markdownify.settings');
    $options = $this->getContentEntityTypeOptions();
    $default_values = [];
    $supported_entity_types = $config->get('supported_entity_types');
    if (!empty($supported_entity_types)) {
      $default_values = array_combine($supported_entity_types, $supported_entity_types);
    }
    $form['supported_entity_types'] = [
      '#type' => 'checkboxes',
      '#options' => $options,
      '#title' => $this->t('Supported entity types'),
      '#default_value' => $default_values,
    ];
    $converters = [];
    $converter_definitions = $this->converterPluginManager->getDefinitions();
    foreach ($converter_definitions as $converter_id => $converter) {
      $converters[$converter_id] = $converter['label'];
    }
    $form['default_converter'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default converter'),
      '#default_value' => $config->get('default_converter'),
      '#options' => $converters,
    ];
    $conversion_settings = $config->get('converters');
    $form['converters'] = [
      '#type' => 'details',
      '#title' => $this->t('Converter settings'),
      '#tree' => TRUE,
    ];
    foreach ($converter_definitions as $converter_id => $converter) {
      $instance = $this->converterPluginManager->createInstance($converter_id, $conversion_settings[$converter_id] ?? []);
      $subform_state = SubformState::createForSubform($form['converters'], $form, $form_state);
      $form['converters'][$converter_id] = $instance->buildConfigurationForm([], $subform_state);
      $form['converters'][$converter_id] += [
        '#type' => 'details',
        '#title' => $this->t('League Html to Markdown'),
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->cleanValues();
    $values = $form_state->getValues();
    $entity_types = array_filter($values['supported_entity_types']);
    $this->config('markdownify.settings')
      ->set('default_converter', $values['default_converter'])
      ->set('converters', $values['converters'])
      ->set('supported_entity_types', array_keys($entity_types))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Builds a list of options for content entity types.
   *
   * @return array
   *   An associative array of entity type IDs and their labels, sorted
   *   alphabetically.
   */
  protected function getContentEntityTypeOptions(): array {
    $entity_types = $this->entityTypeManager->getDefinitions();
    $options = [];
    foreach ($entity_types as $entity_type_id => $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface) {
        $options[$entity_type_id] = $entity_type->getLabel() ?: $entity_type_id;
      }
    }
    asort($options, SORT_FLAG_CASE);
    return $options;
  }

}
