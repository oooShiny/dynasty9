/* eslint func-names: ["error", "never"] */
(function(wp, $, Drupal, drupalSettings, DrupalGutenberg) {
  const { blocks, blockEditor, data } = wp;
  const { dispatch } = data;
  const { RichText } = blockEditor;
  const { DrupalMediaEntity } = DrupalGutenberg.Components;
  const gutenberg = drupalSettings.gutenberg || {};
  const isMediaLibraryEnabled = gutenberg['media-library-enabled'] || false;
  const isMediaEnabled = gutenberg['media-enabled'] || false;
  const __ = Drupal.t;
  const editorSettings = drupalSettings.editor;
  var gutenbergSettings = null;
  if (editorSettings && editorSettings.formats) {
    Object.keys(editorSettings.formats).forEach(function (key) {
      const editorSetting = editorSettings.formats[key];
      if (editorSetting.editor !== 'gutenberg') {
        return;
      }
      gutenbergSettings = editorSetting.editorSettings;
    })
  }

  const registerBlock = () => {
    const blockId = 'drupalmedia/drupal-media-entity';
    if (!gutenbergSettings || !gutenbergSettings.allowedDrupalBlocks) {
      // Totally not enabled, that's for sure.
      return;
    }
    if (!gutenbergSettings.allowedDrupalBlocks.includes(blockId)) {
      return;
    }

    blocks.registerBlockType(blockId, {
      title: Drupal.t('Media'),
      icon: 'admin-media',
      category: 'common',
      supports: {
        align: true,
        html: false,
        reusable: false,
      },
      attributes: {
        mediaEntityIds: {
          type: 'array',
        },
        viewMode: {
          type: 'string',
          default: 'default',
        },
        caption: {
          type: 'string',
          default: '',
        },
        lockViewMode: {
          type: 'boolean',
          default: false,
        },
        allowedTypes: {
          type: 'array',
          default: ['image', 'video', 'audio', 'application'],
        },
      },
      edit({ attributes, className, setAttributes, isSelected, clientId }) {
        const { mediaEntityIds, caption } = attributes;

        return (
          <figure className={className}>
            <DrupalMediaEntity
              attributes={attributes}
              className={className}
              setAttributes={setAttributes}
              isSelected={isSelected}
              isMediaLibraryEnabled={isMediaLibraryEnabled}
              clientId={clientId}
              onError={error => {
                error = typeof error === 'string' ? error : error[2];
                dispatch('core/notices').createWarningNotice(error);
              }}
            />
            {mediaEntityIds &&
              mediaEntityIds.length > 0 &&
              (!RichText.isEmpty(caption) || isSelected) && (
                <RichText
                  tagName="figcaption"
                  placeholder={__('Write caption…')}
                  value={caption}
                  onChange={value => setAttributes({ caption: value })}
                />
              )}
          </figure>
        );
      },
      save() {
        return null;
      },
    });
  };

  const registerDrupalMedia = () =>
    new Promise(resolve => {
      if (isMediaEnabled) {
        registerBlock();
      }

      resolve();
    });

  window.DrupalGutenberg = window.DrupalGutenberg || {};
  window.DrupalGutenberg.registerDrupalMedia = registerDrupalMedia;
})(wp, jQuery, Drupal, drupalSettings, DrupalGutenberg);
