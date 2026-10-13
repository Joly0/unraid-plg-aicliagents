<?php
/**
 * <module_context>
 *     <name>NotebookTemplates</name>
 *     <description>The built-in starting points for a new artefact (docs/specs/ARTEFACTS_CONTRACT.md
 *     section 5, notebook_templates). Each template is a real, usable document of its type:
 *     manual test plan, release checklist, UAT script, deployment runbook, incident report,
 *     design review, decision record, status report, handover note, bug report, meeting notes,
 *     onboarding guide, API reference. A checklist template uses the checklist grammar, so a
 *     person or an agent can set the result of each step.</description>
 *     <dependencies>none</dependencies>
 *     <constraints>Pure data. No file or network access. The token {{date}} in a body is replaced
 *     with today's date when a template is used; no other substitution happens.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class NotebookTemplates {

    /** @return array<int,array{key:string,title:string,type:string,description:string}> */
    public static function listAll(): array {
        $out = [];
        foreach (self::all() as $key => $t) {
            $out[] = ['key' => $key, 'title' => $t['title'], 'type' => $t['type'], 'description' => $t['description']];
        }
        return $out;
    }

    /** @return array{key:string,title:string,type:string,description:string,body:string,tags:array,meta?:array}|null */
    public static function get(string $key): ?array {
        $all = self::all();
        if (!isset($all[$key])) return null;
        $t = $all[$key];
        $t['key'] = $key;
        $t['body'] = str_replace('{{date}}', gmdate('Y-m-d'), $t['body']);
        return $t;
    }

    /**
     * Build a checklist body. $groups: group title => list of [step text, do, expect].
     * Ids are numbered s1, s2, ... in order.
     */
    private static function checklist(string $title, string $intro, array $groups): string {
        $out = '# ' . $title . "\n\n" . trim($intro) . "\n";
        $n = 0;
        foreach ($groups as $group => $steps) {
            $out .= "\n## " . $group . "\n";
            foreach ($steps as $s) {
                $n++;
                $out .= '- [ ] ' . $s[0] . ' {#s' . $n . "}\n";
                if (($s[1] ?? '') !== '') $out .= '  - Do: ' . $s[1] . "\n";
                if (($s[2] ?? '') !== '') $out .= '  - Expect: ' . $s[2] . "\n";
                $out .= "  - Result: pending\n  - Note:\n";
            }
        }
        return $out;
    }

    /** @return array<string,array{title:string,type:string,description:string,body:string,tags:array}> */
    private static function all(): array {
        return [
            'manual-test-plan' => [
                'title' => 'Manual test plan', 'type' => 'checklist', 'tags' => ['test'],
                'description' => 'Steps a person follows to check a change by hand, with the expected result for each step.',
                'body' => self::checklist('Manual test plan', "Version or change under test: \nEnvironment (URL, build, account): \nTester: \nDate: {{date}}\n\nSet each step to pass, fail, blocked or skipped. Write what you saw in the note when a step fails.", [
                    'Set up' => [
                        ['Open the application', 'Open the address of the test environment in a fresh browser window.', 'The first page loads with no error banner.'],
                        ['Sign in with the test account', 'Use the account named in the connection card.', 'You reach the home page and your name shows in the header.'],
                    ],
                    'Main flow' => [
                        ['Do the main task of the change', 'Follow the change description from start to end.', 'Each screen shows the new behaviour and no console error appears.'],
                        ['Reload the page in the middle of the task', 'Press reload when half of the task is done.', 'Nothing is lost, or the page says clearly what was lost.'],
                        ['Repeat the task with wrong input', 'Leave a required field empty, then enter a value that is too long.', 'The page shows a clear message next to the field and does not save.'],
                    ],
                    'Around the change' => [
                        ['Check the neighbouring feature', 'Open the screen next to the changed one and use it once.', 'It works the same as before the change.'],
                        ['Check a phone-sized window', 'Make the browser window 390 px wide.', 'The layout fits the width and every button can be pressed.'],
                    ],
                    'Wrap up' => [
                        ['Sign out', 'Use the sign-out control.', 'You return to the sign-in page.'],
                        ['Write the verdict', 'Add a summary note to the last step: ship, ship with known issues, or do not ship.', 'The verdict is clear to someone who did not test.'],
                    ],
                ]),
            ],
            'release-checklist' => [
                'title' => 'Release checklist', 'type' => 'checklist', 'tags' => ['release'],
                'description' => 'What to confirm before, during and after a release goes out.',
                'body' => self::checklist('Release checklist', "Release: \nPlanned date: {{date}}\nOwner: \n\nWork through the groups in order. A failed or blocked step stops the release until it is resolved.", [
                    'Before the release' => [
                        ['All planned changes are merged', 'List the merged changes in the notes.', 'Nothing planned is missing and nothing unplanned is included.'],
                        ['The automatic tests pass on the release commit', 'Open the latest test run for the release commit.', 'Every stage is green.'],
                        ['The change log reads well to a user', 'Read the change log entry as if you had not built the change.', 'Each line says what the user will notice.'],
                        ['The version number is correct', 'Compare the version in the files with the plan.', 'They match.'],
                        ['A way back is ready', 'Name the previous version and how to install it again.', 'The rollback steps are written down.'],
                    ],
                    'The release' => [
                        ['Publish the release', 'Run the publish step.', 'The publish step ends with no error.'],
                        ['Install the release on the test server', 'Install the published version.', 'The test server reports the new version.'],
                    ],
                    'After the release' => [
                        ['Smoke test the main screen', 'Open the main screen and use the main feature once.', 'It works with no error.'],
                        ['Watch the logs for ten minutes', 'Read the log of the running service.', 'No new error appears.'],
                        ['Tell the people who need to know', 'Post the release note where the team reads it.', 'The note is posted.'],
                    ],
                ]),
            ],
            'uat-script' => [
                'title' => 'User acceptance test script', 'type' => 'checklist', 'tags' => ['test', 'uat'],
                'description' => 'Scenarios a business user runs to accept a change, written in plain words.',
                'body' => self::checklist('User acceptance test script', "Feature: \nAccepted by: \nDate: {{date}}\n\nRun each scenario as a real user would. Mark it pass only if the result is what you need for your work, not only what the screen says.", [
                    'Scenario 1: the usual case' => [
                        ['Start from the home screen', 'Open the application and go to the start of the task.', 'You can find the start without help.'],
                        ['Complete the task with typical data', 'Use data from a real recent case.', 'The task finishes and the result matches what you would produce by hand.'],
                    ],
                    'Scenario 2: the awkward case' => [
                        ['Complete the task with unusual data', 'Use a case that has an exception, a long name or a missing detail.', 'The application handles it, or explains what is needed.'],
                        ['Undo or correct a mistake', 'Make a deliberate mistake, then correct it.', 'You can fix it without asking a technician.'],
                    ],
                    'Sign-off' => [
                        ['Is the change ready for daily use?', 'Think about a normal working day with this change.', 'You would use it tomorrow. Write any condition in the note.'],
                    ],
                ]),
            ],
            'deployment-runbook' => [
                'title' => 'Deployment runbook', 'type' => 'checklist', 'tags' => ['ops', 'deploy'],
                'description' => 'Exact steps to deploy a service, with a check after each step and the way back.',
                'body' => self::checklist('Deployment runbook', "Service: \nTarget environment: \nOperator: \nStarted: {{date}}\n\nDo the steps in order. Do not skip a check. If a check fails, stop and use the rollback group.", [
                    'Before you start' => [
                        ['Confirm the target environment', 'Read the host name and address you are about to change.', 'It is the environment named at the top of this runbook.'],
                        ['Confirm the current version', 'Read the version that runs now.', 'It matches the version you expect to replace.'],
                        ['Take a backup of the data', 'Run the backup command for the service.', 'A backup file exists and its size is not zero.'],
                    ],
                    'Deploy' => [
                        ['Pull or copy the new build', 'Fetch the build named in the release.', 'The checksum matches the release note.'],
                        ['Stop the service or drain it', 'Use the normal stop command.', 'The service reports that it has stopped.'],
                        ['Install the new build', 'Run the install step.', 'The install ends with no error.'],
                        ['Start the service', 'Use the normal start command.', 'The service reports that it runs the new version.'],
                    ],
                    'Verify' => [
                        ['Health check', 'Call the health address of the service.', 'It answers with success.'],
                        ['Use the main feature once', 'Do one real action.', 'It works.'],
                        ['Read the log', 'Read the last 50 log lines.', 'There is no error or repeated warning.'],
                    ],
                    'Rollback (only if a check failed)' => [
                        ['Stop the service', 'Use the normal stop command.', 'The service has stopped.'],
                        ['Restore the previous build and data', 'Install the previous version and restore the backup.', 'The previous version runs again.'],
                        ['Report', 'Write what failed in the note of the failed step.', 'The next person can see the cause.'],
                    ],
                ]),
            ],
            'incident-report' => [
                'title' => 'Incident report', 'type' => 'markdown', 'tags' => ['incident'],
                'description' => 'What happened, who was affected, why, and what changes so it does not happen again.',
                'body' => "# Incident report\n\n> [!NOTE]\n> Write for a reader who was not there. Use plain words. Give times with a time zone.\n\n## Summary\nOne paragraph: what broke, for whom, for how long.\n\n## Impact\n- Who was affected:\n- What they could not do:\n- Start time:\n- End time:\n- Data lost or changed (yes or no, and what):\n\n## Timeline\n| Time | What happened |\n|---|---|\n| {{date}} 00:00 | First sign of the problem |\n|  | Problem noticed |\n|  | Cause found |\n|  | Fix applied |\n|  | Service back to normal |\n\n## Cause\nThe chain of events that led to the failure. Name the root cause, not only the trigger.\n\n## What went well\n- \n\n## What went badly\n- \n\n## Actions\n| Action | Owner | Due | Done |\n|---|---|---|---|\n|  |  |  |  |\n\n## Lessons\nWhat do we know now that we did not know before?\n",
            ],
            'design-review' => [
                'title' => 'Design review', 'type' => 'markdown', 'tags' => ['design'],
                'description' => 'A design proposal with the problem, the options, the risks and the questions for the reviewers.',
                'body' => "# Design review\n\n## Problem\nWhat problem does this design solve? Why now? Who has the problem?\n\n## Goals and non-goals\n- Goal:\n- Goal:\n- Not a goal:\n\n## Proposed design\nDescribe the design in a few paragraphs. Add a diagram as a separate artefact and link it, for example [[Architecture diagram]].\n\n## Options considered\n| Option | Good | Bad |\n|---|---|---|\n| A (proposed) |  |  |\n| B |  |  |\n| Do nothing |  |  |\n\n## Risks\n> [!WARNING]\n> List what can go wrong, how likely it is, and how you would notice.\n\n- \n\n## Test plan\nHow will we know it works? Link the test plan.\n\n## Open questions\n> [!QUESTION]\n> What must the reviewers decide?\n\n- \n\n## Review notes\n| Reviewer | Date | Verdict | Comment |\n|---|---|---|---|\n|  | {{date}} |  |  |\n",
            ],
            'decision-record' => [
                'title' => 'Decision record', 'type' => 'markdown', 'tags' => ['decision'],
                'description' => 'One decision: the context, the options, the choice, and what follows from it.',
                'body' => "# Decision record\n\n- Status: proposed\n- Date: {{date}}\n- Decided by:\n\n## Context\nWhat forces the decision? Facts only.\n\n## Options\n1. **Option one.** What it is. Its cost. Its risk.\n2. **Option two.** What it is. Its cost. Its risk.\n3. **Do nothing.** What happens if we wait.\n\n> [!DECISION]\n> The choice, in one sentence. Say which option and why it wins.\n\n## Consequences\n- What gets easier:\n- What gets harder:\n- What we must do next:\n\n## Revisit\nWhen, or after what event, do we look at this decision again?\n",
            ],
            'status-report' => [
                'title' => 'Status report', 'type' => 'markdown', 'tags' => ['status'],
                'description' => 'A short report for a period: what is done, what is next, what blocks, and the overall state.',
                'body' => "# Status report\n\nPeriod: {{date}}\nOverall: green, amber or red (pick one and say why in one line)\n\n## Done since the last report\n- \n\n## In progress\n| Item | Owner | State | Expected |\n|---|---|---|---|\n|  |  |  |  |\n\n## Next\n- \n\n## Blocked or at risk\n> [!WARNING]\n> What blocks the work, who can unblock it, and by when it must be unblocked.\n\n- \n\n## Decisions needed\n> [!QUESTION]\n> What do you need from the reader?\n\n- \n",
            ],
            'handover-note' => [
                'title' => 'Handover note', 'type' => 'markdown', 'tags' => ['handover'],
                'description' => 'Everything the next person needs to carry on the work without asking.',
                'body' => "# Handover note\n\nFrom: \nTo: \nDate: {{date}}\n\n## Where things stand\nOne paragraph: what is finished, what is half done, what has not started.\n\n## Where everything is\n- Code or folder:\n- Running service or test address:\n- Accounts (name the connection card, never write a password here):\n- Documents:\n\n## In flight\n| Item | State | Next step |\n|---|---|---|\n|  |  |  |\n\n## Things that will surprise you\n> [!TIP]\n> Traps, odd settings, steps that look wrong but are right.\n\n- \n\n## First thing to do\nThe single next action.\n\n## Who to ask\n- \n",
            ],
            'bug-report' => [
                'title' => 'Bug report', 'type' => 'markdown', 'tags' => ['bug'],
                'description' => 'A fault that someone else can reproduce: steps, expected and actual result, and evidence.',
                'body' => "# Bug report\n\n- Found on: {{date}}\n- Version or build:\n- Environment:\n- How bad: blocks work, wrong result, cosmetic\n\n## What happens\nOne or two sentences. State what you saw.\n\n## What should happen\nOne or two sentences.\n\n## Steps to reproduce\n1. \n2. \n3. \n\nHappens every time? yes or no. If no, how often?\n\n## Evidence\n- Screenshot or file: add it to the notebook assets and link it\n- Log lines (remove any secret first):\n\n```text\npaste the few lines that matter\n```\n\n## What you already tried\n- \n\n## Suspected cause\nOnly if you have one. Say how sure you are.\n",
            ],
            'meeting-notes' => [
                'title' => 'Meeting notes', 'type' => 'markdown', 'tags' => ['meeting'],
                'description' => 'Who came, what was decided, and who does what by when.',
                'body' => "# Meeting notes\n\nDate: {{date}}\nPresent: \nApologies: \n\n## Agenda\n1. \n2. \n3. \n\n## Discussion\nKeep it short. Write what matters, not who said what.\n\n## Decisions\n> [!DECISION]\n> Write each decision as a full sentence.\n\n- \n\n## Actions\n| Action | Owner | Due |\n|---|---|---|\n|  |  |  |\n\n## Next meeting\nDate and time:\n",
            ],
            'onboarding-guide' => [
                'title' => 'Onboarding guide', 'type' => 'checklist', 'tags' => ['onboarding'],
                'description' => 'A checklist for a new person: access, tools, first tasks and people to meet.',
                'body' => self::checklist('Onboarding guide', "New person: \nBuddy: \nStart date: {{date}}\n\nTick each step as you go. Add a note when something was missing or unclear, so the next person has it easier.", [
                    'Access' => [
                        ['Get an account for the code host', 'Ask your buddy for an invitation.', 'You can open the main project.'],
                        ['Get access to the chat and the meeting calendar', 'Ask your buddy.', 'You can read and post in the team channel.'],
                        ['Open the connection cards in this notebook', 'Find the connection cards for the test systems.', 'You can open each test system. The passwords stay hidden until you reveal one.'],
                    ],
                    'Tools' => [
                        ['Get the project on your machine', 'Follow the setup steps in the project readme.', 'The project starts on your machine.'],
                        ['Run the tests once', 'Use the test command from the readme.', 'The tests pass.'],
                    ],
                    'First week' => [
                        ['Read the architecture overview', 'Find it in the project docs.', 'You can explain the main parts in your own words.'],
                        ['Do one small starter task', 'Take the task your buddy picked.', 'The change is merged.'],
                        ['Meet the people you work with', 'Book a short call with each.', 'You know who to ask for what.'],
                    ],
                ]),
            ],
            // ---- Wiki layer (ARTEFACTS_CONTRACT.md section 10). `meta` is merged into the new page's metadata. ----
            'wiki-home' => [
                'title' => 'Wiki home', 'type' => 'markdown', 'tags' => ['wiki'], 'meta' => ['kind' => 'home'],
                'description' => 'The front page of the wiki: what this project is, and where to find things.',
                'body' => "# Wiki home\n\nWhat this project is, in two or three sentences.\n\n## Start here\n- [[Things you should know]] short notes that agents want you to read\n- [[How things work]]\n- [[Who owns what]]\n- [[Runbooks]]\n- [[Decisions log]]\n- [[Known issues]]\n- [[Gotchas]]\n- [[Glossary]]\n\n## Current focus\nWhat the team works on now.\n\n> [!TIP]\n> Give a page the tag `wiki` and link it from here or from another wiki page with a double-bracket link to its title, the way this page links [[Runbooks]]. It then shows under that page in the wiki tree.\n",
            ],
            'decisions-log' => [
                'title' => 'Decisions log', 'type' => 'markdown', 'tags' => ['wiki'], 'meta' => ['section' => 'Decisions log'],
                'description' => 'A dated list of decisions: what was decided, why, and what else was considered.',
                'body' => "# Decisions log\n\nOne entry for each decision. Newest at the bottom. Say why, so a later reader does not repeat the argument.\n\n## {{date}} Example: pick the file format\n- Decision: Notes are plain files in the project folder.\n- Reason: Git keeps the history and any editor can open them.\n- Alternatives considered: a database (harder to move), a wiki server (one more thing to run).\n- Decided by: person or agent name\n- Revisit when: the notebook grows past a few thousand pages\n",
            ],
            'runbook' => [
                'title' => 'Runbooks', 'type' => 'markdown', 'tags' => ['wiki'], 'meta' => ['section' => 'Runbooks'],
                'description' => 'How to do a repeatable job step by step, with a check after each step and the way back.',
                'body' => "# Runbooks\n\nOne section for each job that is done more than once. Write steps a new person can follow.\n\n## Example: restart the service\nWhen to use: the service does not answer.\n\n1. Check the state. Expect: the status says stopped or failed.\n2. Restart it. Expect: the status says running.\n3. Open the main page. Expect: it loads with no error.\n\nIf it fails: where to look, and who to ask.\n",
            ],
            'glossary' => [
                'title' => 'Glossary', 'type' => 'markdown', 'tags' => ['wiki'], 'meta' => ['section' => 'Glossary'],
                'description' => 'Words this project uses, each explained in plain language.',
                'body' => "# Glossary\n\nOne line for each term, in alphabetical order. Explain it for a reader who is new.\n\n- **Artefact**: a page, checklist or diagram that an agent leaves in the notebook for a person.\n- **Term**: its meaning in one sentence.\n",
            ],
            'known-issues' => [
                'title' => 'Known issues', 'type' => 'markdown', 'tags' => ['wiki'], 'meta' => ['section' => 'Known issues'],
                'description' => 'Faults we know about, how bad each is, and how to work around it.',
                'body' => "# Known issues\n\nOne entry for each fault that is not fixed yet. Remove or mark an entry when it is fixed.\n\n## {{date}} Example: slow first load\n- Seen in: the test environment\n- Effect: the first page takes ten seconds\n- Workaround: reload once\n- Tracked in: link to the issue\n",
            ],
            'gotchas' => [
                'title' => 'Gotchas', 'type' => 'markdown', 'tags' => ['wiki'], 'meta' => ['section' => 'Gotchas'],
                'description' => 'Traps that look fine but cost time: odd settings, steps that must go in order.',
                'body' => "# Gotchas\n\nA short list of traps. For each: what looks right, what is really true, and what to do.\n\n> [!WARNING]\n> Example: the test server and the live server look the same. Check the host name before you change anything.\n\n- \n",
            ],
            'how-it-works' => [
                'title' => 'How things work', 'type' => 'markdown', 'tags' => ['wiki'], 'meta' => ['section' => 'How things work'],
                'description' => 'A plain explanation of the main parts of the project and how they fit together.',
                'body' => "# How things work\n\nStart with the whole picture in one paragraph. Then one section for each main part.\n\n## The big picture\nWhat goes in, what comes out, and what happens in between.\n\n## Part one\nWhat it does. What it talks to. Where its code or settings live.\n\n## Part two\nSame questions.\n\nAdd a diagram as a separate page and link it, for example [[Architecture diagram]].\n",
            ],
            'who-owns-what' => [
                'title' => 'Who owns what', 'type' => 'markdown', 'tags' => ['wiki'], 'meta' => ['section' => 'Who owns what'],
                'description' => 'For each part of the project: the person or agent to ask.',
                'body' => "# Who owns what\n\n| Area | Owner | Ask them about | Backup |\n|---|---|---|---|\n| Example: build and release | name | failed builds, version numbers | name |\n|  |  |  |  |\n",
            ],
            'things-you-should-know' => [
                'title' => 'Things you should know', 'type' => 'markdown', 'tags' => ['wiki'], 'meta' => ['kind' => 'know', 'section' => 'Things you should know'],
                'description' => 'Short notes an agent wants a person to read. A new note raises a badge until it is read.',
                'body' => "# Things you should know\n\nShort notes from agents to you. Each note is one dated line, newest at the bottom, and says what you should know and why it matters. Open this page to mark the notes as read.\n\n",
            ],
            'api-reference' => [
                'title' => 'API reference', 'type' => 'markdown', 'tags' => ['api', 'reference'],
                'description' => 'The address, authentication, each call with request and reply, and the errors.',
                'body' => "# API reference\n\nBase address: \nVersion: \nUpdated: {{date}}\n\n## Authentication\nHow a caller proves who it is. Name the connection card that holds the key. Never write a key here.\n\n## Conventions\n- Format: JSON\n- Time: ISO 8601, UTC\n- Paging:\n- Rate limit:\n\n## Calls\n\n### List items\n`GET /items`\n\n| Parameter | Type | Required | Meaning |\n|---|---|---|---|\n| `limit` | number | no | How many items to return |\n\nReply:\n\n```json\n{ \"items\": [] }\n```\n\n### Create an item\n`POST /items`\n\nRequest:\n\n```json\n{ \"name\": \"example\" }\n```\n\nReply: `201` with the new item.\n\n## Errors\n| Status | Meaning | What to do |\n|---|---|---|\n| 400 | The request is not valid | Fix the request |\n| 401 | Missing or wrong key | Check the key |\n| 404 | No such item | Check the id |\n| 429 | Too many calls | Wait and try again |\n",
            ],
        ];
    }
}
