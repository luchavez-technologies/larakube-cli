## Running PHP/Composer

All PHP and Composer commands in this directory MUST go through the local wrapper scripts, not the host `php`/`composer` binaries — they run inside a persistent Docker daemon that has the right PHP version and extensions.

- `./php vendor/bin/pest` (tests), `./php vendor/bin/pint` (formatting, per repo-wide hard rule), `./php vendor/bin/phpstan` (static analysis)
- `./composer <args>` for dependency management
- Never run `./build` yourself — tell the user to run it and wait.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
