# AI setup and skill catalog

Updated 2026-10-23. This setup provides shared repository instructions, reusable workflow skills and native editor entry files. It is project-local; it does not install an AI client, change global profiles, bypass workspace trust or guarantee every AI loads local files.

## Start and choose a workflow

Read [AGENTS](../AGENTS.md), [project context](project-context.md), and the project's current progress/decisions. Then load only the matching skill:

| Request | Canonical skill |
|---|---|
| Plan, implement, debug or review a project workflow | [Project work](../.agents/skills/groceryone-project-work/SKILL.md) |
| Update plans, progress and continuation notes | [Documentation and progress](../.agents/skills/groceryone-docs-progress/SKILL.md) |
| Prepare thesis claims, citations, methods or evidence | [Thesis evidence](../.agents/skills/groceryone-thesis-evidence/SKILL.md) |
| Configure or diagnose AI entry files, skills and MCP | [AI tooling](../.agents/skills/groceryone-ai-tooling/SKILL.md) |

Skills are Markdown instructions with name/description frontmatter. They do not automatically run code, grant permissions or add business features. Their workflow bodies have one canonical location. Claude/Cursor bridge skills route here when native shared-path discovery is unavailable.

## Editor entry points

| Client | Repository entry | Skills / configuration |
|---|---|---|
| Codex | AGENTS.md | `.agents/skills/`; `.codex/config.toml` for trusted project MCP |
| Claude Code | CLAUDE.md imports AGENTS.md | `.claude/skills/groceryone-workflow-bridge/`; `.mcp.json` |
| Cursor | `.cursor/rules/project-guide.mdc` | `.cursor/skills/groceryone-workflow-bridge/`; `.cursor/mcp.json` |
| VS Code / Copilot | `.github/copilot-instructions.md` | `.agents/skills/` and native bridge discovery; `.vscode/mcp.json` |
| Gemini CLI | GEMINI.md | `.agents/skills/` alias; `.gemini/settings.json` |
| OpenCode | AGENTS.md and `opencode.json` | Native `.agents/skills/` discovery and root documentation MCP settings |
| Other local AI / plain chat | Explicitly supply AGENTS.md, project-context and the needed SKILL.md | No automatic loading claimed; attach files or give their readable paths |

Native loading depends on the installed client/version and workspace settings. In a plain web chat, local paths alone are not file access. For a nested workspace that does not discover parent skills, follow the explicit catalog links. No forced model, auto-approval, autonomous subagent, startup hook or background service is configured.

## Example requests

- "Read AGENTS.md and use groceryone-docs-progress to summarize current status and the next authorized step."
- "Use groceryone-project-work to trace this issue; preserve the project stack and run only relevant safe checks."
- "Use groceryone-thesis-evidence to map this claim to actual sources and mark missing evidence."
- "Use groceryone-ai-tooling to verify which instructions and MCP tools this session actually loaded."

The request controls the action. For HROMS, the documentation-only application phase continues until the user asks to start implementation.

## MCP and verification

See [MCP setup](mcp/README.md) for configured servers, optional local tools and client checks. See [verification record](verification.md) for what was actually checked. A config on disk is not proof a client loaded it; a successful tools/list is not an application test.

## Maintenance

OpenCode setup follows its [configuration](https://opencode.ai/docs/config/), [skills](https://opencode.ai/docs/skills/) and [MCP](https://opencode.ai/docs/mcp-servers/) documentation. Its native startup was not exercised.

Change the canonical skill or AGENTS first; keep editor files as short routers. Preserve existing editor settings and skill packs. Update the progress owner in project-context after meaningful work. Never copy credentials or environment values into shared configs. Existing developer skills do not prove runtime AI features are enabled.

## Official references

Checked 2026-10-23: [Codex skill locations](https://learn.chatgpt.com/docs/build-skills), [Codex MCP](https://learn.chatgpt.com/docs/extend/mcp?surface=cli), [Claude instructions](https://code.claude.com/docs/en/memory), [Claude skills](https://code.claude.com/docs/en/skills), [Cursor rules](https://cursor.com/docs/rules), [Cursor skills](https://cursor.com/docs/skills), [VS Code skills](https://code.visualstudio.com/docs/agent-customization/agent-skills), [Gemini context](https://geminicli.com/docs/cli/gemini-md/), [Gemini skills](https://geminicli.com/docs/cli/skills/). Recheck official behavior when upgrading clients.
