const express = require("express");
const cors = require("cors");

const app = express();

// --- Configuration ---
const CONFIG = {
  port: process.env.PORT || 3001,
  solrBaseUrl: process.env.SOLR_URL || "http://161.35.2.35:8983/solr",
  solrCore: process.env.SOLR_CORE || "dynasty-core", // Update to your Solr core
  allowedOrigins: process.env.ALLOWED_ORIGINS
    ? process.env.ALLOWED_ORIGINS.split(",")
    : ["https://patsdynasty.com", "http://localhost"],

  // Solr field mapping — update these to match your actual Solr schema
  solr: {
    searchField: "tm_body", // The text field to search against
    highlightField: "tm_body", // The field to generate highlights from
    returnFields: [
      "ss_title", // Segment/node title
      "ss_episode_title", // Episode title
      "ss_url", // URL/path to the content
      "ds_created", // Created date
      "its_field_timestamp", // Timestamp within episode (if applicable)
    ].join(","),
    // Adjust these field names based on your Drupal Search API -> Solr mapping
    // Common prefixes in Drupal's Search API Solr:
    //   tm_ = text multivalued, ss_ = string single, its_ = integer timestamp single
    //   ds_ = date single, bs_ = boolean single
  },
};

// --- Middleware ---
app.use(
  cors({
    origin: (origin, callback) => {
      // Allow requests with no origin (like curl or server-to-server)
      if (!origin) return callback(null, true);
      if (CONFIG.allowedOrigins.includes(origin)) {
        return callback(null, true);
      }
      return callback(new Error("Not allowed by CORS"));
    },
  })
);

// Simple in-memory cache
const cache = new Map();
const CACHE_TTL = 60 * 1000; // 60 seconds

function getCached(key) {
  const entry = cache.get(key);
  if (!entry) return null;
  if (Date.now() - entry.timestamp > CACHE_TTL) {
    cache.delete(key);
    return null;
  }
  return entry.data;
}

function setCache(key, data) {
  // Cap cache size to prevent memory issues
  if (cache.size > 1000) {
    const oldest = cache.keys().next().value;
    cache.delete(oldest);
  }
  cache.set(key, { data, timestamp: Date.now() });
}

// Simple rate limiting by IP
const rateLimits = new Map();
const RATE_LIMIT_WINDOW = 60 * 1000; // 1 minute
const RATE_LIMIT_MAX = 60; // requests per window

function checkRateLimit(ip) {
  const now = Date.now();
  const entry = rateLimits.get(ip);

  if (!entry || now - entry.windowStart > RATE_LIMIT_WINDOW) {
    rateLimits.set(ip, { windowStart: now, count: 1 });
    return true;
  }

  if (entry.count >= RATE_LIMIT_MAX) {
    return false;
  }

  entry.count++;
  return true;
}

// --- Search endpoint ---
app.get("/solr/dynasty-core/select", async (req, res) => {
  const clientIp = req.ip || req.connection.remoteAddress;

  if (!checkRateLimit(clientIp)) {
    return res.status(429).json({ error: "Too many requests. Try again shortly." });
  }

  const query = (req.query.q || "").trim();
  if (!query) {
    return res.status(400).json({ error: "Missing search query parameter 'q'" });
  }

  // Sanitize: strip Solr special characters to prevent injection
  const sanitized = query.replace(/[+\-&|!(){}[\]^"~*?:\\/]/g, " ").trim();
  if (!sanitized) {
    return res.status(400).json({ error: "Invalid search query" });
  }

  const page = Math.max(0, parseInt(req.query.page) || 0);
  const rows = Math.min(50, Math.max(1, parseInt(req.query.rows) || 10));
  const start = page * rows;

  // Check cache
  const cacheKey = `${sanitized}:${page}:${rows}`;
  const cached = getCached(cacheKey);
  if (cached) {
    return res.json(cached);
  }

  // Build Solr query
  const solrParams = new URLSearchParams({
    q: "index_id:podcast_transcript_index&" + sanitized,
    defType: "edismax",
    qf: CONFIG.solr.searchField, // Fields to search against (can be weighted, e.g. "tm_body^1 ss_title^2")
    fl: `id,score,${CONFIG.solr.returnFields}`, // Fields to return
    start: start.toString(),
    rows: rows.toString(),
    wt: "json",

    // Highlighting
    hl: "true",
    "hl.fl": CONFIG.solr.highlightField,
    "hl.snippets": "2",
    "hl.fragsize": "200",
    "hl.simple.pre": "<mark>",
    "hl.simple.post": "</mark>",
  });

  const solrUrl = `${CONFIG.solrBaseUrl}/${CONFIG.solrCore}/select?${solrParams}`;

  try {
    const solrResponse = await fetch(solrUrl);

    if (!solrResponse.ok) {
      console.error(`Solr error: ${solrResponse.status} ${solrResponse.statusText}`);
      return res.status(502).json({ error: "Search service unavailable" });
    }

    const solrData = await solrResponse.json();
    const highlighting = solrData.highlighting || {};

    // Shape the response
    const results = (solrData.response.docs || []).map((doc) => {
      const highlights = highlighting[doc.id] || {};
      const snippets = highlights[CONFIG.solr.highlightField] || [];

      return {
        id: doc.id,
        title: doc.ss_title || "",
        episodeTitle: doc.ss_episode_title || "",
        url: doc.ss_url || "",
        created: doc.ds_created || "",
        timestamp: doc.its_field_timestamp || null,
        score: doc.score,
        snippets: snippets, // HTML with <mark> tags
      };
    });

    const response = {
      query: sanitized,
      total: solrData.response.numFound,
      page,
      rows,
      totalPages: Math.ceil(solrData.response.numFound / rows),
      results,
    };

    setCache(cacheKey, response);
    res.json(response);
  } catch (err) {
    console.error("Error querying Solr:", err.message);
    res.status(500).json({ error: "Internal search error" });
  }
});

// Health check
app.get("/api/health", (req, res) => {
  res.json({ status: "ok", timestamp: new Date().toISOString() });
});

app.listen(CONFIG.port, () => {
  console.log(`Search API running on port ${CONFIG.port}`);
  console.log(`Solr target: ${CONFIG.solrBaseUrl}/${CONFIG.solrCore}`);
});
