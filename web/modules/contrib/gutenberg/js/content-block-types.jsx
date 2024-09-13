// import { data } from '@frontkom/gutenberg-js';
// import DrupalBlock from './components/drupal-block';
// import DrupalIcon from './components/drupal-icon';

/* eslint func-names: ["error", "never"] */
(function(wp, $, Drupal) {
  const { data, blocks, blockEditor } = wp;
  const { useBlockProps } = blockEditor;
  const { DrupalIcon, ContentBlock } = window.DrupalGutenberg.Components;

  function registerBlock(id, definition) {
    const blockId = `content-block/${id}`.replace(/_/g, '-').replace(/:/g, '-');

    blocks.registerBlockType(blockId, {
      version: 2,
      title: `${definition.label}`,
      description: `${definition.description}`,
      icon: DrupalIcon,
      category: 'content-blocks',
      supports: {
        align: true,
        html: false,
        reusable: false,
        color: true,
        spacing: {
          padding: true,
          margin: true,
        },
      },
      attributes: {
        type: {
          type: 'string',
        },
        contentBlockId: {
          type: 'string',
        },
        viewMode: {
          type: 'string',
        },
        settings: {
          type: 'object',
        },
        align: {
          type: 'string',
        },
      },
      edit({ attributes, className, setAttributes }) {
        const { settings, contentBlockId, viewMode } = attributes;
        setAttributes({ type: id });

        return (
          // eslint-disable-next-line react/jsx-props-no-spreading, react-hooks/rules-of-hooks
          <ContentBlock { ...useBlockProps() }
            className={className}
            type={id}
            contentBlockId={contentBlockId}
            viewMode={viewMode}
            name={definition.label}
            settings={settings}
            onViewModeChange={ newViewMode => setAttributes({ viewMode: newViewMode }) }
          />
        );
      },
      save() {
        return (
          <div { ...useBlockProps.save() }>
          </div>
        );
      },
      deprecated: [
        {
          attributes: {
            type: {
              type: 'string',
            },
            contentBlockId: {
              type: 'string',
            },
            viewMode: {
              type: 'string',
            },
            settings: {
              type: 'object',
            },
            align: {
              type: 'string',
            },
          },    
          save() {
            return null;
          }
        },
      ],
    });
  }

  function registerContentBlocks(contentType) {
    return new Promise(resolve => {
      $.ajax(Drupal.url(`editor/content_block_types/load/${contentType}`)).done(
        definitions => {
          const category = {
            slug: 'content-blocks',
            title: Drupal.t('Content Blocks'),
          };

          const categories = [
            ...data.select('core/blocks').getCategories(),
            category,
          ];

          data.dispatch('core/blocks').setCategories(categories);

          /* eslint no-restricted-syntax: ["error", "never"] */
          for (const id in definitions) {
            if ({}.hasOwnProperty.call(definitions, id)) {
              const definition = definitions[id];
              if (definition) {
                registerBlock(id, definition);
              }
            }
          }
          resolve();
        },
      );
    });
  }

  window.DrupalGutenberg = window.DrupalGutenberg || {};
  window.DrupalGutenberg.registerContentBlocks = registerContentBlocks;
})(wp, jQuery, Drupal, drupalSettings);
