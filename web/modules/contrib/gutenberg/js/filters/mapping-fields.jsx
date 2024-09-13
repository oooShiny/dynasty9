(async (wp, Drupal) => {
  const { blockEditor, components, compose, element, hooks, i18n } = wp;
  const { __ } = i18n;
  const { useState, useEffect } = element;
  const { addFilter } = hooks;
  const { createHigherOrderComponent } = compose;
  const { Card, CardBody, CardHeader, PanelBody } = components;
  const { InspectorControls } = blockEditor;

  function hasMappingFields(attributes) {
    return (
      attributes &&
      attributes.mappingFields &&
      Array.isArray(attributes.mappingFields)
    );
  }

  function setClassName(value, className) {
    if (!className) {
      return value;
    }

    return value ? `${className} ${value}` : className;
  }

  const withInspectorControl = createHigherOrderComponent(
    BlockEdit => props => {
      const { attributes, setAttributes, className } = props;
      const hasMapping = hasMappingFields(attributes);
      const { mappingFields } = attributes;
      const {
        mappingFields: drupalMappingFields,
      } = drupalSettings.gutenberg;
      const [isRequired, setIsRequired] = useState(false);
      const [isTranslatable, setIsTranslatable] = useState(false);
      const [isFieldEmpty, setIsFieldEmpty] = useState(true);

      useEffect(() => {
        // Check if any attribute is mapped to a required field in the mapping fields.
        if (hasMapping && isRequired) {
          setIsFieldEmpty(true);
          mappingFields.forEach(field => {
            const possibleAttributes = [field.attribute || 'content', 'value', 'text'];
            possibleAttributes.forEach(attribute => {
              const value = attributes[attribute] || '';
              if (value) {
                setIsFieldEmpty(false);
              }
            });
          });
        }
      }, [attributes]);

      useEffect(() => {
        if (hasMapping) {
          mappingFields.forEach(mappingField => {
            const drupalField = drupalMappingFields[mappingField.field];
            if (!drupalField) {
              return;
            }

            const property = mappingField.property || 'value';
            const attribute = mappingField.attribute || 'content';

            let fieldValue;
            if (drupalField.value[0]) {
              fieldValue = drupalField.value[0][property];
            }

            let value = {};
            // Exception for media blocks.
            if (attribute == 'mediaEntityIds') {
              value = {
                [`${attribute}`]: [fieldValue+''],
              };
            } else {
              value = {
                [`${attribute}`]: fieldValue,
              };
            }

            if (fieldValue) setAttributes(value);
  
            if (drupalField.required) {
              setIsRequired(true);
            }

            if (drupalField.translatable) {
              setIsTranslatable(true);
            }
          });
        }
      }, []);

      return (
        <>
          <BlockEdit {...props} className={isFieldEmpty ? setClassName('is-required', className) : className} />
          {hasMapping && (
            <InspectorControls>
            {!attributes.lockViewMode && (
              <PanelBody title={__('Field mapping')} initialOpen>
                {attributes.mappingFields.map(field => {
                  let content;
                  const property = field.property || 'value';
                  if (field.attribute) {
                    content = __(
                      'The block attribute <strong>@attribute</strong> is mapped to the <strong>@field (@property)</strong> field.',
                      {
                        '@attribute': field.attribute,
                        '@field': drupalMappingFields[field.field].label,
                        '@property': property,
                      },
                    );
                  } else {
                    content = __(
                      'The block content is mapped to the <strong>@field (@property)</strong> field.',
                      {
                        '@field': drupalMappingFields[field.field].label,
                        '@property': property,
                      },
                    );
                  }

                  if (isRequired) {
                    content = `${content}<br/><em>${__('The field is required.')}</em>`;
                  }

                  if (!isTranslatable) {
                    content = `${content}<br/><em>${__('Changing the value of this field will change it for all translations.')}</em>`;
                  }

                  return (
                    <Card>
                      {field.label && (
                        <CardHeader>
                          <strong>{field.label}</strong>
                        </CardHeader>
                      )}
                      <CardBody>
                        <div
                          className="mapping-fields-summary"
                          // eslint-disable-next-line react/no-danger
                          dangerouslySetInnerHTML={{ __html: content }}
                        />
                      </CardBody>
                    </Card>
                  );
                })}
              </PanelBody>
            )}
            </InspectorControls>
          )}
        </>
      );
    },
    'withInspectorControl',
  );

  addFilter(
    'blocks.registerBlockType',
    'drupalgutenberg/mapping-fields-attributes',
    settings => {
      settings.attributes = Object.assign(settings.attributes, {
        mappingFields: {
          type: 'array',
        },
      });

      return settings;
    },
  );

  addFilter(
    'editor.BlockEdit',
    'core/editor/mapping-fields-attributes/with-inspector-control',
    withInspectorControl,
  );
})(wp, Drupal);
