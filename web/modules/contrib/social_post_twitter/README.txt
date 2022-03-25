CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Configuration
 * Example Usage (API)
 * Maintainers


INTRODUCTION
------------

 * Social Post Twitter allows you to configure your site to automatically tweet
   to a users' accounts without human intervention. It is based on Social Post
   and Social API projects.

 * For a full description of the module, visit the project page:
   https://www.drupal.org/project/social_post_twitter

 * To submit bug reports and feature suggestions, or to track changes:
   https://www.drupal.org/project/issues/social_post_twitter


REQUIREMENTS
------------

This module requires the following modules:

 * Social API (https://www.drupal.org/project/social_api)
 * Social Post (https://www.drupal.org/project/social_post)


INSTALLATION
------------

 * Install as you would normally install a contributed Drupal module. See:
   https://www.drupal.org/node/1897420 for further information.


CONFIGURATION
-------------

 * Users need the "Perform Twitter autoposting" permission to link a Twitter
   account and post from it. Navigate to People > Permissions
   (/admin/people/permissions) as a Drupal admin to add this permission to the
   desired roles.

 * Users may link multiple Twitter accounts from the user edit page for their
   account. To do so, log in to the Drupal site, navigate to the user edit page
   and click "Add account" in the "Social Post Twitter" section.

Example use cases:

 * Update status when a new content has been added.
 * Share a video link (stored in a field) after flagging some content.
 * Let user's announce on their Twitter profile they have just bought a product
  (and link to it).
 * Welcome new Drupal users on your site's Twitter account.


EXAMPLE USAGE (API)
-------------------

<?php

// Create and post manager instances.
/** @var Abraham\TwitterOAuth\TwitterOAuth $client */
$client = \Drupal::service('plugin.network.manager')->createInstance('social_post_twitter')->getSdk();
/** @var Drupal\social_post_twitter\TwitterPostManager $post_manager */
$post_manager = \Drupal::service('twitter_post.manager');

// Get accounts for the current user.
/* @var Drupal\social_post\Entity\SocialPost[] $accounts */
$accounts = \Drupal::service('social_post.user_manager')->getAccounts('social_post_twitter');

foreach ($accounts as $account) {
  // Set OAuth token for the account.
  $token = json_decode($account->getToken());
  $client->setOauthToken($token->oauth_token, $token->oauth_token_secret);

  // Make a post to Twitter with the account!
  $post_manager->setClient($client)->doPost('New post via Social Post Twitter!');
}


MAINTAINERS
-----------

Current maintainers:

 * wells - https://www.drupal.org/u/wells
