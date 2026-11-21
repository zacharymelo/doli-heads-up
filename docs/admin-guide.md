# Admin guide

## Installing

1. Upload `headsup-X.Y.Z.zip` in **Home → Setup → Modules → Deploy external module**.
   - Or copy the repository's `module/` folder to `htdocs/custom/headsup/` by hand.
2. Enable **Heads-up**, which is in the CRM family. It also enables the Agenda module, which it depends on.

When you enable it, the module:

- adds three event types to the agenda dictionary: **Flag** (`AC_HU_FLAG`), **Problem report** (`AC_HU_PROBLEM`) and **Assignment** (`AC_HU_ASSIGN`)
- sets its default settings
- adds its permissions and left-menu entries

Disabling the module keeps the event types, so existing to-dos keep their type.

### Upgrading

Upload the newer zip over the old one.

If a version adds menu entries, turn the module off and on again to create them. For example, 0.3.7 added the **Completed** menu entry.

## Setup

Go to **Home → Setup → Modules → Heads-up**, then click the setup icon.

| Setting | Constant | Default | What it does |
|---|---|---|---|
| To-do on assignment | `HEADSUP_NOTIFY_ASSIGNMENTS` | On | Creates a to-do when someone adds another user to a project, task, proposal, order or invoice, or makes them a third party's sales rep |
| Problem reports go to | `HEADSUP_PROBLEM_USER` | First active admin | Who is selected already in the "Report a problem" tab |
| Show every user in the popup | `HEADSUP_USERS_SHOW_ALL` | Off | On: every active user is a button under "Assign to". Off: only the short list, and anyone else is found by typing |
| Short list | `HEADSUP_SHORTLIST` | Empty | The people offered as quick choices under "Assign to" |
| Status control | `HEADSUP_STATUS_CONTROL` | Both | What My queue rows and the event card show: the progress slider, the status picker, or both |
| Project field in the popup | `HEADSUP_PROJECT_FIELD` | Off | Adds a Project search box to the popup. It stays on Automatic unless the sender searches for one of the open projects they can see, or picks No project |
| Top bar refresh (seconds) | `HEADSUP_REFRESH_SECONDS` | 60 | How often the open count next to the flag icon refreshes (15 at least) |
| Debug mode | `HEADSUP_DEBUG_MODE` | Off | Turns on the diagnostics page described below |

## Permissions

| Permission | Default | Allows |
|---|---|---|
| Send flags and problem reports (`headsup` → `send`) | On for new users | Opening the popup, Alt+click, pick mode |
| See everyone's flags and problem reports (`headsup` → `readall`) | Off | The **Everyone's open flags** tab, and changing the state of anyone's to-do |

Everyone can see their own queue.

These people can change a to-do's state:

- the people it is assigned to
- its sender
- users with `readall`
- users allowed to edit every event (Agenda → `allactions` → `create`)

## Menus

Heads-up adds a top-level **Heads-up** menu with these entries:

- My queue
- Sent by me
- Completed
- Everyone's open flags (only with `readall`)

The flag icon in the top bar opens a small menu with two items: **Flag something on this page** (pick mode) and **My queue**.

## Where the data lives

Heads-up has no tables of its own. Every to-do is a row in `llx_actioncomm` with one of the three codes above, assigned to its users through `llx_actioncomm_resources`. This means:

- the to-dos appear in the Agenda module's lists and calendars, filtered by those event types
- backups, exports and entity (multicompany) separation work as they do for any event
- deleting the module never deletes to-dos

## Troubleshooting

### Diagnostics page

Turn on **Debug mode** in setup, then open `/custom/headsup/ajax/debug.php` as an admin. It prints plain text, and `?mode=` selects what to show:

- `overview` (the default)
- `events`
- `settings`
- `all`

The page is refused to non-admins and whenever Debug mode is off.

### Common problems

- **No flag icon in the top bar:** check that the module is enabled. Then check that the user has the *Send* permission, or that the icon still shows the count without pick mode.
- **Alt+click does nothing:** some operating systems or browser extensions use Alt+click themselves. Use pick mode from the flag icon instead.
- **The Completed menu entry is missing after an upgrade:** turn the module off and on again.
- **A new feature is missing after an upgrade, on one device only:** that browser is running a cached copy of the old script. From 0.3.22 the script is linked with its file date, so this should not happen again; for an older version, clear that browser's data for the site.
- **An assignment created no to-do:** check these in order:
  - Assigning yourself never creates one.
  - Only internal contacts (users) count, not external contacts.
  - Check that **To-do on assignment** is on.
