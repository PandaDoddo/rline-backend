<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>R-Line API</title>
    <script>
        (() => {
            const originalFetch = window.fetch.bind(window);
            const ready = originalFetch('/sanctum/csrf-cookie', { credentials: 'include', headers: { Accept: 'application/json' } });
            window.fetch = async (input, options) => {
                const request = new Request(input, options);
                const url = new URL(request.url);
                if (url.origin !== location.origin || !/^\/(api|auth)(\/|$)/.test(url.pathname)) return originalFetch(input, options);
                if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method)) {
                    await ready;
                    const token = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice(11);
                    const headers = new Headers(request.headers);
                    if (token) headers.set('X-XSRF-TOKEN', decodeURIComponent(token));
                    return originalFetch(new Request(request, { headers, credentials: 'include' }));
                }
                return originalFetch(new Request(request, { credentials: 'include' }));
            };
        })();
    </script>
    <script src="/vendor/stoplight-elements/8.4.2/web-components.min.js"></script>
    <link rel="stylesheet" href="/vendor/stoplight-elements/8.4.2/styles.min.css">
    <style>html, body { margin: 0; height: 100%; } elements-api { display: block; height: 100vh; }</style>
</head>
<body>
    <elements-api id="api-docs" layout="responsive" router="hash" tryItCredentialsPolicy="include"></elements-api>
    <script>document.getElementById('api-docs').apiDescriptionDocument = @json($spec);</script>
</body>
</html>
