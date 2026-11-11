# Heads-up — Dolibarr module

Flag anything in Dolibarr for someone, report a problem with a page, and get a to-do when a record is assigned to you. Nothing leaves the page.

Everything is stored as Dolibarr agenda events (to-dos). The module adds **no tables**: three event types (Flag, Problem report, Assignment), a top bar icon, a popup and a queue page.

## Sending a flag

- **Alt+click** (Option+click on a Mac) anything on any page. The click is caught before the browser acts on it, so links and buttons don't fire.
- Or click the **flag icon** in the top bar, choose **Flag something on this page**, then click what you mean ("pick mode", Esc cancels).

The popup has two tabs:

- **To-do**: assign to one or more people, with a title and a description.
- **Report a problem**: pre-selects the person set in setup (default: the first admin) and fills in the title from the box that was clicked, e.g. "Problem: Customers going quiet".

It records where the user was, so they don't have to explain: the page, the title of the nearest box, and the text they clicked.

**Linking the record that was clicked.** The browser can't send "the record", only what it sees, and in Dolibarr a record's identity is in its links (`/projet/card.php?id=1`). So the popup takes the link that was clicked, or the links in the same table row when plain text was clicked (e.g. a project's title in the project list). The server matches them against Dolibarr's card and tab URLs (projects, tasks, third parties, contacts, proposals, orders, invoices, shipments, receptions, supplier orders and invoices, products, events, interventions, contracts, tickets, MOs, BOMs, and custom modules' `*_card.php?id=`) and loads the record to check it exists. The popup shows "Links to: …" before sending.

The clicked record becomes the event's main link, so the to-do appears on that record's Events tab, with its project and third party. The record whose card the user was on fills in whatever is still empty. The note also says "Linked to: PJ2611-0042 … (project #42)".

On the event card the context is clickable: **Page** opens the exact page the flag was sent from, and **Linked to** opens the clicked record's card.

## Who appears under "Assign to"

Set by an admin in setup:

- **Show every user in the popup** (off by default): on lists everyone as a button. Off shows only the short list, and anyone else is found by typing.
- **Short list**: the people shown as quick choices.

## Assignments

When someone adds another user to a record, that user gets an **Assignment** to-do: "Project leader on PJ2611-0042 …". This covers any internal contact role on a project, task, proposal, order or invoice (`*_ADD_CONTACT` triggers) and a third party's sales representative (`COMPANY_LINK_SALE_REPRESENTATIVE`). Assigning yourself creates nothing. It can be switched off in setup.

## My queue

Menu **Heads-up → My queue**, or the flag icon in the top bar. Its bubble shows the open count (hidden at zero, refreshes every minute), and clicking it opens a small menu: **Flag something on this page** (pick mode) and **My queue**.

- **For me**: open flags, problem reports and assignments, with who sent each one, the record, a link back to the exact page, and its **Status** (to do, acknowledged, in progress, done).
- **Assigned to me**, read straight from the records, so leads that arrived without an assignment event (email collectors, imports) still show: open leads and projects I'm on (with lead status and last activity), my open tasks, open proposals and orders I follow, third parties I'm the sales rep for.
- **Sent by me**, and **Everyone's open flags** (needs the "See everyone's flags" permission).
- **Completed**: every to-do assigned to me that is done, most recently finished first, with when and by whom, a page at a time. Its slider or picker reopens one.

These are tabs at the top of the page and entries in the left menu (the Completed menu entry appears after the module is turned off and on again).

## To do, acknowledged, in progress, done

Each to-do is in one of four states, kept in the event's own percent so Dolibarr's agenda shows it too:

- **To do** (0%): new, counted in the top bar bubble.
- **Acknowledged** (10%): seen.
- **In progress** (50%, the value core's own "In progress" sets): being worked on. Any other percent set by hand on the event card also counts as in progress.
- **Done** (100%).

Only **To do** counts in the bubble; acknowledged and in-progress to-dos stay in My queue.

Change it with the **progress slider** (stops at 0, 10, 25, 50, 75 and 100%; the state follows the stop, and arrow keys move one stop at a time) or the **Status** picker, both on each My queue row, or on the event card next to the **Status: …** button. Below 1000px wide, My queue shows each to-do as a card, so the controls are reachable on a phone without scrolling sideways. Each change adds a line to the event's description, e.g. "Acknowledged by Becca Long, 11/11/2026 10:49 AM", so the sender can see who saw it and when. The people it is assigned to, its sender, anyone who sees everyone's flags, and anyone allowed to edit every event can change it.

## Browser notifications

When a new flag, problem report or assignment reaches you, the browser shows a notification ("Heads-up from Zach: …"); clicking it opens My queue. The browser asks for permission the first time you open the flag menu or the popup. Each to-do is shown once across all your tabs, and background tabs keep checking only once notifications are allowed.

## For other modules

- **Say what Alt+click on your page is about:** put `data-headsup-*` attributes on the element or any parent; each is taken from the nearest element that has it. `element`, `id`, `label`: the record (`myobject@mymodule`), which then wins over links found nearby. `page`: a link back to exactly that spot, a path inside Dolibarr. `title`, `quote`: the title and description to start from. `users`: user ids offered as quick choices, comma separated. Meta Inbox (metamessenger) tags every message this way, so Alt+clicking one pings someone with a link that opens the conversation at that message.
- **Hear when a flag is saved:** Heads-up fires `headsup:sent` on `document`, with `detail.id` (the event) and `detail.record`.
- **Open the popup already filled in:** `window.HeadsupOpen({x, y, record: {element, id, label}, page, box, title, description, users, onSent})`. Every field is optional; `users` replaces the short list as quick choices, and `onSent(reply)` runs once it is saved. The attributes above take the same fields.
- **Check a flag before it is saved:** hook `headsupBeforeFlag`, context `headsupflag`. `$object` is the record, `$parameters['userids']` the people chosen, `$parameters['project']` the sender's project choice (a project id, -1 for none, 0 automatic). Put `warnings` (strings) in `results` to make the sender confirm first, or `recordonly` to link the record without its third party, contact or project.
- **Say which project a to-do belongs to:** hook `headsupTodoProject`, context `headsuptodo`. It runs for every new to-do (flags, problem reports and assignments) unless the sender chose a project in the popup. `$object` is the record, `$parameters` holds `code`, `userids`, `secondary` (the other record, or null) and `project` (the project Heads-up found on the record, or 0). Put `project` in `results`: a project id, or -1 for none. Leave it out to keep the automatic one. It is applied after `recordonly`, so a project you pass is kept. From the page side, `data-headsup-project` or `HeadsupOpen({project: {id, label}})` passes one too; it must be a project the sender can see.

## Permissions

- Send flags and problem reports (on by default)
- See everyone's flags and problem reports

## Setup

Home → Setup → Modules → Heads-up: to-do on assignment (on/off), who receives problem reports, show every user in the popup (on/off), short list, status control (slider, status picker or both, on My queue and the event card; default both), project field in the popup (off by default: Automatic, No project, or one of the open projects the sender can see), top bar refresh interval, debug mode (`/custom/headsup/ajax/debug.php`).

## Not in this version

- Email when you're flagged
- Attaching a file from the popup (events already accept files on their own card)
- Checklists

## Development

```bash
docker compose up -d        # Dolibarr 24 on http://localhost:8141
./build.sh                  # headsup-X.Y.Z.zip for Home → Setup → Modules → Deploy external module
```
