# Changelog

## 0.3.15

- The flag icon's menu now keeps itself on the screen at any window width, not just on a phone. 0.3.14 fixed the clipping below 600px, but the width at which Dolibarr moves the top bar icons into the slide-out menu is the theme's business, not ours: on a window a little wider than that the menu still ran off the left edge. It is now measured where it actually lands and pulled back, so the breakpoint no longer matters. Lines wrap rather than overflow when the menu is wider than the window.

## 0.3.14

- The flag icon's menu is readable on a phone again. On a small screen Dolibarr moves the top bar icons into the slide-out menu, part-way across the screen, and the menu hung under the icon's right edge ran off the left: the icon and the first words of **New to-do**, **Flag something on this page** and **My queue** were cut off and could not be tapped. It now spans the screen under the icon, with taller rows and labels that wrap.

## 0.3.13

- **Mentions** in a to-do's conversation, so nobody has to send a Heads-up about a Heads-up: type **@** in the comment box and pick someone (or type `@login`). Their name is highlighted in the comment.
- A mention waits for an answer: it counts in the mentioned person's top bar bubble and puts the to-do at the top of their My queue with **Sam mentioned you**, until they comment back or the to-do is done. They also get a browser notification that opens the comment.
- Someone mentioned can read and join that conversation even when the to-do is not theirs. The to-do is not assigned to them, and they cannot change its state.
- The Conversation page no longer asks for an Agenda permission, like My queue.

## 0.3.12

- New **Conversation** tab on a to-do's event card, for comments back and forth: the to-do first, then the comments oldest first, yours on the right, and a box to write one. The tab shows the number of comments. Comments are plain text; you can delete your own, an administrator anyone's.
- My queue rows link to the conversation: **Comment**, or the number of comments.
- A browser notification when someone else comments on a to-do you sent or are assigned to. Clicking it opens that conversation.
- Comments are kept in Dolibarr's own comment table (the one task comments use), so the module still adds no tables. Deleting a to-do deletes its comments.
- Code cleanup: the module passes phpcs, phpstan and phan with no findings.

## 0.3.11

- Same as 0.3.10, built with New to-do and the phone popup. The first 0.3.10 zip was built before them; this number tells the two apart in Setup → Modules.

## 0.3.10

- The stylesheet is linked again with its file date, so browsers (Safari above all) load the new one after an update instead of a cached copy. Dolibarr itself links module stylesheets with no version.
- Layout check: add `headsup_overflow=1` to any page's address to outline whatever sticks out past the right edge of the screen, with a list at the top of the page. It also says whether this version's My queue page and stylesheet are the ones loaded.

## 0.3.9

- New **New to-do**, linked to no record or page, for things outside Dolibarr (a call, an email still to come): in the flag icon's menu, as a button on My queue, and as **Heads-up → New to-do** in the left menu (re-enable the module to get the menu entry). `HeadsupOpen()` accepts `standalone: true`.
- The popup has a close button. On phones it fills the screen with its header and Send/Cancel kept in view, 16px fields, and the back gesture closes it.
- The pick mode banner has a Cancel button, since touch screens have no Esc key.
- **Assigned to me** no longer makes My queue wider than a phone screen. Below 1000px each row shows on two lines: the ref with its status, date or amount, then the title underneath. If a single word is still too wide, that table scrolls sideways on its own instead of the whole page.

## 0.3.8

- The popup's **Project** field is now a search: it starts on Automatic, searches the open projects the sender can see as they type (Enter picks the first result), offers **No project**, and the × goes back to Automatic.
- Projects passed in by `data-headsup-project` or `HeadsupOpen({project})` show as chosen. When only an id is given, its name is looked up.

## 0.3.7

- New **Completed** tab and menu entry: done to-dos assigned to you, most recently finished first, with when and by whom, a page at a time. Reopen one from its slider or picker.
- New `headsupTodoProject` hook (context `headsuptodo`), so other modules can choose a to-do's project.
- New optional **Project field** in the popup, set in setup and off by default.
- `headsupBeforeFlag` now receives the sender's project choice in `$parameters['project']`.
- The event card's **Status** dropdown no longer shows a tooltip over its first item.

## 0.3.5

- First public version:
  - flags and problem reports from Alt+click or pick mode
  - record detection from clicked links and rows
  - assignment to-dos
  - My queue
  - four states with a progress slider and a status picker
  - browser notifications
  - `data-headsup-*` attributes, `HeadsupOpen()`, the `headsup:sent` event and the `headsupBeforeFlag` hook
