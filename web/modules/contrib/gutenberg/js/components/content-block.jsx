// eslint-disable-next-line func-names
(function (Drupal, wp, drupalSettings) {
  const { blockEditor, components, element } = wp;
  const { useState, useEffect, useCallback } = element;
  const { InspectorControls } = blockEditor;
  const { SelectControl, Card, CardBody, Placeholder, Spinner } = components;
  const __ = Drupal.t;

  function openBlockSettings(type, contentBlockId) {
    const entityId = drupalSettings.gutenberg.entityId || null;
    const entityType = 'node';
    const entityBundle = drupalSettings.gutenberg.nodeType;
    const ajaxSettings = {
      url: Drupal.url(`editor/content_block_type/settings/${type}/${contentBlockId}/${entityType}/${entityId}/${entityBundle}`),
      dialogType: 'dialog',
      dialogRenderer: 'sidebar',
    };
    Drupal.ajax(ajaxSettings).execute();
  }

  function RenderedEntity({id, viewMode = 'default'}) {
    const renderedEntityRef = useCallback(async (node) => {
      if (node !== null) {
        node.id = `content_block-${id}-${viewMode}`;
        const element_settings = {
          url: Drupal.url(`editor/content_block/render/${id}/${viewMode}`),
          progress: false,
        };
        Drupal.ajax(element_settings).execute();
      }
    }, [id, viewMode]);

    return (
      <div ref={renderedEntityRef}><Spinner /></div>
    );
  }

  function ContentBlock({ type, contentBlockId, name, viewMode: viewModeOriginal, onViewModeChange }) {
    const settingsFormRef = useCallback((node) => {
      if (node !== null) {
        openBlockSettings(type, contentBlockId);
      }
    }, [type, contentBlockId]);

    const [viewMode, setViewMode] = useState(viewModeOriginal);
    const [viewModeOptions, setViewModeOptions] = useState([]);

    useEffect(() => {
      if (onViewModeChange)
        onViewModeChange(viewMode);
    }, [viewMode, onViewModeChange]);

    useEffect(() => {
      const fetchViewModes = async () => {
        const response = await fetch(Drupal.url(`editor/entity/view_modes/block_content/${type}`));
        const result = await response.json();
        setViewModeOptions(result.view_modes);
      };
      fetchViewModes();
    }, [type]);

    return (
      <div>
        <InspectorControls key="content-block-settings">
        <Card>
            <CardBody>
              <SelectControl
                  label={ __('View mode') }
                  value={ viewMode }
                  options={ Object.entries(viewModeOptions).map(([k,v]) => ({label: v, value: k})) }
                  onChange={ ( newValue ) => setViewMode( newValue ) }
                  __nextHasNoMarginBottom
              />
            </CardBody>
          </Card>

          <Card>
            <CardBody>
              <div ref={settingsFormRef} id="gutenberg-sidebar-dialog">
                <Spinner />
              </div>
            </CardBody>
          </Card>
        </InspectorControls>
        {!contentBlockId && (
          <Placeholder icon="media-document" label={name}>
            <div className="content-blocks__placeholder">
              <div className="content-blocks__placeholder__description">
                <p>{Drupal.t('This content block is not configured.')}</p>
                <p>
                  {Drupal.t('Fill the form at the sidebar to configure it.')}
                </p>
              </div>
            </div>
          </Placeholder>
        )}
        {contentBlockId && (
          <RenderedEntity id={contentBlockId} viewMode={viewMode} />
        )}
      </div>
    );
  }

  window.DrupalGutenberg = window.DrupalGutenberg || {};
  window.DrupalGutenberg.Components = window.DrupalGutenberg.Components || {};
  window.DrupalGutenberg.Components.ContentBlock = ContentBlock; // createClass;
})(Drupal, wp, drupalSettings);
