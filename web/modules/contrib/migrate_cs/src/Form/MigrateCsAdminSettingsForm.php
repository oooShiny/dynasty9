<?php

namespace Drupal\migrate_cs\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Migrate Cron Scheduler settings for this site.
 */
class MigrateCsAdminSettingsForm extends ConfigFormBase implements ContainerInjectionInterface {

  /**
   * The migration plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected $migratePluginManager;

  /**
   * Constructs a new MigrateCsAdminSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $plugin_manager
   *   The migration plugin manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, MigrationPluginManagerInterface $plugin_manager) {
    parent::__construct($config_factory);
    $this->migratePluginManager = $plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('plugin.manager.migration')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'migrate_cs_options';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['migrate_cs.options'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $configOptions = $this->config('migrate_cs.options');
    $migrations = $this->migratePluginManager->getDefinitions();
    if ($migrations) {
      foreach ($migrations as $migration) {

        $migrationId = $migration['id'];

        $form[$migrationId] = [
          '#type' => 'details',
          '#title' => $migration['label'],
          '#description' => $this->t("Migrate cron scheduler settings for <em>@migration_id</em>", ['@migration_id' => $migrationId]),
          '#open' => TRUE,
        ];
        $form[$migrationId]["{$migrationId}_cs"] = [
          '#description' => $this->t('If checked, migration will run at cron.'),
          '#title' => $this->t('Run at cron'),
          '#type' => 'checkbox',
          '#default_value' => $configOptions->get("{$migrationId}_cs"),
        ];

        $attributes = [
          'data-type' => 'number',
        ];
        if ($configOptions->get("{$migrationId}_cs") == FALSE) {
          $attributes['disabled'] = TRUE;
        }

        $form[$migrationId]["{$migrationId}_interval"] = [
          '#maxlength' => 20,
          '#size' => 10,
          '#title' => 'Run at interval',
          '#description' => $this->t("The interval (in seconds) the migration should run.<br/>If left empty or the value is lower then the cron interval - migration will run at each cron."),
          '#type' => 'number',
          '#default_value' => ($configOptions->get("{$migrationId}_cs") == FALSE ? NULL : $configOptions->get("{$migrationId}_interval")),
          '#attributes' => $attributes,
          '#states' => [
            'disabled' => [
              ':input[name="' . $migrationId . '_cs"]' => ['checked' => FALSE],
            ],
          ],
        ];

        $form[$migrationId]["{$migrationId}_type"] = [
          '#type' => 'radios',
          '#title' => $this->t('Migration option'),
          '#description' => $this->t("If selected adds the flag to the migration.<br>Update: In addition to processing unimported items from the source, update previously-imported items with new data.<br>Sync: Like 'update' but also removes items missing from the source."),
          '#default_value' => ($configOptions->get("{$migrationId}_type") ? $configOptions->get("{$migrationId}_type") : 'normal'),
          '#options' => [
            'normal' => $this->t('Normal'),
            'update' => $this->t('Update'),
            'sync' => $this->t('Sync'),
          ],
          '#attributes' => $attributes,
          '#states' => [
            'disabled' => [
              ':input[name="' . $migrationId . '_cs"]' => ['checked' => FALSE],
            ],
          ],
        ];
      }
    }
    else {
      $form['migrations']['empty'] = [
        '#markup' => $this->t('There are no migrations.'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $configOptions = $this->config('migrate_cs.options');
    $migrations = $this->migratePluginManager->getDefinitions();
    if ($migrations) {
      foreach ($migrations as $migration) {
        $migrationId = $migration['id'];
        $configOptions
          ->set("{$migrationId}_cs", $form_state->getValue("{$migrationId}_cs"))
          ->set("{$migrationId}_interval", $form_state->getValue("{$migrationId}_interval"))
          ->set("{$migrationId}_type", $form_state->getValue("{$migrationId}_type"))
          ->save();
      }
    }
  }

}
