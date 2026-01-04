/**
 * Cloudflare Worker: Cache Warmer
 *
 * Deploy this to Cloudflare Workers to warm cache programmatically
 * Trigger via: https://your-worker.workers.dev/warm
 */

const URLS_TO_WARM = [
  '/search/games',
  '/search/plays',
  '/podcast',
  // Popular seasons
  '/search/games?season%5B2001%5D=2001',
  '/search/games?season%5B2003%5D=2003',
  '/search/games?season%5B2004%5D=2004',
  '/search/games?season%5B2014%5D=2014',
  '/search/games?season%5B2016%5D=2016',
  '/search/games?season%5B2018%5D=2018',
  // Popular opponents
  '/search/games?opponent%5BNew+York+Jets%5D=New+York+Jets',
  '/search/games?opponent%5BMiami+Dolphins%5D=Miami+Dolphins',
  '/search/games?opponent%5BBuffalo+Bills%5D=Buffalo+Bills',
  // Results
  '/search/games?result%5BWin%5D=Win',
  '/search/games?result%5BLoss%5D=Loss',
];

addEventListener('fetch', event => {
  event.respondWith(handleRequest(event.request))
})

async function handleRequest(request) {
  const url = new URL(request.url);

  // Security: require secret key
  const secretKey = url.searchParams.get('key');
  if (secretKey !== 'YOUR_SECRET_KEY_HERE') {
    return new Response('Unauthorized', { status: 401 });
  }

  if (url.pathname === '/warm') {
    return warmCache();
  }

  return new Response('Cache Warmer Worker. Use /warm?key=SECRET to warm cache.');
}

async function warmCache() {
  const results = [];
  const origin = 'https://patsdynasty.com'; // Your site URL

  for (const path of URLS_TO_WARM) {
    const targetUrl = origin + path;
    try {
      const response = await fetch(targetUrl, {
        cf: {
          cacheTtl: 3600,
          cacheEverything: true
        }
      });

      results.push({
        url: targetUrl,
        status: response.status,
        cached: response.headers.get('cf-cache-status')
      });
    } catch (error) {
      results.push({
        url: targetUrl,
        error: error.message
      });
    }

    // Rate limit: small delay between requests
    await new Promise(resolve => setTimeout(resolve, 100));
  }

  return new Response(JSON.stringify({
    warmed: results.length,
    timestamp: new Date().toISOString(),
    results
  }, null, 2), {
    headers: { 'content-type': 'application/json' }
  });
}
