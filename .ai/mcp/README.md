# Project MCP connections and verification

Updated 2026-10-23. Configured connections are public documentation services. No credentials or global settings were added.

| Server ID | Endpoint | Purpose | Checked in this task |
|---|---|---|---|
| openaiDeveloperDocs | `https://developers.openai.com/mcp` | OpenAI/Codex product and API documentation | initialize HTTP 200; tools/list returned 5 tools |
| context7 | `https://mcp.context7.com/mcp` | Library/framework documentation | initialize HTTP 200; tools/list returned resolve-library-id and query-docs |

Only protocol metadata was sent for these checks; no project code, secrets, business records or documentation queries were uploaded. A working handshake does not guarantee future availability or unrestricted access. Context7 currently accepted these discovery requests without a key; authenticated queries, quotas or future policies may differ. Supply any required credential through the client's private credential facility, never a tracked repository file. See [Context7's official setup](https://github.com/upstash/context7) and [OpenAI Docs MCP](https://developers.openai.com/learn/docs-mcp).

## Native configuration files

- Codex: `.codex/config.toml`, `[mcp_servers.<name>]` with `url`; project trust is required. No model, sandbox or approval settings changed. [Reference](https://learn.chatgpt.com/docs/extend/mcp?surface=cli).
- Claude Code: `.mcp.json`, `mcpServers` with HTTP type/url. Review/load the project connection in Claude; use its MCP status interface. [Reference](https://code.claude.com/docs/en/mcp).
- Cursor: `.cursor/mcp.json`, `mcpServers` with URL. Check Settings/Tools/MCP for the loaded server and tool list. [Reference](https://cursor.com/docs/mcp).
- VS Code/Copilot: `.vscode/mcp.json`, `servers` with HTTP type/url. Use MCP: List Servers and inspect status/tool availability. [Reference](https://code.visualstudio.com/docs/copilot/customization/mcp-servers).
- Gemini CLI: `.gemini/settings.json`, `mcpServers` with `httpUrl` for Streamable HTTP. Inspect `/mcp`; `url` has different transport semantics. [Reference](https://geminicli.com/docs/tools/mcp-server/).

Open the intended workspace root before checking. For AcademiSync, equivalent docs-only configs exist at the parent root and Laravel subfolder so either opening is supported. If both scopes load, same-named entries should be reviewed in the client; do not add a second duplicate server manually.

## Optional project tools

| Tool | When useful | Prerequisite / boundary |
|---|---|---|
| Laravel Boost | Laravel-specific docs, routes/schema and application context | AcademiSync already configures it in OpenCode. HROMS has no Laravel install yet. GroceryOne is not Laravel. Check installed version, effective DB and exposed tools before enabling elsewhere. |
| Playwright MCP | Browser checks of the local app | A running isolated local app, reviewed/pinned MCP package and browser availability. Do not run production transactions or reuse a real signed-in browser profile. Existing AcademiSync config is preserved. |
| Serena | Symbol navigation for larger changes | Existing AcademiSync setup may be used after verifying its project path/runtime. No new installation or full-tree indexing was run. |
| Database MCP | Only a concrete authorized query need | Not configured by this setup. Establish disposable target, credentials/grants and allowed tool actions first; application defaults are not proof of a safe target. |
| GitHub or other external apps | Explicit repository/account work | Separate client/account connection and task authorization; not installed merely because MCP exists. |

Do not use floating package installation commands as a background startup hook. A tool described as useful is not a verified installation. Inspect actual tools: instructions restricting use are not a technical read-only guarantee.

## Troubleshooting and fallback

OpenCode uses the root `opencode.json`: instructions point to AGENTS/catalog and `mcp` contains the two remote docs servers. Its native skill discovery supports `.agents/skills/`. No OpenCode process was launched. See [official MCP settings](https://opencode.ai/docs/mcp-servers/).

1. Confirm the client opened the intended folder and read AGENTS/catalog. Skills may require a reload/new session. If absent from the selector, explicitly ask the assistant to read the linked SKILL.md.
2. Validate the client's native schema and inspect server status without printing secrets. A configuration parser success does not establish workspace trust or an active process.
3. For HTTP failure, distinguish network/proxy/TLS, authentication and quota errors. Do not disable TLS validation or copy credentials into docs as a workaround.
4. For local tools, check command path, working directory, package version and prerequisites. Never boot an app with an unverified database target just to remove a red status icon.
5. Use direct official documentation when a docs server is unavailable. Record the limited capability and continue independent work.

## Portability boundary

These are developer-assistant tools, not features inside the hotel, LMS or grocery system. No single MCP file is loaded universally. File-based setup does not configure hosted chats that cannot see this filesystem. Native editor activation and account permissions remain client-specific.

## Codex loading observation (2026-10-23)

`codex mcp list --json` was run from all four configured roots. Both docs servers were listed from the AcademiSync parent workspace. HCI P1, GroceryOne and the separate Laravel root returned no servers; selective local-config inspection found no exact trust entry for those roots. The parent AcademiSync root already had a trusted entry. No global trust or profile setting was changed. Open/trust the intended project through Codex's normal flow and rerun the listing; until then, those configurations are present but not confirmed loaded. Other editors may require their normal workspace/server acceptance and reload.
