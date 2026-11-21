# Heads-up for Dolibarr

Alt+click anything in Dolibarr to flag it for a colleague, report a problem with a page, or get a to-do when a record is assigned to you. The person you flag sees what you meant: the page, the box, the record, and a link back to the exact spot.

Heads-up adds **no database tables**. Every flag is a native Dolibarr agenda event (a to-do), so it shows on the record's Events tab, in the agenda, and in Dolibarr's own reports.

## Features

- **Flag anything**: Alt+click (Option+click on a Mac) any element, or use pick mode from the flag icon in the top bar. Assign it to one or more people with a title and a description.
- **Report a problem**: a second tab in the same popup, sent by default to the person chosen in setup, with the title filled in from the box that was clicked.
- **Knows the record**: the link (or table row) that was clicked becomes the to-do's main record, with its third party and project. The page's own record fills in what is still empty.
- **Link it later**: a to-do's Modify form has a searchable **Linked object** field, to link it to a record after it is sent, whether it has none yet or should point elsewhere.
- **Assignment to-dos**: when someone adds a user as an internal contact on a project, task, proposal, order or invoice, or as a third party's sales representative, that user gets a to-do.
- **My queue**: tabs for *For me*, *Assigned to me*, *Sent by me*, *Completed* and *Everyone's open flags*, with a progress slider and a status picker on each row. On a phone, each to-do is shown as a card.
- **Four states** (to do, acknowledged, in progress, done) kept in the event's percent, so Dolibarr's agenda agrees. Each change is logged on the event.
- **Browser notifications** when a new to-do reaches you, shown once across all your tabs.
- **Projects**: the to-do takes the record's project automatically. Other modules can choose the project through a hook, and an optional Project search field can be added to the popup.
- **For other modules**: `data-headsup-*` attributes, a `window.HeadsupOpen()` JavaScript call, a `headsup:sent` event, and two PHP hooks.

## Requirements

- Dolibarr 20.0 or later (developed and tested on 24.0)
- PHP 7.4 or later
- The Agenda module (enabled automatically)

## Install

1. Get `headsup-X.Y.Z.zip`, either from the [Releases](https://github.com/zacharymelo/doli-heads-up/releases) page or by running `./build.sh` in a clone.
2. In Dolibarr, go to **Home → Setup → Modules → Deploy external module** and upload the zip.
3. Enable **Heads-up** (in the CRM family) and open its setup page.
4. Give users the **Send flags and problem reports** permission (it is on by default for new users) and, for managers, **See everyone's flags and problem reports**.

To install by hand instead, copy the `module/` folder to `htdocs/custom/headsup/`.

## Documentation

| Guide | For |
|---|---|
| [User guide](docs/user-guide.md) | Sending flags, the popup, My queue, states and notifications |
| [Admin guide](docs/admin-guide.md) | Installing, setup options, permissions, menus and troubleshooting |
| [Integration guide](docs/integration.md) | Other modules: page attributes, the JavaScript API and the PHP hooks |
| [Architecture](docs/architecture.md) | How to-dos are stored, linked and moved between states, and a file map |
| [Development](docs/development.md) | Local Docker environment, building the zip, static analysis and releases |
| [Changelog](CHANGELOG.md) | What changed in each version |

## Not in this version

- Email when you're flagged
- Attaching a file from the popup (events already accept files on their own card)
- Checklists

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
