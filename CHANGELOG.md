# Changelog

## 0.3.10

- The stylesheet is linked again with its file date, so browsers (Safari above all) load the new one after an update instead of a cached copy. Dolibarr itself links module stylesheets with no version.
- Layout check: add `headsup_overflow=1` to any page's address to outline whatever sticks out past the right edge of the screen, with a list at the top of the page. It also says whether this version's My queue page and stylesheet are the ones loaded.

## 0.3.9

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
