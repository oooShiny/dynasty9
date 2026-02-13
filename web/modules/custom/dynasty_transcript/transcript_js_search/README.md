# Solr Search API Proxy

A lightweight Express.js proxy that sits between your frontend and Apache Solr, providing a clean JSON API for transcript search with caching, rate limiting, and Solr highlighting.

## Architecture

```
Browser (search-widget.html)
    ↓ fetch JSON
Express API (server.js)
    ↓ HTTP query
Apache Solr (existing instance)
```

Drupal continues to manage content and index it into Solr via Search API as usual. The search UI bypasses Drupal's rendering pipeline entirely.

## Quick Start

### 1. Install and run the API

```bash
cd solr-search-api
npm install
```

Copy `.env.example` to `.env` and update the values:

```bash
cp .env .env
```

Edit `.env` with your Solr details:
- `SOLR_URL` — Base URL of your Solr instance (e.g., `http://localhost:8983/solr`)
- `SOLR_CORE` — The core/collection name used by Drupal's Search API
- `ALLOWED_ORIGINS` — Your site's domain(s)

Start the server:

```bash
npm start

# Or with auto-reload during development:
npm run dev
```

### 2. Update Solr field mappings

The most important step is mapping the field names in `server.js` to match your actual Solr schema. Open `server.js` and update the `CONFIG.solr` section.

To find your field names, you can:

- Check Solr Admin UI → Your Core → Schema
- Query Solr directly: `curl "http://localhost:8983/solr/YOUR_CORE/select?q=*:*&rows=1&wt=json"` and inspect the field names in the response
- Check Drupal's Search API index configuration at `/admin/config/search/search-api`

Drupal's Search API Solr module uses prefixed field names:
| Prefix | Type |
|--------|------|
| `tm_` | Text, multivalued |
| `ts_` | Text, single |
| `ss_` | String, single |
| `sm_` | String, multivalued |
| `its_` | Integer, single |
| `ds_` | Date, single |
| `bs_` | Boolean, single |

### 3. Test the API

```bash
# Health check
curl http://localhost:3001/api/health

# Search
curl "http://localhost:3001/api/search?q=brady&rows=5"
```

Verify you're getting results back with highlights. If you get a 502, check that the Solr URL and core name are correct.

### 4. Add the search widget to your site

Open `search-widget.html` and update `SEARCH_API_URL` to point to your running API (e.g., `https://patsdynasty.com/api/search` if you reverse proxy it, or the direct URL).

Then embed it in your Drupal site by either:
- Adding it as a custom block (paste the HTML into a Full HTML text format block)
- Including it as a Twig template
- Adding it to a custom module/theme template

## Configuration

### API Settings (server.js)

| Setting | Default | Description |
|---------|---------|-------------|
| `PORT` | 3001 | API port |
| `SOLR_URL` | localhost:8983/solr | Solr base URL |
| `SOLR_CORE` | your_core_name | Solr core name |
| Cache TTL | 60s | How long to cache search results |
| Rate limit | 60 req/min | Per-IP request limit |

### Widget Settings (search-widget.html)

| Setting | Default | Description |
|---------|---------|-------------|
| `SEARCH_API_URL` | localhost:3001 | API endpoint URL |
| `RESULTS_PER_PAGE` | 10 | Results per page |
| `DEBOUNCE_MS` | 300 | Delay before auto-search fires |

## Production Considerations

- **Reverse proxy**: In production, you'll likely want to proxy the API through Nginx/Apache so it's accessible at something like `https://patsdynasty.com/api/search` rather than exposing port 3001 directly.
- **Process manager**: Use PM2 or systemd to keep the Node process running: `pm2 start server.js --name solr-search`
- **HTTPS**: The API should be served over HTTPS in production (handled by your reverse proxy).
- **Logging**: Consider adding structured logging (e.g., with pino) for production monitoring.
