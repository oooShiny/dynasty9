<?php

namespace Drupal\dynasty_plays\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\user\UserInterface;

/**
 * Defines the Player Game Stat entity.
 *
 * Stores a single player's passing, rushing, or receiving stat line for one
 * quarter of one Game. Imported in bulk from the quarterly offensive stats
 * CSV; see \Drupal\dynasty_plays\Commands\QuarterlyStatsImportCommands.
 *
 * @ingroup dynasty_plays
 *
 * @ContentEntityType(
 *   id = "player_game_stat",
 *   label = @Translation("Player Game Stat"),
 *   label_collection = @Translation("Player Game Stats"),
 *   label_singular = @Translation("player game stat"),
 *   label_plural = @Translation("player game stats"),
 *   label_count = @PluralTranslation(
 *     singular = "@count player game stat",
 *     plural = "@count player game stats",
 *   ),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\dynasty_plays\PlayerGameStatListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\dynasty_plays\Form\PlayerGameStatForm",
 *       "add" = "Drupal\dynasty_plays\Form\PlayerGameStatForm",
 *       "edit" = "Drupal\dynasty_plays\Form\PlayerGameStatForm",
 *       "delete" = "Drupal\dynasty_plays\Form\PlayerGameStatDeleteForm",
 *     },
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "player_game_stat",
 *   admin_permission = "administer player game stat entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *     "uuid" = "uuid",
 *     "uid" = "user_id",
 *     "langcode" = "langcode",
 *     "status" = "status",
 *   },
 *   links = {
 *     "canonical" = "/player-game-stat/{player_game_stat}",
 *     "add-form" = "/admin/content/player-game-stat/add",
 *     "edit-form" = "/admin/player-game-stat/{player_game_stat}/edit",
 *     "delete-form" = "/admin/player-game-stat/{player_game_stat}/delete",
 *     "collection" = "/admin/content/player-game-stat",
 *   },
 *   field_ui_base_route = "player_game_stat.settings"
 * )
 */
class PlayerGameStat extends ContentEntityBase implements PlayerGameStatInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
    parent::preCreate($storage_controller, $values);
    $values += [
      'user_id' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    // Auto-generate a readable label if one wasn't set explicitly.
    if ($this->get('name')->isEmpty()) {
      $parts = [
        $this->get('stat_player_name')->value,
        $this->get('stat_category')->value,
        $this->get('stat_quarter')->value,
      ];
      $game = $this->get('stat_game')->entity;
      if ($game) {
        $parts[] = $game->label();
      }
      $this->set('name', implode(' - ', array_filter($parts)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->get('name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setName($name) {
    $this->set('name', $name);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->get('user_id')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('user_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('user_id', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('user_id', $account->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isPublished() {
    return (bool) $this->getEntityKey('status');
  }

  /**
   * {@inheritdoc}
   */
  public function setPublished($published = NULL) {
    if ($published !== NULL) {
      $this->set('status', $published ? TRUE : FALSE);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Authored by'))
      ->setDescription(t('The user ID of author of the Player Game Stat entity.'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The label of the Player Game Stat entity. Auto-generated if left blank.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Publishing status'))
      ->setDescription(t('A boolean indicating whether the Player Game Stat is published.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 20,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    // Game field - entity reference to Game nodes.
    $fields['stat_game'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Game'))
      ->setDescription(t('The Game this stat line belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'game' => 'game',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 0,
        'settings' => [
          'link' => TRUE,
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 0,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Player Name field - raw player label as it appears in the source data
    // (e.g. "D. Bledsoe"). Always populated, regardless of whether a Player
    // node could be confidently matched.
    $fields['stat_player_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Player Name'))
      ->setDescription(t('The player name as it appears in the source data.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 100,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Player field - entity reference to Player nodes, populated only when
    // stat_player_name could be confidently matched to a single Player node.
    $fields['stat_player'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Player'))
      ->setDescription(t('The matched Player node, if one could be determined unambiguously.'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'player' => 'player',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 2,
        'settings' => [
          'link' => TRUE,
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 2,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Quarter field.
    $fields['stat_quarter'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Quarter'))
      ->setDescription(t('The quarter this stat line covers.'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'Q1' => t('1st Quarter'),
          'Q2' => t('2nd Quarter'),
          'Q3' => t('3rd Quarter'),
          'Q4' => t('4th Quarter'),
          'OT' => t('Overtime'),
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Category field.
    $fields['stat_category'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Category'))
      ->setDescription(t('The stat category this line reports.'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'Passing' => t('Passing'),
          'Rushing' => t('Rushing'),
          'Receiving' => t('Receiving'),
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Passing stats.
    $passing_fields = [
      'stat_completions' => t('Completions'),
      'stat_attempts' => t('Attempts'),
      'stat_pass_yards' => t('Passing Yards'),
      'stat_interceptions' => t('Interceptions'),
      'stat_pass_td' => t('Passing Touchdowns'),
    ];

    // Rushing stats.
    $rushing_fields = [
      'stat_carries' => t('Carries'),
      'stat_rush_yards' => t('Rushing Yards'),
      'stat_rush_td' => t('Rushing Touchdowns'),
    ];

    // Receiving stats.
    $receiving_fields = [
      'stat_targets' => t('Targets'),
      'stat_receptions' => t('Receptions'),
      'stat_rec_yards' => t('Receiving Yards'),
      'stat_rec_td' => t('Receiving Touchdowns'),
    ];

    $weight = 5;
    foreach ($passing_fields + $rushing_fields + $receiving_fields as $field_name => $label) {
      $fields[$field_name] = BaseFieldDefinition::create('integer')
        ->setLabel($label)
        ->setDescription(t('Only populated for rows in the matching stat category.'))
        ->setDisplayOptions('view', [
          'label' => 'above',
          'type' => 'number_integer',
          'weight' => $weight,
        ])
        ->setDisplayOptions('form', [
          'type' => 'number',
          'weight' => $weight,
        ])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
      $weight++;
    }

    return $fields;
  }

}
