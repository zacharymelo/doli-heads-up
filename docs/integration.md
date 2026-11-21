# Integration guide

Other modules can tell Heads-up what something on their page is about, open the popup already filled in, react when a flag is saved, check a flag before it is saved, and choose a to-do's project.

## Page side

### `data-headsup-*` attributes

Put these attributes on an element or on any of its parents. Each value is taken from the nearest element that has that attribute.

| Attribute | Value |
|---|---|
| `data-headsup-element` | The record's element name, e.g. `project`, `propal`, or `myobject@mymodule` for a custom module |
| `data-headsup-id` | The record's id |
| `data-headsup-label` | A readable name for the record, shown as "Links to: …" |
| `data-headsup-page` | A link back to exactly this spot: a path inside Dolibarr, e.g. `/custom/mymodule/view.php?id=4#msg-12` |
| `data-headsup-title` | The title to start from |
| `data-headsup-quote` | The description to start from |
| `data-headsup-users` | User ids offered as quick choices instead of the short list, comma separated |
| `data-headsup-project` | A project id the to-do belongs to, which the Project field then shows as already chosen |

A record you declare this way wins over links found near the click.

```html
<div class="message"
     data-headsup-element="conversation@mymodule"
     data-headsup-id="42"
     data-headsup-label="Conversation with ACME"
     data-headsup-page="/custom/mymodule/conversation.php?id=42#m-981"
     data-headsup-project="7">
  …
</div>
```

### `window.HeadsupOpen(options)`

This opens the popup from your own button or menu. Every field is optional.

```js
window.HeadsupOpen({
	x: 200, y: 120,                          // where to place it (default: top right)
	record: { element: 'myobject@mymodule', id: 42, label: 'MYO-0042' },
	page: '/custom/mymodule/myobject_card.php?id=42',
	box: 'Shipping details',                 // where on the page, for the note
	title: 'Please check the address',
	description: 'The postcode looks wrong.',
	users: [3, 8],                           // quick choices instead of the short list
	project: { id: 7, label: 'PJ2611-0007' }, // label is looked up when left out
	onSent: function (reply) {               // reply.id is the new event's id
		console.log('Saved as event', reply.id);
	}
});
```

`HeadsupOpen` does nothing for users without the *Send* permission. It is defined by the script Heads-up links at the end of the page (from 0.3.22; earlier, in the head), so call it from a click handler or after `DOMContentLoaded`, not from an inline script that runs while the page loads.

### The `headsup:sent` event

After a flag is saved, Heads-up fires a `headsup:sent` event on `document`:

```js
document.addEventListener('headsup:sent', function (e) {
	// e.detail.id      the new event's id
	// e.detail.record  {element, id, label} or null
});
```

## Server side (PHP hooks)

Register your actions class for the hook's context in your module descriptor:

```php
$this->module_parts = array(
	'hooks' => array('data' => array('headsupflag', 'headsuptodo')),
);
```

### `headsupBeforeFlag` (context `headsupflag`)

This runs when someone sends a flag or problem report from the popup, before it is saved. Use it to check the flag, for example that the people chosen can open the record.

| Argument | Value |
|---|---|
| `$object` | The record the flag is about, or null |
| `$parameters['userids']` | `int[]`: the people chosen |
| `$parameters['secondary']` | The page's record when another record was clicked, or null |
| `$parameters['confirmed']` | `1` once the sender has confirmed your warnings |
| `$parameters['project']` | The sender's project choice: a project id, `-1` for none, `0` for automatic |

What your hook can do:

| You do | Effect |
|---|---|
| `$this->results['warnings'] = array('…')` | The sender sees the warnings and must confirm before the flag is saved |
| `$this->results['recordonly'] = true` | Links the record alone, without its third party, contact or project |
| `return -1` with `$this->error` set | Refuses the flag; the sender sees the error |

```php
public function headsupBeforeFlag($parameters, &$object, &$action, $hookmanager)
{
	if (is_object($object) && $object->element === 'myobject') {
		$cannot = array();
		foreach ($parameters['userids'] as $uid) {
			if (!mymodule_user_can_read((int) $uid, $object)) {
				$cannot[] = (int) $uid;
			}
		}
		if ($cannot) {
			$this->results['warnings'] = array(count($cannot).' of the people chosen cannot open this record.');
		}
	}
	return 0;
}
```

### `headsupTodoProject` (context `headsuptodo`)

This says which project a new to-do belongs to. It runs for every new to-do (flags, problem reports and assignments), except when the sender chose a project in the popup.

| Argument | Value |
|---|---|
| `$object` | The record the to-do is about, or null |
| `$parameters['code']` | `AC_HU_FLAG`, `AC_HU_PROBLEM` or `AC_HU_ASSIGN` |
| `$parameters['userids']` | `int[]`: the people it is assigned to |
| `$parameters['secondary']` | The other record, or null |
| `$parameters['project']` | The project Heads-up found on the record, or `0` |

Put `project` in your results: a project id, or `-1` for none. If you leave it out, the automatic project is kept. It is applied after `recordonly`, so a project you pass is kept even then. Heads-up checks that the project exists in the current entity.

```php
public function headsupTodoProject($parameters, &$object, &$action, $hookmanager)
{
	if (is_object($object) && $object->element === 'myobject' && !empty($object->fk_project)) {
		$this->results['project'] = (int) $object->fk_project;
	}
	return 0;
}
```

### Order of precedence for the project

1. The sender's choice in the popup's Project field (it must be a project the sender can see), including **No project**.
2. A `headsupTodoProject` hook.
3. The project of the clicked record, then of the page's record.

## Creating to-dos from your own code

```php
dol_include_once('/headsup/class/headsup.class.php');
$hu = new Headsup($db);
$id = $hu->createTodo(
	$user,                 // sender
	Headsup::CODE_FLAG,    // or CODE_PROBLEM, CODE_ASSIGN
	array(3, 8),           // assigned users
	'Please check this order',
	Headsup::buildNote('Free text', array('page' => '/commande/card.php?id=12')),
	$order,                // main record, or null
	null,                  // secondary record, or null
	array('project' => 7)  // optional: project id, -1 none, 0 automatic; 'recordonly' => true
);
// $id > 0 is the new event's id; < 0 is an error in $hu->error
```

## AJAX endpoints

These are used by `headsup-main.js`. They answer in JSON and need a logged-in session. Every POST needs the CSRF `token`.

| Endpoint | Method | Purpose |
|---|---|---|
| `ajax/flag.php` | POST | Save a flag (`kind=todo`) or problem report (`kind=problem`). Fields: `users[]`, `title`, `description`, `page`, `box`, `clicked`, `element`, `elementid`, `clickedelement`, `clickedid`, `project`, `confirm` |
| `ajax/queue.php` | GET | `{count, latest}` for the top bar and notifications |
| `ajax/queue.php` | POST | `action=status` with `status` (`todo`, `ack`, `progress`, `done`), `action=progress` with `percent`, or `action=done`; each also takes `id` |
| `ajax/users.php` | GET | The active users for "Assign to" |
| `ajax/resolve.php` | GET | Which record a list of `links[]` points to |
| `ajax/projects.php` | GET | Open projects the user can see: `q` to search, or `id` for one project |
| `ajax/debug.php` | GET | Diagnostics, for admins with Debug mode on |
