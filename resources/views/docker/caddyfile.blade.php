{{-- Serves the SPA. Plain HTTP on 8080: Traefik terminates TLS at the cluster
     edge, exactly as it does for every other LaraKube workload. --}}
:8080 {
	root * /srv
	encode zstd gzip

	{{-- Vite names build output `index-D5pO33-4.js`: a DASH before a base64url
	     hash, not a dot before a hex one. A hex pattern matches none of it and
	     the header silently never applies — confirmed against a real build. --}}
	@@hashed path_regexp hashed [-.][A-Za-z0-9_-]{8,}\.(js|mjs|css|woff2?|png|jpe?g|svg|webp|avif|gif|ico)$

	{{-- Content-addressed: changing the file changes its name, so a year is
	     safe. This is what Netlify and Vercel apply to build output. --}}
	header @@hashed Cache-Control "public, max-age=31536000, immutable"

	{{-- Everything else is the app shell — `/`, and every client-side route,
	     which try_files rewrites to index.html. Matching the literal path
	     `/index.html` would catch none of them, because header matchers see the
	     ORIGINAL request path, before the rewrite. --}}
	@@shell not path_regexp hashed [-.][A-Za-z0-9_-]{8,}\.(js|mjs|css|woff2?|png|jpe?g|svg|webp|avif|gif|ico)$
	header @@shell Cache-Control "public, max-age=0, must-revalidate"

	header {
		X-Content-Type-Options nosniff
		Referrer-Policy strict-origin-when-cross-origin
		-Server
	}

	{{-- Client-side routing: /some/route has no file on disk and must serve the
	     app shell at 200, not 404. This is the failure a dev server cannot
	     show, because it resolves deep links itself. --}}
	try_files {path} /index.html
	file_server
}
