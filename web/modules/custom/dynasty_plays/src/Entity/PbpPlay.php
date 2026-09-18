<?php

namespace Drupal\dynasty_plays\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\user\UserInterface;

/**
 * Defines the Play-by-Play entity.
 *
 * Stores a single raw play-by-play row (one row per play event, covering all
 * imported seasons, pre- and post-2000). Unlike PlayerGameStat, this isn't a
 * summed report -- it keeps the original play text (pbp_detail) plus down/
 * distance/score/time context, meant for browsing and searching. It's also
 * classified: pbp_play_type gives each row a canonical play type, and the
 * pbp_passer/pbp_rusher/pbp_receiver/etc. fields tag the specific players
 * involved by role (see the field definitions below for the full role
 * list) -- both populated at import time from the source CSV, not derived
 * from PlayerGameStat's aggregated numbers.
 *
 * @ingroup dynasty_plays
 *
 * @ContentEntityType(
 *   id = "pbp_play",
 *   label = @Translation("Play-by-Play Entry"),
 *   label_collection = @Translation("Play-by-Play Entries"),
 *   label_singular = @Translation("play-by-play entry"),
 *   label_plural = @Translation("play-by-play entries"),
 *   label_count = @PluralTranslation(
 *     singular = "@count play-by-play entry",
 *     plural = "@count play-by-play entries",
 *   ),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\dynasty_plays\PbpPlayListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\dynasty_plays\Form\PbpPlayForm",
 *       "add" = "Drupal\dynasty_plays\Form\PbpPlayForm",
 *       "edit" = "Drupal\dynasty_plays\Form\PbpPlayForm",
 *       "delete" = "Drupal\dynasty_plays\Form\PbpPlayDeleteForm",
 *     },
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "pbp_play",
 *   admin_permission = "administer pbp play entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *     "uuid" = "uuid",
 *     "uid" = "user_id",
 *     "langcode" = "langcode",
 *     "status" = "status",
 *   },
 *   links = {
 *     "canonical" = "/pbp-play/{pbp_play}",
 *     "add-form" = "/admin/content/pbp-play/add",
 *     "edit-form" = "/admin/pbp-play/{pbp_play}/edit",
 *     "delete-form" = "/admin/pbp-play/{pbp_play}/delete",
 *     "collection" = "/admin/content/pbp-play",
 *   },
 *   field_ui_base_route = "pbp_play.settings"
 * )
 */
class PbpPlay extends ContentEntityBase implements PbpPlayInterface {

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

    if ($this->get('name')->isEmpty()) {
      $parts = [];
      $game = $this->get('pbp_game')->entity;
      if ($game) {
        $parts[] = $game->label();
      }
      $parts[] = $this->get('pbp_quarter')->value;
      $detail = $this->get('pbp_detail')->value;
      if ($detail) {
        $parts[] = mb_strimwidth($detail, 0, 60, '…');
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
      ->setDescription(t('The user ID of author of the Play-by-Play entry.'))
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
      ->setDescription(t('The label of the Play-by-Play entry. Auto-generated if left blank.'))
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
      ->setDescription(t('A boolean indicating whether the Play-by-Play entry is published.'))
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
    $fields['pbp_game'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Game'))
      ->setDescription(t('The Game this play occurred in.'))
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

    // Sequence field - preserves the original row order within a game,
    // since quarter/time alone don't uniquely order plays (e.g. penalty
    // rows often share the previous play's time, or have no time at all).
    $fields['pbp_sequence'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Sequence'))
      ->setDescription(t('The order this play occurred in within its game (1-based, source row order).'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Quarter field.
    $fields['pbp_quarter'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Quarter'))
      ->setDescription(t('The quarter this play occurred in.'))
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
        'weight' => 2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Time field - clock display, e.g. "12:34". Free text since some rows
    // (penalties, coin toss, etc.) don't have one.
    $fields['pbp_time'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Time'))
      ->setDescription(t('Time remaining in the quarter, e.g. "12:34".'))
      ->setSettings([
        'max_length' => 8,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Down field.
    $fields['pbp_down'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Down'))
      ->setDescription(t('The down (1-4), where applicable.'))
      ->setSettings([
        'min' => 1,
        'max' => 4,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Distance field - yards to go.
    $fields['pbp_distance'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Distance'))
      ->setDescription(t('Yards to go for a first down, where applicable.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Location field - e.g. "NWE 35".
    $fields['pbp_location'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Location'))
      ->setDescription(t('Field position at the start of the play, e.g. "NWE 35".'))
      ->setSettings([
        'max_length' => 20,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Score fields - running score at the time of the play.
    $fields['pbp_patriots_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Patriots Score'))
      ->setDescription(t('The Patriots score at the time of this play.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 7,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['pbp_opponent_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Opponent Score'))
      ->setDescription(t('The opponent score at the time of this play.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 8,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Detail field - the play-by-play description text.
    $fields['pbp_detail'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Detail'))
      ->setDescription(t('The play-by-play description.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 9,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 9,
        'settings' => [
          'rows' => 3,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Expected points before/after this play (advanced analytics, from the
    // source data; not all rows have these).
    $fields['pbp_epb'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Expected Points Before'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_decimal',
        'weight' => 10,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['pbp_epa'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Expected Points Added'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_decimal',
        'weight' => 11,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Source URL - the Pro Football Reference box score this was scraped
    // from, kept for provenance/verification.
    $fields['pbp_source_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Source URL'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'uri_link',
        'weight' => 12,
      ])
      ->setDisplayOptions('form', [
        'type' => 'uri',
        'weight' => 12,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Player field - best-effort match of the primary player named in
    // pbp_detail, parsed at import time. Deliberately single-value and NOT
    // translatable/multi-cardinality: this keeps it a plain column on the
    // pbp_play base table (like pbp_game already is), so the raw SQL query
    // in SearchDataController::playByPlay() can keep reading it directly
    // without a join, which matters at ~61,000 rows.
    $fields['pbp_player'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Player'))
      ->setDescription(t('Best-effort match of the primary player in this play, parsed from the detail text. Empty when no single confident match was found.'))
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
        'weight' => 13,
        'settings' => [
          'link' => TRUE,
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 13,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Scoring Play field - computed at import time from a running-score
    // delta against the previous row in the same game (reliable; not a
    // text-pattern guess). Replaces the old `play` entity's scoring_play
    // field, now derivable for every imported season instead of just one.
    $fields['pbp_scoring_play'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Scoring Play'))
      ->setDescription(t('Whether the running score changed on this play, relative to the previous play in the same game.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 14,
        'settings' => [
          'format' => 'yes-no',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 14,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Scoring Team field - 'Patriots' if the Patriots' score increased on
    // this play, the opponent's name (from the source CSV's own `opponent`
    // column) if the opponent's score increased, NULL otherwise.
    $fields['pbp_scoring_team'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Scoring Team'))
      ->setDescription(t('The team that scored on this play, if any.'))
      ->setSettings([
        'max_length' => 50,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 15,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Highlight field - manually-curated link to a Highlight node for this
    // play, where one exists. Deliberately single-value and NOT
    // translatable/multi-cardinality, same reasoning as pbp_player: keeps
    // this a plain column on the pbp_play base table so
    // SearchDataController::playByPlay()'s raw SQL query can keep reading
    // it directly. Populated only via manual curation, not fuzzy
    // auto-matching (the same scope decision made for pbp_player's
    // matchPlayerInDetail(), applied here: matching a play's text against
    // a highlight's context was judged lower-value/higher-risk).
    $fields['pbp_highlight'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Highlight'))
      ->setDescription(t('The Highlight video for this play, if one has been manually linked.'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'highlight' => 'highlight',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 16,
        'settings' => [
          'link' => TRUE,
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 16,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Play Type field - the canonical play type, populated at import time
    // either straight from nflfastR's own `play_type` column (2000+,
    // already this exact vocabulary) or derived by regex against the PFR
    // prose `detail` text (pre-2000, see
    // scripts/ne_legacy_parser.py/enrich-legacy-pbp.py in the nfldata.org
    // sibling project that produces the CSVs this module imports).
    $fields['pbp_play_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Play Type'))
      ->setDescription(t('The canonical play type for this play.'))
      ->setSettings([
        'allowed_values' => [
          'pass' => t('Pass'),
          'run' => t('Run'),
          'punt' => t('Punt'),
          'kickoff' => t('Kickoff'),
          'field_goal' => t('Field Goal'),
          'extra_point' => t('Extra Point'),
          'qb_kneel' => t('QB Kneel'),
          'qb_spike' => t('QB Spike'),
          'no_play' => t('No Play'),
          'penalty' => t('Penalty'),
          'other' => t('Other'),
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 17,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 17,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Two Point Attempt field - whether this play was a two-point
    // conversion try. pbp_play_type still reflects the underlying pass/
    // run, same as nflfastR's own scheme (a two-point try isn't its own
    // play_type, just a flag alongside one).
    $fields['pbp_two_point_attempt'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Two Point Attempt'))
      ->setDescription(t('Whether this play was a two-point conversion attempt.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 18,
        'settings' => [
          'format' => 'yes-no',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 18,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Per-role player fields. Each is a best-effort match (same
    // GamePlayerMatcher::matchPlayer() used everywhere else in this
    // module) of a player name that arrives from the source CSV already
    // tagged with its role -- nflfastR's own passer_player_name/
    // rusher_player_name/etc. columns for 2000+, or enrich-legacy-pbp.py's
    // regex-captured equivalents for pre-2000 -- rather than parsed from
    // free text at import time the way pbp_player above still is. A play
    // can need several of these at once (e.g. a pass_complete has both a
    // passer and a receiver; a rush with a fumble has a rusher plus
    // forced_fumble_player/fumble_recovery_player), which is the "assign
    // as many players as needed" this set of fields exists for.
    //
    // Each is deliberately single-value (no ->setCardinality() override)
    // and NOT translatable, same reasoning as pbp_player/pbp_highlight
    // above: this keeps every one of them a plain column on the
    // `pbp_play` base table, so SearchDataController::playByPlay()'s raw
    // SQL select can keep reading them directly -- no join, no per-row
    // Entity API load -- at ~135,000 rows. A handful of rarer roles
    // (a 3rd/4th assisted tackler, a split sack's second player) are
    // dropped rather than modeled, the same fixed-columns tradeoff
    // enrich-legacy-pbp.py/extract-patriots-pbp.py already made when
    // producing the source CSVs.
    $weight = 19;
    foreach ([
      'pbp_passer' => [t('Passer'), t('The player who threw the pass, on a pass play.')],
      'pbp_rusher' => [t('Rusher'), t('The ball carrier, on a run play.')],
      'pbp_receiver' => [t('Receiver'), t('The intended or actual receiver, on a pass play.')],
      'pbp_interceptor' => [t('Interceptor'), t('The player who intercepted the pass, if any.')],
      'pbp_sacker' => [t('Sacker'), t('The defender credited with the sack, if any.')],
      'pbp_punter' => [t('Punter'), t('The punter, on a punt play.')],
      'pbp_kicker' => [t('Kicker'), t('The kicker, on a kickoff/field goal/extra point play.')],
      'pbp_returner' => [t('Returner'), t('The player who returned the kick/punt, if any.')],
      'pbp_blocker' => [t('Blocker'), t('The defender who blocked the kick/punt, if any.')],
      'pbp_tackler_1' => [t('Tackler 1'), t('The first player credited with the tackle, if any.')],
      'pbp_tackler_2' => [t('Tackler 2'), t('The second player credited with the tackle (an assist), if any.')],
      'pbp_forced_fumble_player' => [t('Forced Fumble By'), t('The defender who forced a fumble, if any.')],
      'pbp_fumble_recovery_player' => [t('Fumble Recovered By'), t('The player who recovered a fumble, if any.')],
      'pbp_penalized_player' => [t('Penalized Player'), t('The player a penalty was called on, if the source names one rather than just a team.')],
    ] as $field_name => [$label, $description]) {
      $fields[$field_name] = BaseFieldDefinition::create('entity_reference')
        ->setLabel($label)
        ->setDescription($description)
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
          'weight' => $weight,
          'settings' => [
            'link' => TRUE,
          ],
        ])
        ->setDisplayOptions('form', [
          'type' => 'entity_reference_autocomplete',
          'weight' => $weight,
          'settings' => [
            'match_operator' => 'CONTAINS',
            'size' => '60',
            'autocomplete_type' => 'tags',
            'placeholder' => '',
          ],
        ])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
      $weight++;
    }

    return $fields;
  }

}
