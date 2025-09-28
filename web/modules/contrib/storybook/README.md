✨ Seamless integration between Drupal and Storybook ✨

The _Storybook Drupal_ module enhances the Twig templating language
by introducing two new Twig tags: `stories` and `story`, so you can write
Storybook stories in Twig. With this module, you can easily create and manage
Storybook stories directly in your Twig templates, making it a powerful tool for
**documenting and showcasing your frontend templates**.

## Installation

You can install Twig Storybook via Composer:

```bash
composer require drupal/storybook
```

### Creating Stories

Once the Twig Storybook extension is registered, you can start creating
stories within your Twig templates. We recommend writing the stories in a file
with name `<file-name>.stories.twig`.

- Use the `{% stories %}` tag to define a group of stories.
- Use the `{% story %}` tag to define an individual story.

Here's an example:

```twig
{# some/path/in/your/code/base/my-card.stories.twig #}
{% stories my_card with { title: 'Components/Examples/Card' } %}

  {% story default with {
    name: '1. Default',
    args: { header: 'I am a header!', text: 'Learn more', iconType: 'power' }
  } %}
    {# Write any Twig for the "default" story. The `args` above will be made #}
    {# available as variables for the template 👇 #}
    {% embed '@examples/my-card' with { header } %}
      {% block card_body %}
        <p>I am the <em>card</em> contents.</p>
        {% include '@examples/my-button' with { text, iconType } %}
      {% endblock %}
    {% endembed %}
  {% endstory %}

{% endstories %}
```

This will render as:

![Storybook Screenshot](./docs/sb-screenshot.png)

### Drupal setup
In `development.services.yml` you want to add some configuration for Twig, so you don't need to clear caches so often. This is not needed for the Storybook integration, but it will make things easier when you need to move components to your Drupal templates.

You also need to enable CORS, so the Storybook application can talk to your Drupal site. You want this CORS configuration to be in `development.services.yml` so it does not get changed in your production environment. If you mean to use _CL Server_ in production, make sure to restrict CORS as much as possible. Remember _CL Server_ development mode **SHOULD** be disabled in production.

The configuration you want looks like this:

```yaml
parameters:
  # ...
  # Remember to disable development mode in production!
  storybook.development: true
  cors.config:
    enabled: true
    allowedHeaders: ['*']
    allowedMethods: ['*']
    allowedOrigins: ['*']
    exposedHeaders: false
    maxAge: false
    supportsCredentials: true
services:
  # ...
```

Disable render cache and twig cache:

<code>
drush state:set twig_debug 1
drush state:set twig_cache_disable 1
drush state:set disable_rendered_output_cache_bins 1
</code>

⚠ Make sure to **grant permission** to _Render Storybook stories_ for anonymous users. Keep this permission disabled in production.

#### Prepare ddev for running the Storybook application
If you are using ddev for you local environment you will need to expose some ports to connect to Storybook. You can do so by adapting the following snippet in your `.ddev/config.yaml`:

<details><summary><strong>See ddev configuration</strong></summary>

```yaml
###############################################################################
# Customizations
###############################################################################
nodejs_version: "18"
webimage_extra_packages:
  - pkg-config
  - libpixman-1-dev
  - libcairo2-dev
  - libpango1.0-dev
  - make
web_extra_exposed_ports:
  - name: storybook
    container_port: 6006
    http_port: 6007
    https_port: 6006
web_extra_daemons:
  - name: node.js
    command: "tail -F package.json > /dev/null"
    directory: /var/www/html
hooks:
  post-start:
    - exec: echo '================================================================================='
    - exec: echo '                                  NOTICE'
    - exec: echo '================================================================================='
    - exec: echo 'The node.js container is ready. You can start storybook by typing:'
    - exec: echo 'ddev yarn storybook'
    - exec: echo
    - exec: echo 'By default it will be available at https://change-me.ddev.site:6006'
    - exec: echo "Use ddev describe to confirm if this doesn't work."
    - exec: echo 'Check the status of startup by running "ddev logs --follow --time"'
    - exec: echo '================================================================================='

###############################################################################
# End of customizations
###############################################################################
```

</details>

<details><summary><strong>Manually support missing assets (fonts, etc)</strong></summary>

Some users have reported that even with CORS enabled on Drupal, font assets (i.e. `woff/woff2` fonts) won't be served due to CORS.

As a workaround, you can take control of the `nginx-site.conf` file and tweak it. Just do the following:

1. Remove the `#ddev-generated` line (usually, the third line) on `.ddev/nginx_full/nginx-site.conf`. This will allow you to override DDEV defaults, see more info [here](https://ddev.readthedocs.io/en/latest/users/extend/customization-extendibility/#custom-nginx-configuration).
2. Locate this line and manually add the CORS header:
```yml
  # Media: images, icons, video, audio, HTC
  location ~* \.(png|jpg|jpeg|gif|ico|svg|woff|woff2)$ { # <--- Add the missing extensions
    add_header Access-Control-Allow-Origin *; # <--- Add the CORS header
    try_files $uri @rewrite;
    expires max;
    log_not_found off;
  }
```
3. Run `ddev restart`

</details>

### Storybook setup

Install Storybook as usual:

```bash
# Make use of modern versions of yarn.
yarn set version berry
# Avoid pnp.
echo 'nodeLinker: node-modules' >> .yarnrc.yml
# Install and configure stock Storybook.
yarn dlx sb init --builder webpack5 --type server
```

Then update `.storybook/main.js` to scan for stories where your application stores them.

### Compiling Twig stories into JSON

The Storybook application will does not understand stories in Twig format. It will fail to render them. You need to
compile them into a `*.stories.json`. To do so you can run:

```bash
drush storybook:generate-all-stories
```

If you want to monitor story changes to compile Twig stories into JSON, execute it with `watch`. Like so:

```bash
watch --color drush storybook:generate-all-stories
```

#### Setting the server url for Stories
In order for Storybook to fetch the rendered story from Drupal, it must know the url for the Storybook route. By default this url is added as a [story parameter](https://storybook.js.org/docs/writing-stories/parameters) during the compilation process and will be set based on the URI configured for drush.

To override the domain, use Drush's `--uri` option.

```bash
drush storybook:generate-all-stories --uri=https://my-site.com
```

If you'd prefer to set the server URL in Storybook configuration, you can omit the server url parameter from the compiled stories.json files with the `--omit-server-url` option. This is useful when deploying a static version of your Storybook application to different environments.

```bash
drush storybook:generate-all-stories --omit-server-url
```

You will then need to set the server url option in Storybook's `.storybook/preview.[ts|js]` file. *NOTE: You must include the full path to Drupal's Storybook route when setting this configuration via Storybook configuration. Setting only the domain will not work*

```js
const preview = {
  server: {
    url: process.env.STORYBOOK_SERVER_URL || 'http://my-site.com/storybook/stories/render',
  },
  parameters: {
    ...
  },
};

export default preview;

```

In this example, we are setting the url to the `$STORYBOOK_SERVER_URL` environment variable if it's available, otherwise falling back to `http://my-site.com/storybook/stories/render`.

### Tugboat setup and configuration
[Tugboat](https://www.tugboatqa.com/) is a service that builds a complete, working website, for every pull request. You can also preview your Storybook application within Tugboat with a few additional configurations.

Note: You will need a Tugboat account configured for your repository to preview your application.

Update your `.tugboat/config.yml` file with the following service.

```yaml
storybook:
    image: tugboatqa/node:20
    checkout: true
    expose: 6006
    commands:
      init:
        - corepack enable
        - corepack install
        - yarn
        - mkdir -p /etc/service/node
        - echo "#!/bin/sh" > /etc/service/node/run
        - echo "yarn --cwd ${TUGBOAT_ROOT} storybook" >> /etc/service/node/run
        - chmod +x /etc/service/node/run
      build:
        - perl -pe "s/my-domain.com/$TUGBOAT_DEFAULT_SERVICE_URL_HOST/g" -i web/**/**/*json
        - echo "STORYBOOK_DRUPAL_PREVIEW_URL=${TUGBOAT_SERVICE_URL}" >> ${TUGBOAT_ROOT}/.env
        - yarn > /dev/null
```

You will also need to update the `init` command used for your PHP service to allow for a custom nginx configuration in order to add Cross Origing Resource Sharing (CORS) headers so that the Tugboat application can access your site's static assets such as CSS/JS, Webfonts, and icon SVGs.

In `.tugboat/config.yml` add the following to your `init` command for your PHP service.

```yaml
php:
    ...
    commands:
      init:
        - ...
        - apt-get install gettext
        - envsubst '$TUGBOAT_SERVICE_URL_HOST $DOCROOT' < "${TUGBOAT_ROOT}/.tugboat/default.nginx.conf.template" > /etc/nginx/sites-enabled/default.nginx.conf
```

And create the following file `.tugboat/default.nginx.conf.template` with the following content:

```nginx
server {
    listen 80;
    server_name ${TUGBOAT_SERVICE_URL_HOST};
    root ${DOCROOT};

    index index.php index.htm index.html;

    # Disable sendfile as per https://docs.vagrantup.com/v2/synced-folders/virtualbox.html
    sendfile off;
    error_log /dev/stdout info;
    access_log /var/log/nginx/access.log;

    location / {
        absolute_redirect off;
        try_files $uri $uri/ /index.php?$query_string; # For Drupal >= 7
    }

    location @rewrite {
        # For D7 and above:
        # Clean URLs are handled in drupal_environment_initialize().
        rewrite ^ /index.php;
    }

    # Handle image styles for Drupal 7+
    location ~ ^/sites/.*/files/styles/ {
        try_files $uri @rewrite;
    }

    # pass the PHP scripts to FastCGI server listening on socket
    location ~ '\.php$|^/update.php' {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;

        # Normally we'd use a unix socket here, but the base image is already
        # configured to listen on a TCP port. Since it's local anyways, we don't
        # expect any real performance impact.
        fastcgi_pass localhost:9000;

        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME $fastcgi_script_name;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_intercept_errors off;
        # fastcgi_read_timeout should match max_execution_time in php.ini
        fastcgi_read_timeout 10m;
        fastcgi_param SERVER_NAME $host;
        fastcgi_param HTTPS $fcgi_https;
    }

    # Expire rules for static content

    # Prevent clients from accessing hidden files (starting with a dot)
    # This is particularly important if you store .htpasswd files in the site hierarchy
    # Access to `/.well-known/` is allowed.
    # https://www.mnot.net/blog/2010/04/07/well-known
    # https://tools.ietf.org/html/rfc5785
    location ~* /\.(?!well-known\/) {
        deny all;
    }

    # Prevent clients from accessing to backup/config/source files
    location ~* (?:\.(?:bak|conf|dist|fla|in[ci]|log|psd|sh|sql|sw[op])|~)$ {
        deny all;
    }

    ## Regular private file serving (i.e. handled by Drupal).
    location ^~ /system/files/ {
        ## For not signaling a 404 in the error log whenever the
        ## system/files directory is accessed add the line below.
        ## Note that the 404 is the intended behavior.
        log_not_found off;
        access_log off;
        expires 30d;
        try_files $uri @rewrite;
    }

    # Media: images, icons, video, audio, HTC
    location ~* \.(jpg|jpeg|gif|png|ico|cur|gz|mp4|ogg|ogv|webm|webp|htc)$ {
        try_files $uri @rewrite;
        expires max;
        log_not_found off;
    }

    # Media: SVG icons with CORS headers
    location ~* \.(svg)$ {
        # Allow storybook to access SVGs.
        add_header Access-Control-Allow-Origin '*';
        add_header X-Content-Type-Options nosniff;
        try_files $uri @rewrite;
        expires max;
        log_not_found off;
    }

    # Assets: js, css and webfonts with CORS headers
    location ~* \.(js|css|woff|woff2|ttf)$ {
        # Allow storybook to access JS, CSS and webfonts.
        add_header Access-Control-Allow-Origin '*';
        add_header X-Content-Type-Options nosniff;
        try_files $uri @rewrite;
        expires -1;
        log_not_found off;
    }
}

```

This nginx configuration will be copied over to your Tugboat `sites-enabled` directory and loaded with every nginx reload. The configuration takes precedent over Tugboat's default nginx configuration for each `TUGBOAT_SERVICE_URL_HOST` which corresponds to the URL created by Tugboat for your pull requests.

Note: You may need to add, remove, or update location directives depending on your site's particular use cases.

## Troubleshooting

### Case 1: Error rendering component with Storybook

If the component doesn't render in Storybook and inspecting network request showing a request with an error response:

```
http://[DRUPAL-SITE]/storybook/stories/render/{hash}?...
```

This is because server url in JSON stories generated via `drush storybook:generate-all-stories` are `http`.
Then when Storybook request to Drupal via `http`, it eventually get rejected.
This is caused by either *ddev* certificate config issue, or by custom drush alias enforcing http.

Solution:
- Consider fixing ddev config as shown in https://stackoverflow.com/questions/65111024/ddev-project-starts-up-site...
- Or, create a Drush site alias with uri using https
- Run `ddev drush st | grep "Site URI"` to verify the current protocol is https

### Case 2: Issue when migrating from CL Server into Storybook

If all components don't render in Storybook and inspecting network requests in Storybook showing request to

```
http://[DRUPAL-SITE]/storybook/stories/render/_cl_server?_storyFileName=.%2Fdocroot%2Fmodules%2Fcustom%2[MY-MODULE]%2Fcomponents%2Fbutton%2Fbutton.stories.json&_drupalTheme=testTheme
```

Unlike with CL Server, the new Storybook module no longer requires `@lullabot/storybook-drupal-addon`. This add-on should be removed from the `.storybook/main.js`.
