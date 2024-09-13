((wp, drupalSettings) => {
  const userId = drupalSettings.user ? drupalSettings.user.uid || 1 : 1;
  const storageKey = `WP_PREFERENCES_USER_${userId}`;
  const { preferences, preferencesPersistence } = wp;

  const getUserPreferences = () => {
    const persisted = localStorage.getItem(storageKey);
    if (persisted !== null) {
      try {
        return JSON.parse( persisted );
      } catch ( error ) {}
    }
    return {};
  }

  const serverData = getUserPreferences();
  const persistenceLayer = preferencesPersistence.__unstableCreatePersistenceLayer( serverData, userId );
  const preferencesStore = preferences.store;
  wp.data.dispatch( preferencesStore ).setPersistenceLayer( persistenceLayer );
})(wp, drupalSettings);
