# Markdownify

Markdownify is a Drupal module that provides a seamless solution for generating
Markdown versions of your site's content. Via any of the six supported request
patterns, this module enables bots, AI agents, and developers to access a
lightweight, Markdown-based representation of your site’s content for easier
parsing and consumption.

For a full description of the module, visit
the [project page](https://www.drupal.org/project/markdownify).

Submit bug reports and feature suggestions, or track changes in
the [issue queue](https://www.drupal.org/project/issues/markdownify).

## Table of Contents

- [Why Markdown?](#why-markdown)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [How to Access Markdown Versions of Content](#how-to-access-markdown-versions-of-content)
    - [Six Ways to Access Markdown Content](#six-ways-to-access-markdown-content)
    - [Supported Routes](#supported-routes)
    - [Examples](#examples)
- [Optional: Enable the Markdownify Path Submodule](#optional-enable-the-markdownify-path-submodule)
- [Optional: Enable the Markdownify Views Pages Submodule](#optional-enable-the-markdownify-views-pages-submodule)
- [Contributing](#contributing)
- [License](#license)
- [Maintainers](#maintainers)

## Why Markdown?

While modern LLMs can ingest and parse raw HTML, Markdown offers significant
advantages:

- **Cost Efficiency**: AI services charge based on token usage, typically in
  increments of one million. In our testing, Markdown reduces token count by a *
  *10:1 ratio** compared to HTML, leading to lower costs.
- **Faster Processing**: The reduction in token count results in **quicker
  processing** of your content in Markdown format.
- **Zero Distractions**: The Markdown output omits headers, footers, ads, and
  other irrelevant elements, providing a **clean and concise context** for AI
  models.
- **Universal Format**: Markdown is widely supported and easily understood by AI
  models, making it the **lingua franca** for structured text.

## Requirements

This module requires:

- **Drupal Version**: 9.x or higher
- **PHP Version**: 8.0 or higher

## Installation

Install as you would normally install a contributed Drupal module. For further
information,
see [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

Using Composer:

```sh
composer require drupal/markdownify
```

Then enable the module using Drush:

```sh
drush en markdownify -y
```

### Optional: Enable the **Markdownify Path** submodule

The **Markdownify Path** submodule allows users to access Markdown-formatted
content using human-readable path aliases. Enable it with Drush:

```sh
drush en markdownify_path -y
```

Alternatively, enable both the main module and submodule via the Drupal admin UI
by navigating to **Extend** and selecting "Markdownify" and "Markdownify Path".

### Optional: Enable the **Markdownify Views Pages** submodule

The **Markdownify Views Pages** submodule (`markdownify_views`) extends
Markdownify support to Drupal Views pages. It enables Markdown-formatted
versions of views, making it easier to consume structured content in Markdown
format.

Enable it with Drush:

```sh
drush en markdownify_views -y
```

Or enable it via the Drupal admin UI by navigating to **Extend** and selecting "
Markdownify Views Pages".

Once enabled, this submodule allows users to retrieve Markdown versions of Views
pages using the same access methods as regular entities, such as appending `.md`
to URLs or using query parameters.

## Configuration

No additional configuration is required. The module automatically integrates
with all entities that define `entity.view` routes, such as nodes and taxonomy
terms.

## How It Works

The **Markdownify** module leverages the power of
the [League HTML-to-Markdown Library](https://github.com/thephpleague/html-to-markdown)
to convert HTML-rendered content into clean Markdown format.

When a request for a `.md` version of an entity is received, the module:

1. **Renders the Entity**: The module uses the standard Drupal render pipeline
   to generate the HTML representation of the requested entity.
2. **Converts HTML to Markdown**: Using the
   `League\HTMLToMarkdown\HtmlConverter`, the module processes the HTML output
   and transforms it into Markdown format.
3. **Delivers Markdown Output**: The Markdown version of the content is served
   to the client, omitting unnecessary elements like headers, footers, or
   advertisements.

By removing unnecessary elements, the output remains concise and AI-friendly.

## How to Access Markdown Versions of Content

Once enabled, Markdownify provides six ways to access Markdown-formatted
content. With the **Markdownify Path** submodule enabled, you can also access
Markdown content via human-readable path aliases.

### Six Ways to Access Markdown Content

1. **Appending `.md` to the URL**
   Simply append `.md` to the entity URL:
   ```sh
   curl -I https://yourwebsite.com/node/1.md
   ```

2. **Appending `.md` to Path Aliases** (via `Markdownify Path` Submodule)
   Access Markdown versions of content using the path alias followed by `.md`:
   ```sh
   curl -I https://yourwebsite.com/en/articles/sample-article.md
   ```

3. **Using the `/markdownify` Path Prefix**
   Prepend `/markdownify` to the URL:
   ```sh
   curl -I https://yourwebsite.com/markdownify/node/1
   ```

4. **Using the `_format` Query Parameter**
   Add `_format=markdown` to the URL:
   ```sh
   curl -I "https://yourwebsite.com/node/1?_format=markdown"
   ```

   See https://www.drupal.org/node/2501221 for more information about the
   `_format` query parameter.

5. **Using the `Accept` Header**
   Specify `Accept: text/markdown` in your request headers:
   ```sh
   curl -I https://yourwebsite.com/node/1 -H "Accept: text/markdown"
   ```

6. **Using the `Content-Type` Header**
   Set `Content-Type: text/markdown` in the request headers:
   ```sh
   curl -I https://yourwebsite.com/node/1 -H "Content-Type: text/markdown"
   ```

### Supported Routes

| Access Method                    | Example Usage                       |
|----------------------------------|-------------------------------------|
| `.md` extension (Canonical Path) | `/node/1.md`                        |
| `.md` extension (Path Alias)     | `/en/articles/sample-article.md`    |
| `/markdownify` path prefix       | `/markdownify/node/1`               |
| `_format` query parameter        | `/node/1?_format=markdown`          |
| `Accept` header                  | `/node/1` (with `Accept` set)       |
| `Content-Type` header            | `/node/1` (with `Content-Type` set) |

### Examples

#### Standard HTML Page

```
https://example.com/node/1
```

#### Markdown Versions

- **Using `.md` extension**:
  ```
  https://yourwebsite.com/node/1.md
  ```
  Using Path Alias:
  ```
  https://yourwebsite.com/en/articles/sample-article.md
  ```
- **Using `/markdownify` path prefix**:
  ```
  https://yourwebsite.com/markdownify/node/1
  ```
- **Using `_format` parameter**:
  ```
  https://yourwebsite.com/node/1?_format=markdown
  ```
- **Using HTTP headers**:
  Add either `Accept: text/markdown` or `Content-Type: text/markdown` to your
  requests.

This applies to nodes, taxonomy terms, and other entities that support route
handling in Drupal.

## Contributing

Contributions are welcome! Feel free to submit issues and pull requests on
the [Markdownify project page](https://www.drupal.org/project/markdownify).

## License

Markdownify is open-source and distributed under the **GPL-2.0-or-later**
license.

## Maintainers

This module is maintained by:

- [Adrian Morelos (i'mbatman)](https://www.drupal.org/u/imbatman)
- [Christoph Weber (christophweber)](https://www.drupal.org/u/christophweber)
- [Artem Dmitriiev (a.dmitriiev)](https://www.drupal.org/u/admitriiev)
- [Christoph Breidert (breidert)](https://www.drupal.org/u/breidert)
