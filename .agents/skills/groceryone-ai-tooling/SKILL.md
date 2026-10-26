---
name: groceryone-ai-tooling
description: "Configure or diagnose groceryone project instructions, skills, editor bridges and MCP connections while preserving existing tooling."
---

# groceryone-ai-tooling

Read [AI catalog](../../../.ai/README.md), [MCP guide](../../../.ai/mcp/README.md) and [project context](../../../.ai/project-context.md).

1. Identify the opened workspace, target client/version and specific missing capability. Inspect existing project configuration without printing environment secrets or tokens; preserve unrelated settings and installed skill packs.
2. Keep one canonical workflow body in `.agents/skills`. Native editor files and bridge skills point to it. Honor the current application phase; tooling changes do not approve product features or database access.
3. Verify the current official client format before changes. Merge configuration by server/key; never overwrite unrelated entries or auto-enable an old broad-access server to satisfy a status label.
4. For a new connection, distinguish syntax validation, discovery/initialize and actual task calls. Limit public docs requests to generic technical queries. Never send source, secrets or guest/student/employee records as a documentation query.
5. Report configured versus active state separately. Missing credentials, unavailable clients and workspace trust are explicit limits; do not bypass them. Prefer direct official documentation as fallback if a docs MCP is unavailable.
6. Update the setup guide/progress with actual checks. For new skills validate frontmatter, names and referenced paths, then review realistic task routing and scope preservation.

No model override, approval bypass, startup hook, automatic deployment or universal database connection is part of this setup. The two public documentation endpoints have no business-system permissions. Installed third-party MCP tools can still expose powerful actions; inspect their actual tool list.
