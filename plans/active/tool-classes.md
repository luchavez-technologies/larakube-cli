# Plan: one class per tool, in `app/Tools`

**Status:** Flow done (`app/Tools/N8n.php`, `app/Tools/Windmill.php`). The
other categories not started.

## Why
The multi-product categories are enums (`DataTool`, `ChatTool`,
`GitForgeTool`, `DesignTool`, `DeskTool`, `TaskTool`) where every method is a
`match ($this)`: each new product edits every method, each new capability
edits every product. Container versions live as literal `image:` lines across
the Blade templates, so stale pins go unnoticed.

## Shape
- **One class per product** in `app/Tools`, implementing only the capability
  contracts it supports (`HasSmtpWiring`, `HasCommonsDatabases`, …) plus
  `HasImages`, which declares every pinned image (`images()`, `image($key)`).
  Tool-specific knowledge belongs on the class too.
- **Templates read images from the class** (`$tool->image('n8n')`); a test
  fails on any literal `image:` tag in a migrated template, and on any image
  without an explicit version.
- **The category enum keeps only the engine keys** (what `--engine=` and the
  tool registry store) and a `tool()` method returning the class.
- **Names come from `ToolInstance`**, never from the class.

## Order
One category per commit: Data, Chat, GitForge, Design, Desk, Task. Then the
22 single-product classes in `app/Vendors` move to `app/Tools` and gain
`HasImages` as each is next touched. Before pinning any version, check the
upstream release (CLAUDE.md).

## Open
- Windmill: pinned at 1.770.0 while upstream is at 1.815.0, and upstream
  replaced `windmill-lsp` with `windmill-extra`. Bump and verify live as its
  own change.
