# Architecture

## Storage

Heads-up adds no tables. A to-do is an `ActionComm` (agenda event), and its conversation is rows of core's `llx_comment` (see [Conversation](#conversation)):

| Event field | Heads-up meaning |
|---|---|
| `code` / `type_code` | `AC_HU_FLAG`, `AC_HU_PROBLEM` or `AC_HU_ASSIGN`, three event types the module adds to `c_actioncomm` (ids 520901–520903) |
| `label` | The title |
| `note_private` | The description, followed by the context: page, box, clicked text, and the linked record with its URL. The state changes are appended here too |
| `fk_user_author` | The sender |
| `userownerid`, `userassigned` | The people it is assigned to (the first is the owner) |
| `percentage` | The state (see below) |
| `elementtype`, `fk_element` | The record it is about (`myobject@mymodule` for custom modules) |
| `socid`, `fk_project`, `contact_id` | That record's third party, project and contact |

### States

| Percent | State |
|---|---|
| `0` | To do (the only state counted in the top bar bubble) |
| `10` | Acknowledged |
| `1`–`99` (except 10) | In progress (`50` when set from the picker) |
| `≥ 100`, or `< 0` | Done |

The slider stops at `0, 10, 25, 50, 75, 100`. `Headsup::setStatus()` and `Headsup::setProgress()` change the percent. They also append "*State* by *User*, *date*" to the note.

## How a flag is linked

`Headsup::createTodo()` builds the event like this:

1. **Main record:** the record that was clicked. If nothing was clicked, it is the page's record. `linkToObject()` sets:
   - the record itself as `elementtype`/`fk_element`
   - its third party (`socid`/`fk_soc`)
   - its project (`fk_project`)
   - for a contact, `contact_id`
2. **Secondary record:** the page's record, when it differs from the main record. It fills in only the links that are still empty.
3. **`recordonly`** (from the `headsupBeforeFlag` hook): clears the third party, project and contact.
4. **Project:** the sender's choice. If there is none, the `headsupTodoProject` hook decides. If no hook answers, the project found in steps 1–2 is kept.

### Changing the link afterwards

The event's **Modify** form gets a **Linked object** field on Heads-up to-dos (the `formObjectOptions` hook), which `headsup.js` puts in place of core's read-only row. It searches the records the user can open (`ajax/records.php`, `Headsup::searchRecords()`), takes a pasted link, or removes the link.

On save, `ActionsHeadsup::prepareRelink()` (the `doActions` hook, before core's update):

1. checks the record exists and that the user can open it (`Headsup::canSeeRecord()`: the module's read permission, then core's `checkUserAccessToObject()`, which covers entities and sales representatives)
2. gives the form's own third party and project fields the record's (or none), as `linkToObject()` would for a new flag, and, for a contact, the contact. A field the user changed in the same form is left as they set it
3. adds "Linked to *record* by *User*, *date*" (or "Link to … removed by …") to the description
4. puts the new linked object in `Headsup::$pendingLinks`. The `ACTION_MODIFY` trigger writes it during core's update, through `Headsup::setLinkedRecord()`, because `ActionComm::update()` can set `elementtype`/`fk_element` but never clear them

A third party, project or contact is not a linked object: picking one clears `elementtype`/`fk_element` and sets that field.

### From the browser to a record

The browser sends the `href`s it sees. `Headsup::parseRecordUrl()` matches them against core card and tab URLs, plus custom modules' `*_card.php?id=` pages. `Headsup::fetchRecord()` then loads the record to check that it exists.

`ActionsHeadsup::printCommonFooter` passes the page's own record to the browser, but only when the page URL is that record's card or tab. List pages reuse `$object` for every row, so their last row is never taken as "the page's record".

## Conversation

A to-do's comments are rows of core's `llx_comment`, the table project task comments use, with `element_type = 'action'` and `fk_element` = the event id. They are written through core's `Comment` class, without its triggers (`TASK_COMMENT_CREATE` and `TASK_COMMENT_DELETE` are about tasks). Comments are plain text.

- `Headsup::canComment()` decides who may read and write: whoever may change the state, plus anyone allowed to read every event. `comments.php` also lets in anyone mentioned in the conversation (`Headsup::isMentioned()`), and asks for no Agenda permission, like My queue.
- The **Conversation** tab is added by the `completeTabsHead` hook, so it shows on Heads-up to-dos only and not on other events.
- The `ACTION_DELETE` trigger deletes a to-do's comments with it.
- `ajax/queue.php` also returns `latestComment`: the newest comment by someone else on a to-do the user sent or is assigned to. `headsup.js` shows it once as a browser notification.

### Mentions

A mention is `@login` in a comment's text. Nothing else is stored: who is mentioned, and whether it still waits, is read from the comments.

- `Headsup::openMentionsFor()` returns the mentions of a user that still wait: on a to-do that is not done, written by someone else, with no later comment by that user. SQL narrows with `LIKE '%@login%'`, then `Headsup::textMentions()` checks the whole login, so `@sam` is not found in `@samuel` or `@sam.smith`.
- `Headsup::countWaitingFor()` is the top bar number: `countOpenFor()` plus the to-dos with a waiting mention that it does not already count.
- My queue adds those to-dos to **For me**, with the status controls only for people who may change the state.
- `latestComment` in `ajax/queue.php` is the waiting mention when it is the newest, with `mention: 1`.
- `comments.php` passes the active users in the form's `data-users`; `headsup.js` (`initMentions`) lists them after an `@`. `Headsup::formatMentions()` shows each `@login` as the person's name.

## Assignments

`InterfaceHeadsupTrigger` listens to two kinds of trigger (besides `ACTION_DELETE` for comments and `ACTION_MODIFY` for [a changed link](#changing-the-link-afterwards)):

- `*_ADD_CONTACT` (any element): the newest internal contact on the record is taken as the user just added, because `add_contact()` does not say which one it added. Their contact role becomes the title, e.g. "Project leader on PJ2611-0042".
- `COMPANY_LINK_SALE_REPRESENTATIVE`: the user in `$object->context['commercial_modified']`.

Both are skipped when the user assigns themselves or when **To-do on assignment** is off.

## Hooks used

| Context | Hook | Does |
|---|---|---|
| `main` | `printTopRightMenu` | The flag icon, open count and its menu |
| `main` | `printCommonFooter` | `window.HEADSUP`: URLs, token, rights, setup choices and the page's record |
| `main` | `doActions` | The event card's `action=headsup_status` links (checks the token and who may change it); on the Modify form's save, a changed linked object |
| `main` | `formObjectOptions` | The **Linked object** field on a to-do's Modify form |
| `main` | `addMoreActionsButtons` | The progress slider and **Status: …** button on the event card |
| `main` | `completeTabsHead` | The **Conversation** tab on a to-do's event card, with the number of comments |

Hooks provided for other modules: `headsupBeforeFlag` (context `headsupflag`) and `headsupTodoProject` (context `headsuptodo`). See the [integration guide](integration.md).

## Files

```
module/
├── admin/setup.php                      Setup page
├── ajax/
│   ├── flag.php                         Save a flag or problem report
│   ├── queue.php                        Open count, newest comment; change state or progress
│   ├── users.php                        Active users for "Assign to"
│   ├── resolve.php                      Links → record, for "Links to: …"
│   ├── projects.php                     Project search for the Project field
│   ├── records.php                      Record search for the Modify form's Linked object field
│   └── debug.php                        Diagnostics (admins, Debug mode)
├── class/
│   ├── headsup.class.php                Core logic: create, link, states, queries
│   └── actions_headsup.class.php        Hooks on the 'main' context
├── core/
│   ├── modules/modHeadsup.class.php     Module descriptor, event types, menus, rights
│   └── triggers/interface_99_modHeadsup_HeadsupTrigger.class.php   Assignment to-dos; comments of a deleted to-do; a changed link
├── comments.php                         Conversation tab of a to-do's event card
├── css/headsup.css                      Popup, queue, slider, conversation, cards on small screens
├── js/headsup.js                        Alt+click, pick mode, popup, notifications, queue controls
├── langs/en_US/headsup.lang             Translations
└── queue.php                            My queue page and its tabs
```
