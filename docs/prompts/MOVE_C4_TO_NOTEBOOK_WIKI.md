You are working in /mnt/cache/DevelopmentProjects/unraid-extensions/unraid-plg-aicliagents (branch master). Write in ASD-STE100 simplified English. Read the repo CLAUDE.md files first. Read docs/specs/ARTEFACTS.md, docs/specs/ARTEFACTS_CONTRACT.md (section 10 is the wiki layer) and docs/specs/NOTEBOOK_TESTING.md before you start. Spec-first is mandatory: write docs/specs/C4_IN_NOTEBOOK_WIKI.md (use the spec template in the workspace CLAUDE.md) and show it to John before you move any file.

GOAL
Move the C4 architecture documentation from C4-Documentation/ into this repo's Notebook wiki, so that people and agents read it in the Notebook drawer (Wiki view) and agents update it through the Notebook tools. The wiki stays inside this repo (in the notebook/ folder at the repo root), structured the way the plugin expects.

FIRST: PROVE THE WIKI WORKS
John asked that the wiki feature is tested before the move. Do this first and report the result in plain words.
1. Run the Notebook PHPUnit and Vitest tests that cover the wiki (tests/php/NotebookServiceExtrasTest.php wiki tests, ui-build/src/components/notebook/__tests__/NotebookWiki.test.tsx). They use a temporary folder and never touch real notes.
2. Prove the wiki in a real browser against a SCRATCH notebook, never the repo's own notebook/ folder and never the flash global notebook. Those two hold canary notes on purpose (tests/lib/notebook-canary.py checks them). Use the Playwright route described in the workspace CLAUDE.md, with the request guard. If the live Playwright specs in ui-build/tests/e2e/notebook/ still fail on the known blocker (the page falls back to the operator's workspace about 12 s after a note is created while the drawer is open; see docs/specs/NOTEBOOK_TESTING.md R7), fix that blocker or work around it in the harness, and say which.
3. Check these wiki behaviours: home page, sections from a `section` field and from a `section:<name>` tag, [[Title]] and [[a_ID]] links, backlinks, the orphan list, the graph, the "Things you should know" page, a locked page (agents may only comment), page size limit, and a Mermaid diagram (.mmd and a fenced block in .md) drawn in the wiki.
If any of this fails, stop and report. Do not start the move on a broken wiki.

FACTS YOU MUST DESIGN AROUND
- A notebook file may be at most 262144 bytes (NotebookService::MAX_FILE_BYTES). A notebook holds at most 2000 artefacts. Two C4 documents are over the limit: c4-code-services.md (about 390 KB) and c4-code-ui-build.md (about 284 KB). Several others are over 100 KB. Total is about 2.85 MB in 29 files. Split large documents by their existing headings into linked pages, one page per cluster or component, each under about 120 KB. Keep a short index page per original document that links to its parts with [[Title]] links.
- Every C4 document carries a provenance stamp (the commit it reflects) and C4-Documentation/c4-manifest.json maps each document to the source paths it describes. The drift check (python3 C4-Documentation/tools/c4-drift.py, run by ../ci/run docs, and the rule "commit a refreshed doc in the SAME commit as the code") must keep working after the move. Read C4-Documentation/MAINTENANCE.md and the tool first. Decide, and write in the spec: store the stamp in each page's front matter, store the manifest as a notebook page or keep it as a file next to the wiki, and change c4-drift.py to read the pages. The check must still go red when a page is stale, and `sync` must still work.
- Diagrams in the C4 documents are Mermaid blocks. Keep them as Mermaid (a fenced block in the page, or a .mmd page). Check that each one draws.
- The Notebook folder rules: the folder is notebook/ at the workspace root, everything in it is committed, git is the only history, areas are inbox, working, notes, archive. Wiki pages live in notes/. The storefront mirror excludes notebook/ (Forgejo #426); keep it excluded. The public C4 documents must not leak: check what ci/scripts/storefront does with C4-Documentation/ today and keep the same result.
- The notebook secret guard refuses a page that looks like it holds a secret. C4 text may trigger it (example tokens, key names). Do not turn the guard off. Rewrite the text or use the guard's documented confirm route, and list each case in the spec.
- Never write to a notebook through shell redirects. Use the plugin's own path: NotebookService through the aicli_* Notebook tools or the CLI verbs, so ids, front matter, locks, events and git commits are right. Write a one-off importer script under tools/ that calls NotebookService (with the same seams the tests use) for the bulk move. Run it first against a scratch notebook and compare, then against notebook/.
- Do not delete C4-Documentation/. Archive it: move it to C4-Documentation-archive/ in the same commit only after the new wiki, the drift check and ../ci/run docs all pass. Check every reference first (grep CLAUDE.md files, .claude/ docs, ci/, tests/, tools/, C4-Documentation/MAINTENANCE.md, the c4-manifest.json users) and update each one.

STRUCTURE OF THE WIKI
- One wiki home page (`kind: home`) with the C4 levels as sections: `Context`, `Containers`, `Components`, `Code`. Use the `section` field, not only tags.
- One page per C4 document, or per part of a split document. Title = the document's own title. Tags: `c4`, the level, the component name.
- Link the pages with [[Title]] links the way the C4 documents already link to each other (their relative .md links become wiki links). No orphans: aicli_wiki_index must list no orphan among the C4 pages.
- A page that explains how to maintain the wiki (replaces C4-Documentation/MAINTENANCE.md), and a `Things you should know` note about the drift rule.
- Lock the generated index pages? No. Agents must be able to update the C4 pages in the same commit as the code. Do not lock them.

ALSO UPDATE
- The workspace and plugin CLAUDE.md text about C4 (paths, the drift command, "commit a refreshed doc in the SAME commit as the code").
- The aicli-artefacts skill text and the injected guidance, only if they need a line about the C4 wiki. Keep them short.
- C4 docs about the Notebook itself, so they stay true.
- Add tests: the importer (page sizes under the limit, no orphans, every original heading present, every link resolves), the drift tool reading pages (stale page goes red, sync turns it green), and a guard that the storefront mirror still excludes both notebook/ and the wiki.

VERIFY BEFORE YOU CLAIM DONE
- ../ci/run docs lint unit all pass. Run ../ci/run guards after each edit under src/.
- Open the wiki in the real Notebook drawer (Playwright, with a screenshot) and show John the home page, one split page and one diagram.
- Show the byte size of the biggest page and the page count.
- Do the work in small commits (commit, integrate, push after each verified step). Deploy with ../ci/run deploy if you change src/ or ui-build/, then speak a plain-English summary (no numbers, no file names) with the plugin's speak tool.
- Create or update Forgejo issues in unraid/unraid-plg-aicliagents as you go (type/feature, status labels). Close children before the epic.
- End your final message with the link to the spec or issue, or with the decision you need from John.

STOP AND ASK JOHN (use AskUserQuestion, recommendation first) if: the wiki test fails; the drift tool cannot keep a faithful stale check on pages; any C4 content would reach the public storefront; or the split of a large document loses information.
