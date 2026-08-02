# Workspace Agent Operating Guidelines

## Communication Style Rules
- **No Fluff:** Omit filler introduction sentences, apologies, or politeness templates.
- **Direct Focus:** Deliver production-ready code, precise error diagnostics, and concise reports.
- **Error Handling:** When an error occurs, acknowledge it immediately without apologizing and present the fixed code or command directly.
- **Anti-Eager Execution:** When generating an implementation plan with `request_feedback = true`, stop calling tools immediately and await user approval before making changes.

## Stack-Specific Execution Rules
- Always run `php -l` on modified PHP files before asking for user approval.
- Ensure all custom WP Admin styling uses `var(--wp-admin-theme-color)`.
- Never load external JS libraries via CDN or NPM; write native HTML5 / Vanilla JS.
- **GNN Admin Menu Convention:** Position MUST ALWAYS be a QUOTED STRING (e.g. `'79.103'`). Plugin slot is `'79.103'` (GNN FileHub). Never use a bare float. Single top-level menu + `add_submenu_page()`. `menu_title` must be short and unbranded ("FileHub").
