-- SUMMARY --

The Patreon Extras module pulls data from the Patreon API and provides it in
different formats for use on a Drupal site.

-- REQUIREMENTS --

The module requires that the Patreon User module is enabled and configured.

If the tokens module is enabled, some API data will be available as global
tokens. This relies on users being registered on your site and connected to
patreon by the patreon_user module: this is due to AOI changes that mean
the API no longer returns pledge data for a campaign. Pledges must now be
calculated from the individual user's Patreon accounts.


-- INSTALLATION --

The module can be installed as usual; see https://drupal.org/node/895232 for
further information. It is a sub-module of the Patreon module.

-- CONFIGURATION --

There is no configuration for this module.

-- CUSTOMIZATION --

This module does not currently offer opportunity to customise.

-- TROUBLESHOOTING --

The data is collected from the API on a daily cron run. If it appears out of
date, you will need to trigger cron.

-- CONTACT --

Current maintainer:

* Dale Smith (MrDaleSmith) - https://drupal.org/user/2612656
