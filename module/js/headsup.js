/* Heads-up: Alt+click (Option+click) anything, or click the flag in the top
 * bar and then click something, to open a small popup that sends a to-do or
 * a problem report without leaving the page.
 *
 * Settings and the record on the page come from window.HEADSUP, printed in
 * the page footer by ActionsHeadsup::printCommonFooter().
 */
(function () {
	'use strict';

	var picking = false;
	var popup = null;
	var usersCache = null;
	/* On a phone the popup adds a history entry, so the back gesture closes it */
	var historyPushed = false;

	function cfg() {
		return window.HEADSUP || null;
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'text') {
					node.textContent = attrs[k];
				} else if (k === 'className') {
					node.className = attrs[k];
				} else {
					node.setAttribute(k, attrs[k]);
				}
			});
		}
		(children || []).forEach(function (c) {
			if (c) {
				node.appendChild(c);
			}
		});
		return node;
	}

	function clean(text, max) {
		text = (text || '').replace(/\s+/g, ' ').trim();
		return text.length > max ? text.slice(0, max - 1) + '…' : text;
	}

	/* ---------- What was clicked ---------- */

	var HEADING_SELECTOR = '.titre, .titre_inline, .boxtitle, .tagtdtitle, .fichetitle, h1, h2, h3, h4, .box-flex-item-title, .liste_titre_bydiv, legend';

	/* A real heading: short text, no filters or inputs inside it. */
	function titleOf(node) {
		if (!node || node.querySelector('select, input, textarea, button')) {
			return '';
		}
		var t = (node.innerText || '').replace(/\s+/g, ' ').trim();
		return (t && t.length <= 80) ? t : '';
	}

	function headingText(node) {
		if (!node || node.nodeType !== 1) {
			return '';
		}
		if (node.matches && node.matches(HEADING_SELECTOR)) {
			return titleOf(node);
		}
		var all = node.querySelectorAll ? node.querySelectorAll(HEADING_SELECTOR) : [];
		for (var i = 0; i < all.length && i < 5; i++) {
			var t = titleOf(all[i]);
			if (t) {
				return t;
			}
		}
		return '';
	}

	/* The title of the nearest box or section around the click. */
	function boxTitle(target) {
		var node = target;
		var depth = 0;
		while (node && node !== document.body && depth < 25) {
			if (node.matches && node.matches(HEADING_SELECTOR) && titleOf(node)) {
				return titleOf(node);
			}
			/* A box that starts with its own heading (Dolibarr boxes, tables with a title row). */
			if (node.firstElementChild && node !== target) {
				var first = node.firstElementChild;
				var t = first.matches && first.matches(HEADING_SELECTOR) ? titleOf(first) : '';
				if (!t && node.tagName === 'TABLE') {
					/* Only a table whose title row is a single heading cell, not column names */
					var row = node.querySelector('tr.liste_titre');
					var cells = row ? row.querySelectorAll('th, td') : [];
					t = (cells.length && cells[0].colSpan > 1) ? titleOf(cells[0]) : '';
				}
				if (t) {
					return t;
				}
			}
			/* A heading just before this block (load_fiche_titre puts it in a sibling). */
			var sib = node.previousElementSibling;
			for (var i = 0; sib && i < 4; i++, sib = sib.previousElementSibling) {
				var h = headingText(sib);
				if (h) {
					return h;
				}
			}
			node = node.parentElement;
			depth++;
		}
		return '';
	}

	function clickedText(target) {
		var text = clean(target.innerText || target.value || target.getAttribute('title') || target.getAttribute('alt') || '', 120);
		if (!text) {
			var labelled = target.closest('[title]');
			text = labelled ? clean(labelled.getAttribute('title'), 120) : '';
		}
		return text;
	}

	/* Links that may point at the record the user means: the link clicked,
	 * then the other links in the same table row. The server works out
	 * which one is a record. */
	function nearbyLinks(target) {
		var out = [];
		function add(a) {
			var href = a && a.getAttribute('href');
			if (href && href.charAt(0) !== '#' && href.indexOf('javascript:') !== 0 && out.indexOf(a.href) < 0) {
				out.push(a.href);
			}
		}
		add(target.closest('a[href]'));
		var row = target.closest('tr');
		if (row) {
			Array.prototype.forEach.call(row.querySelectorAll('a[href]'), add);
		}
		return out.slice(0, 8);
	}

	/* ---------- Popup ---------- */

	/* Phone-sized screen: the popup fills it instead of sitting next to the click */
	function isSmallScreen() {
		return window.matchMedia && window.matchMedia('(max-width: 600px)').matches;
	}

	/* how: 'history' when the back gesture closed it, 'reopen' when another popup replaces it */
	function closePopup(how) {
		if (popup) {
			popup.remove();
			popup = null;
		}
		document.documentElement.classList.remove('headsup-popup-open');
		document.removeEventListener('keydown', onPopupKey, true);
		if (historyPushed && how !== 'reopen') {
			historyPushed = false;
			if (how !== 'history') {
				window.history.back();
			}
		}
	}

	window.addEventListener('popstate', function () {
		if (popup && historyPushed) {
			historyPushed = false;
			closePopup('history');
		}
	});

	function onPopupKey(e) {
		if (e.key === 'Escape') {
			e.preventDefault();
			closePopup();
		}
	}

	function toast(message, isError) {
		var t = el('div', { className: 'headsup-toast' + (isError ? ' headsup-toast-error' : ''), text: message });
		document.body.appendChild(t);
		setTimeout(function () {
			t.classList.add('headsup-toast-out');
		}, 2200);
		setTimeout(function () {
			t.remove();
		}, 2700);
	}

	function setCount(n) {
		var badge = document.querySelector('.headsup-badge');
		if (!badge) {
			return;
		}
		var span = badge.querySelector('.headsup-count');
		if (span) {
			span.textContent = String(n);
		}
		badge.classList.toggle('headsup-badge-zero', !(n > 0));
		var inmenu = document.querySelector('.headsup-menu-count');
		if (inmenu) {
			inmenu.textContent = '(' + n + ')';
		}
	}

	/* Open projects the user can see matching q, or the one project with that id */
	function findProjects(q, id) {
		var params = new URLSearchParams();
		if (id) {
			params.append('id', String(id));
		} else {
			params.append('q', q);
		}
		return fetch(cfg().projectsUrl + '?' + params.toString(), { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) { return (data && data.projects) || []; });
	}

	function projectName(p) {
		return p.ref + (p.title ? ' – ' + p.title : '');
	}

	function loadUsers() {
		if (usersCache) {
			return Promise.resolve(usersCache);
		}
		return fetch(cfg().usersUrl, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				usersCache = (data && data.users) || [];
				usersCache.showAll = !!(data && data.showAll);
				usersCache.shortlist = (data && data.shortlist) || [];
				return usersCache;
			});
	}

	/* What a page says about the clicked spot, through data-headsup-* attributes on it
	 * or any parent (each attribute from the nearest element that has it):
	 *   data-headsup-element, -id, -label   the record it is about ('myobject@mymodule')
	 *   data-headsup-page                   link back to exactly this spot (path in Dolibarr)
	 *   data-headsup-title, -quote          title and description to start from
	 *   data-headsup-users                  user ids offered as quick choices, comma separated
	 *   data-headsup-project                project id the to-do belongs to
	 * Same shape as window.HeadsupOpen()'s options. */
	function fromPage(target) {
		function attr(name) {
			var node = target.closest('[data-headsup-' + name + ']');
			return node ? node.getAttribute('data-headsup-' + name) : '';
		}
		var opts = {};
		var element = attr('element');
		var id = parseInt(attr('id'), 10);
		if (element && id > 0) {
			opts.record = { element: element, id: id, label: attr('label') };
		}
		opts.page = attr('page');
		opts.title = attr('title');
		opts.description = attr('quote');
		opts.users = attr('users').split(',').map(Number).filter(function (n) { return n > 0; });
		var project = parseInt(attr('project'), 10);
		if (project > 0) {
			opts.project = { id: project };
		}
		return opts;
	}

	/* target is what was clicked, or null when another module opens the popup
	 * through window.HeadsupOpen(opts), which can fill it in (see there). */
	function openPopup(target, x, y, opts) {
		var c = cfg();
		if (!c || !c.canSend) {
			return;
		}
		closePopup('reopen');
		opts = opts || {};
		if (target) {
			opts = fromPage(target);
		}
		askNotifyPermission();
		/* A to-do about something outside Dolibarr (a phone call...): no record, no page */
		var standalone = !target && !!opts.standalone;

		var context = {
			page: standalone ? '' : (opts.page || (window.location.pathname + window.location.search)),
			box: target ? boxTitle(target) : (opts.box || ''),
			clicked: target ? clickedText(target) : ''
		};
		var record = standalone ? null : (opts.record || c.record);
		var clickedRecord = null;
		/* A record the page declared for what was clicked wins over links found nearby */
		var links = (target && !opts.record) ? nearbyLinks(target) : [];
		var quick = (opts.users && opts.users.length) ? opts.users.map(Number) : null;

		var kind = 'todo';
		var selected = {};

		var tabTodo = el('button', { type: 'button', className: 'headsup-tab headsup-tab-on', text: 'To-do' });
		var tabProblem = el('button', { type: 'button', className: 'headsup-tab', text: 'Report a problem' });

		var filter = el('input', { type: 'text', className: 'headsup-filter', placeholder: 'Search people…' });
		var userList = el('div', { className: 'headsup-users', text: 'Loading…' });
		var chosen = el('div', { className: 'headsup-chosen' });
		var title = el('input', { type: 'text', className: 'headsup-title', maxlength: '255', placeholder: 'Title' });
		var desc = el('textarea', { className: 'headsup-desc', rows: '4', placeholder: 'What should they look at? (optional)' });
		if (opts.title) {
			title.value = String(opts.title);
		}
		if (opts.description) {
			desc.value = String(opts.description);
		}
		/* Project: the opener's (opts.project), else automatic. The field shows only when setup turns it on. */
		var projectWanted = (opts.project && parseInt(opts.project.id, 10) > 0) ? parseInt(opts.project.id, 10) : 0;
		/* null: automatic; {id, name}: a project; {id: -1}: no project */
		var projectChosen = projectWanted ? { id: projectWanted, name: (opts.project.label ? String(opts.project.label) : 'Project #' + projectWanted) } : null;
		var projectBox = null;
		var projectChip = null;
		var projectFilter = null;
		var projectList = null;
		if (c.projectField) {
			projectChip = el('div', { className: 'headsup-chosen' });
			projectFilter = el('input', { type: 'search', className: 'headsup-filter headsup-project-filter', placeholder: 'Automatic · search to change', 'aria-label': 'Project' });
			projectList = el('div', { className: 'headsup-users' });
			projectBox = el('div', { className: 'headsup-project' }, [projectChip, projectFilter, projectList]);
		}
		var send = el('button', { type: 'submit', className: 'button headsup-send', text: 'Send' });
		var cancel = el('button', { type: 'button', className: 'button button-cancel headsup-cancel', text: 'Cancel' });

		var where = [];
		if (record && record.label) {
			where.push('On: ' + record.label);
		}
		if (context.box) {
			where.push('Box: ' + context.box);
		}
		if (!where.length && context.clicked) {
			where.push('Clicked: ' + context.clicked);
		}
		var whereLine = el('div', { className: 'headsup-where', text: standalone ? 'Not linked to a record' : (where.join(' · ') || document.title) });
		var linkedLine = el('div', { className: 'headsup-linked' });
		var close = el('button', { type: 'button', className: 'headsup-close', 'aria-label': 'Close', title: 'Close (Esc)', text: '×' });
		var head = el('div', { className: 'headsup-head' }, [
			el('span', { className: 'headsup-head-title', text: standalone ? 'New to-do' : 'Heads-up' }),
			close
		]);

		var form = el('form', { className: 'headsup-form' }, [
			head,
			el('div', { className: 'headsup-tabs' }, [tabTodo, tabProblem]),
			whereLine,
			linkedLine,
			el('label', { className: 'headsup-label', text: 'Assign to' }),
			chosen,
			filter,
			userList,
			el('label', { className: 'headsup-label', text: 'Title' }),
			title,
			el('label', { className: 'headsup-label', text: 'Description' }),
			desc,
			projectBox ? el('label', { className: 'headsup-label', text: 'Project' }) : null,
			projectBox,
			el('div', { className: 'headsup-actions' }, [cancel, send])
		]);

		popup = el('div', { className: 'headsup-popup', role: 'dialog', 'aria-label': 'Heads-up' }, [form]);
		document.body.appendChild(popup);
		if (isSmallScreen()) {
			document.documentElement.classList.add('headsup-popup-open');
			if (!historyPushed && window.history && window.history.pushState) {
				window.history.pushState({ headsupPopup: 1 }, '');
				historyPushed = true;
			}
		}

		/* Place it next to the click, kept on screen (again whenever it grows).
		 * On a phone the stylesheet makes it fill the screen instead. */
		function place() {
			if (!popup) {
				return;
			}
			if (isSmallScreen()) {
				popup.style.left = '';
				popup.style.top = '';
				return;
			}
			var w = popup.offsetWidth;
			var h = popup.offsetHeight;
			popup.style.left = Math.max(8, Math.min(x + 12, window.innerWidth - w - 8)) + 'px';
			popup.style.top = Math.max(8, Math.min(y + 12, window.innerHeight - h - 8)) + 'px';
		}
		place();

		function renderChosen() {
			chosen.textContent = '';
			Object.keys(selected).forEach(function (id) {
				var chip = el('span', { className: 'headsup-chip', text: selected[id] });
				var x = el('button', { type: 'button', className: 'headsup-chip-x', 'aria-label': 'Remove', text: '×' });
				x.addEventListener('click', function () {
					delete selected[id];
					renderChosen();
					renderUsers();
				});
				chip.appendChild(x);
				chosen.appendChild(chip);
			});
		}

		function renderUsers() {
			if (!usersCache) {
				return;
			}
			var q = filter.value.toLowerCase().trim();
			userList.textContent = '';
			var pool;
			if (q) {
				/* Typing searches everyone */
				pool = usersCache.filter(function (u) {
					return u.name.toLowerCase().indexOf(q) >= 0 || u.login.toLowerCase().indexOf(q) >= 0;
				}).slice(0, 8);
			} else if (quick) {
				/* The opener's suggestions (e.g. the people who can see a conversation) */
				pool = quick.map(function (id) {
					return usersCache.filter(function (u) { return u.id === id; })[0];
				}).filter(Boolean);
			} else if (usersCache.showAll) {
				pool = usersCache.slice(0, 40);
			} else {
				/* Only the admin's short list, in the order it was saved */
				pool = usersCache.shortlist.map(function (id) {
					return usersCache.filter(function (u) { return u.id === id; })[0];
				}).filter(Boolean);
			}
			pool
				.filter(function (u) { return !selected[u.id]; })
				.forEach(function (u) {
					var b = el('button', { type: 'button', className: 'headsup-user', text: u.name + (u.id === c.me ? ' (me)' : '') });
					b.addEventListener('click', function () {
						selected[u.id] = u.name;
						filter.value = '';
						renderChosen();
						renderUsers();
						filter.focus();
					});
					userList.appendChild(b);
				});
			if (!userList.firstChild && q) {
				userList.appendChild(el('span', { className: 'opacitymedium', text: 'No match' }));
			}
			place();
		}

		function selectUser(id) {
			var u = (usersCache || []).filter(function (x) { return x.id === id; })[0];
			if (u) {
				selected[u.id] = u.name;
			}
		}

		function setKind(k) {
			kind = k;
			tabTodo.classList.toggle('headsup-tab-on', k === 'todo');
			tabProblem.classList.toggle('headsup-tab-on', k === 'problem');
			if (k === 'problem') {
				if (!Object.keys(selected).length && c.problemUser) {
					selectUser(c.problemUser);
				}
				if (!title.value && !standalone) {
					title.value = 'Problem: ' + (context.box || context.clicked || document.title);
				}
				desc.placeholder = "What's wrong? What did you expect to see?";
			} else {
				desc.placeholder = 'What should they look at? (optional)';
			}
			renderChosen();
			renderUsers();
		}

		tabTodo.addEventListener('click', function () { setKind('todo'); });
		tabProblem.addEventListener('click', function () { setKind('problem'); });
		filter.addEventListener('input', renderUsers);
		filter.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				var first = userList.querySelector('.headsup-user');
				if (first) {
					first.click();
				}
			}
		});
		cancel.addEventListener('click', function () { closePopup(); });
		close.addEventListener('click', function () { closePopup(); });

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var ids = Object.keys(selected);
			if (!ids.length) {
				toast('Choose at least one person', true);
				filter.focus();
				return;
			}
			if (!title.value.trim()) {
				toast('Add a title', true);
				title.focus();
				return;
			}
			post(false);
		});

		/* confirmed: the sender accepted the warnings another module raised (see ajax/flag.php) */
		function post(confirmed) {
			var ids = Object.keys(selected);
			var body = new URLSearchParams();
			body.append('token', c.token);
			if (confirmed) {
				body.append('confirm', '1');
			}
			body.append('kind', kind);
			ids.forEach(function (id) { body.append('users[]', id); });
			body.append('title', title.value.trim());
			body.append('description', desc.value);
			body.append('page', context.page);
			body.append('box', context.box);
			body.append('clicked', context.clicked);
			if (record) {
				body.append('element', record.element);
				body.append('elementid', String(record.id));
			}
			if (clickedRecord) {
				body.append('clickedelement', clickedRecord.element);
				body.append('clickedid', String(clickedRecord.id));
			}
			if (projectChosen) {
				body.append('project', String(projectChosen.id));
			}
			send.disabled = true;
			fetch(c.flagUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json().catch(function () { return { ok: 0, error: 'Server error ' + r.status }; }); })
				.then(function (data) {
					if (data && data.ok) {
						closePopup();
						toast(kind === 'problem' ? 'Problem reported' : 'Sent');
						if (typeof data.count === 'number') {
							setCount(data.count);
						}
						if (typeof opts.onSent === 'function') {
							opts.onSent(data);
						}
						/* Lets the page refresh whatever shows this record's flags */
						document.dispatchEvent(new CustomEvent('headsup:sent', { detail: { id: data.id, record: record } }));
					} else if (data && data.confirm && !confirmed) {
						if (window.confirm(data.confirm + '\n\nSend anyway?')) {
							post(true);
						} else {
							send.disabled = false;
						}
					} else {
						send.disabled = false;
						toast((data && data.error) || 'Could not send', true);
					}
				})
				.catch(function () {
					send.disabled = false;
					toast('Could not send', true);
				});
		}

		document.addEventListener('keydown', onPopupKey, true);

		if (links.length && c.resolveUrl) {
			var q = new URLSearchParams();
			links.forEach(function (h) { q.append('links[]', h); });
			fetch(c.resolveUrl + '?' + q.toString(), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					var r = data && data.record;
					if (!r || !popup) {
						return;
					}
					/* Same record as the page: nothing new to say */
					if (record && record.element === r.element && record.id === r.id) {
						return;
					}
					clickedRecord = r;
					linkedLine.textContent = 'Links to: ' + r.label;
					if (kind === 'problem' && title.value.indexOf('Problem: ') === 0 && !context.box) {
						title.value = 'Problem: ' + r.label;
					}
					place();
				})
				.catch(function () {});
		}

		if (projectBox) {
			/* The chosen project as a chip (× goes back to automatic), or the search box */
			var renderProject = function () {
				projectChip.textContent = '';
				projectList.textContent = '';
				projectFilter.value = '';
				projectFilter.hidden = !!projectChosen;
				if (projectChosen) {
					var chip = el('span', { className: 'headsup-chip', text: projectChosen.id < 0 ? 'No project' : projectChosen.name });
					var x = el('button', { type: 'button', className: 'headsup-chip-x', 'aria-label': 'Back to automatic', text: '×' });
					x.addEventListener('click', function () {
						projectChosen = null;
						renderProject();
						projectFilter.focus();
					});
					chip.appendChild(x);
					projectChip.appendChild(chip);
				}
				place();
			};
			var chooseProject = function (choice) {
				projectChosen = choice;
				renderProject();
			};
			var projectTimer = 0;
			var projectSeq = 0;
			projectFilter.addEventListener('input', function () {
				clearTimeout(projectTimer);
				var q = projectFilter.value.trim();
				if (!q) {
					projectList.textContent = '';
					place();
					return;
				}
				projectTimer = setTimeout(function () {
					var seq = ++projectSeq;
					findProjects(q, 0).then(function (list) {
						if (!popup || seq !== projectSeq) {
							return;
						}
						projectList.textContent = '';
						list.forEach(function (p) {
							var b = el('button', { type: 'button', className: 'headsup-user', text: projectName(p) });
							b.addEventListener('click', function () { chooseProject({ id: p.id, name: projectName(p) }); });
							projectList.appendChild(b);
						});
						if (!list.length) {
							projectList.appendChild(el('span', { className: 'opacitymedium', text: 'No match' }));
						}
						var none = el('button', { type: 'button', className: 'headsup-user headsup-project-none', text: 'No project' });
						none.addEventListener('click', function () { chooseProject({ id: -1 }); });
						projectList.appendChild(none);
						place();
					}).catch(function () {});
				}, 250);
			});
			projectFilter.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					var first = projectList.querySelector('.headsup-user');
					if (first) {
						first.click();
					}
				}
			});
			renderProject();
			/* A project passed in by id: show its name */
			if (projectWanted && !(opts.project && opts.project.label)) {
				findProjects('', projectWanted).then(function (list) {
					if (popup && list.length && projectChosen && projectChosen.id === projectWanted) {
						projectChosen.name = projectName(list[0]);
						renderProject();
					}
				}).catch(function () {});
			}
		}

		loadUsers().then(function () {
			renderUsers();
			filter.focus();
		}).catch(function () {
			userList.textContent = 'Could not load users';
		});
	}

	/* ---------- Pick mode ---------- */

	function setPicking(on) {
		picking = on;
		document.documentElement.classList.toggle('headsup-picking', on);
		var banner = document.querySelector('.headsup-banner');
		if (on && !banner) {
			/* A Cancel button too: phones and tablets have no Esc key */
			var touch = window.matchMedia && window.matchMedia('(hover: none)').matches;
			document.body.appendChild(el('div', { className: 'headsup-banner' }, [
				el('span', { text: touch ? 'Tap what you want to flag' : 'Click what you want to flag · Esc to cancel' }),
				el('button', { type: 'button', className: 'headsup-banner-cancel', text: 'Cancel' })
			]));
		} else if (!on && banner) {
			banner.remove();
		}
	}

	/* Capture phase, so we act before links, buttons and Dolibarr's own handlers. */
	document.addEventListener('click', function (e) {
		var c = cfg();
		if (!c || !c.canSend) {
			return;
		}
		var target = e.target;
		if (!(target instanceof Element)) {
			return;
		}
		if (target.closest('.headsup-popup')) {
			return;
		}
		var menu = document.querySelector('.headsup-menu');
		if (target.closest('.headsup-banner-cancel')) {
			e.preventDefault();
			e.stopPropagation();
			setPicking(false);
			return;
		}
		/* New to-do (top bar menu, My queue button): a blank popup, linked to nothing */
		if (!picking && target.closest('.headsup-new, #headsup-new')) {
			e.preventDefault();
			e.stopPropagation();
			if (menu) {
				menu.hidden = true;
			}
			openNew();
			return;
		}
		if (target.closest('.headsup-pick')) {
			e.preventDefault();
			e.stopPropagation();
			if (menu) {
				menu.hidden = true;
			}
			setPicking(!picking);
			return;
		}
		/* The flag icon opens its menu (a plain click; Alt+click still flags the icon itself) */
		var altPressed = e.altKey && !e.ctrlKey && !e.metaKey && !e.shiftKey;
		if (!altPressed && !picking && target.closest('.headsup-badge') && menu) {
			e.preventDefault();
			e.stopPropagation();
			menu.hidden = !menu.hidden;
			askNotifyPermission();
			target.closest('.headsup-badge').setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
			return;
		}
		if (menu && !menu.hidden && !target.closest('.headsup-menu')) {
			menu.hidden = true;
		}
		var altOnly = e.altKey && !e.ctrlKey && !e.metaKey && !e.shiftKey;
		if (!picking && !altOnly) {
			if (popup && !target.closest('.headsup-popup')) {
				closePopup();
			}
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		e.stopImmediatePropagation();
		setPicking(false);
		openPopup(target, e.clientX, e.clientY);
	}, true);

	/* Alt+click on a link would otherwise download it in some browsers. */
	document.addEventListener('auxclick', function (e) {
		if (picking) {
			e.preventDefault();
		}
	}, true);

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && picking) {
			setPicking(false);
		}
		var menu = document.querySelector('.headsup-menu');
		if (e.key === 'Escape' && menu && !menu.hidden) {
			menu.hidden = true;
		}
	});

	/* ---------- Top bar count ---------- */

	function notifyAllowed() {
		return ('Notification' in window) && Notification.permission === 'granted';
	}

	/* Asked when the user opens the menu or the popup, never on page load
	 * (browsers ignore or penalise unprompted requests). */
	function askNotifyPermission() {
		if (('Notification' in window) && Notification.permission === 'default') {
			try {
				Notification.requestPermission();
			} catch (e) { /* old Safari: callback form only; skip */ }
		}
	}

	/* Show the newest open to-do once, across all tabs: the highest id already
	 * shown is kept in localStorage. The very first check only records it, so
	 * an old to-do never pops up in a new browser. */
	function maybeNotify(latest) {
		var c = cfg();
		if (!latest || !latest.id || !c) {
			return;
		}
		var key = 'headsup-seen-' + c.me;
		var seen;
		try {
			seen = window.localStorage.getItem(key);
			if (seen !== null && Number(seen) >= latest.id) {
				return;
			}
			window.localStorage.setItem(key, String(latest.id));
		} catch (e) {
			return;
		}
		if (seen === null || !notifyAllowed()) {
			return;
		}
		try {
			var n = new Notification('Heads-up' + (latest.from ? ' from ' + latest.from : ''), {
				body: latest.label || '',
				tag: 'headsup-' + latest.id
			});
			n.onclick = function () {
				window.focus();
				if (c.queuePageUrl) {
					window.location.href = c.queuePageUrl;
				}
				n.close();
			};
		} catch (e) { /* notifications unavailable here */ }
	}

	/* The same for the newest comment someone else wrote on one of my to-dos;
	 * clicking it opens that conversation. */
	function maybeNotifyComment(latest) {
		var c = cfg();
		if (!latest || !latest.id || !c) {
			return;
		}
		var key = 'headsup-seen-comment-' + c.me;
		var seen;
		try {
			seen = window.localStorage.getItem(key);
			if (seen !== null && Number(seen) >= latest.id) {
				return;
			}
			window.localStorage.setItem(key, String(latest.id));
		} catch (e) {
			return;
		}
		if (seen === null || !notifyAllowed()) {
			return;
		}
		try {
			var n = new Notification('Heads-up: ' + (latest.from ? latest.from + ' commented' : 'new comment'), {
				body: latest.label || '',
				tag: 'headsup-comment-' + latest.id
			});
			n.onclick = function () {
				window.focus();
				if (c.commentsPageUrl && latest.todo) {
					window.location.href = c.commentsPageUrl + '?id=' + encodeURIComponent(latest.todo);
				}
				n.close();
			};
		} catch (e) { /* notifications unavailable here */ }
	}

	function refreshCount() {
		var c = cfg();
		/* Background tabs keep checking only when they can show a notification */
		if (!c || (document.hidden && !notifyAllowed())) {
			return;
		}
		fetch(c.queueUrl, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data && typeof data.count === 'number') {
					setCount(data.count);
				}
				if (data) {
					maybeNotify(data.latest);
					maybeNotifyComment(data.latestComment);
				}
			})
			.catch(function () {});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var c = cfg();
		if (c && c.refresh) {
			setInterval(refreshCount, c.refresh * 1000);
			setTimeout(refreshCount, 3000);
		}
	});

	/* Opens the popup from another module, already filled in. Every field is optional:
	 *   x, y         where to show it (viewport pixels; default: near the top right)
	 *   record       {element, id, label}: what it is about, e.g. 'myobject@mymodule'
	 *   page         link back, a path inside Dolibarr (default: this page)
	 *   box          where on the page, for the note
	 *   title        title
	 *   description  description
	 *   users        user ids offered as quick choices instead of the short list
	 *   project      {id, label}: the project the to-do belongs to (preselected in the Project field)
	 *   standalone   true: about nothing on this page (no record, no page link), like the New to-do button
	 *   onSent       function (reply) called once it is saved
	 */
	window.HeadsupOpen = function (opts) {
		opts = opts || {};
		var x = typeof opts.x === 'number' ? opts.x : window.innerWidth - 400;
		var y = typeof opts.y === 'number' ? opts.y : 60;
		openPopup(null, x, y, opts);
	};

	/* A to-do that is not about anything on the page, near the top centre */
	function openNew() {
		openPopup(null, Math.max(8, window.innerWidth / 2 - 182), 60, { standalone: true });
	}

	/* My queue opened with ?newtodo=1 (left menu, a home screen bookmark): open it straight away */
	document.addEventListener('DOMContentLoaded', function () {
		var c = cfg();
		if (!c || !c.canSend || !document.getElementById('headsup-new')) {
			return;
		}
		var params = new URLSearchParams(window.location.search);
		if (params.get('newtodo') !== '1') {
			return;
		}
		/* Drop it from the address, so reloading or going back does not open it again */
		params.delete('newtodo');
		var rest = params.toString();
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', window.location.pathname + (rest ? '?' + rest : '') + window.location.hash);
		}
		openNew();
	});

	/* Used by the queue page after "Mark done". */
	window.HeadsupSetCount = setCount;

	/* My queue: the status picker on each row saves as soon as it changes */
	document.addEventListener('change', function (e) {
		var sel = e.target;
		var c = cfg();
		if (!c || !sel.classList || !sel.classList.contains('headsup-status-select')) {
			return;
		}
		var before = sel.getAttribute('data-status');
		var body = new URLSearchParams();
		body.append('token', c.token);
		body.append('action', 'status');
		body.append('status', sel.value);
		body.append('id', sel.getAttribute('data-id'));
		sel.disabled = true;
		fetch(c.queueUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				sel.disabled = false;
				if (!d || !d.ok) {
					sel.value = before;
					alert((d && d.error) || 'Could not save it');
					return;
				}
				sel.setAttribute('data-status', d.status);
				var row = sel.closest('tr');
				if (row) {
					row.classList.toggle('headsup-done-row', d.status === 'done');
					var slider = row.querySelector('input.headsup-progress');
					if (slider && typeof d.percent === 'number') {
						showProgress(slider, d.percent);
						slider.setAttribute('data-percent', String(d.percent));
					}
				}
				if (typeof d.count === 'number') {
					setCount(d.count);
				}
			})
			.catch(function () {
				sel.disabled = false;
				sel.value = before;
			});
	});

	/* Progress slider (My queue rows, event card): the state follows the percent */
	function progressState(percent) {
		if (percent >= 100) {
			return 'done';
		}
		if (percent <= 0) {
			return 'todo';
		}
		return percent === 10 ? 'ack' : 'progress';
	}

	function progressPoints(slider) {
		return (slider.getAttribute('data-points') || '0,100').split(',').map(function (p) { return parseInt(p, 10) || 0; });
	}

	/* Percent of the stop the slider is on */
	function sliderPercent(slider) {
		var points = progressPoints(slider);
		var i = Math.max(0, Math.min(points.length - 1, parseInt(slider.value, 10) || 0));
		return points[i];
	}

	/* Put the slider on the stop nearest to percent and show the state it means */
	function showProgress(slider, percent) {
		var c = cfg();
		var points = progressPoints(slider);
		percent = Math.max(0, Math.min(100, percent));
		var stop = 0;
		points.forEach(function (p, i) {
			if (Math.abs(p - percent) < Math.abs(points[stop] - percent)) {
				stop = i;
			}
		});
		slider.value = String(stop);
		var state = progressState(percent);
		var name = (c && c.statusLabels && c.statusLabels[state]) || state;
		var text = name + ' \u00b7 ' + percent + '%';
		slider.setAttribute('aria-valuetext', text);
		var wrap = slider.closest('.headsup-progress-wrap');
		var label = wrap ? wrap.querySelector('.headsup-progress-label') : null;
		if (label) {
			label.textContent = text;
		}
		return state;
	}

	document.addEventListener('input', function (e) {
		var slider = e.target;
		if (slider && slider.classList && slider.classList.contains('headsup-progress')) {
			showProgress(slider, sliderPercent(slider));
		}
	});

	/* Save the slider's stop. The slider stays enabled (disabling it would drop
	 * keyboard focus); a move made while a save is running is saved right after. */
	function saveProgress(slider) {
		var c = cfg();
		if (!c) {
			return;
		}
		if (slider.headsupBusy) {
			slider.headsupPending = true;
			return;
		}
		var before = parseInt(slider.getAttribute('data-percent'), 10) || 0;
		var percent = sliderPercent(slider);
		if (percent === before) {
			return;
		}
		var body = new URLSearchParams();
		body.append('token', c.token);
		body.append('action', 'progress');
		body.append('percent', String(percent));
		body.append('id', slider.getAttribute('data-id'));
		slider.headsupBusy = true;
		slider.setAttribute('aria-busy', 'true');
		var done = function () {
			slider.headsupBusy = false;
			slider.removeAttribute('aria-busy');
			if (slider.headsupPending) {
				slider.headsupPending = false;
				saveProgress(slider);
			}
		};
		fetch(c.queueUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) {
					slider.headsupPending = false;
					showProgress(slider, before);
					done();
					alert((d && d.error) || 'Could not save it');
					return;
				}
				if (slider.getAttribute('data-reload') === '1' && !slider.headsupPending) {
					window.location.reload();
					return;
				}
				var saved = typeof d.percent === 'number' ? d.percent : percent;
				slider.setAttribute('data-percent', String(saved));
				var state = progressState(saved);
				if (!slider.headsupPending) {
					showProgress(slider, saved);
				}
				var row = slider.closest('tr');
				if (row) {
					row.classList.toggle('headsup-done-row', state === 'done');
					var sel = row.querySelector('select.headsup-status-select');
					if (sel) {
						sel.value = state;
						sel.setAttribute('data-status', state);
					}
				}
				if (typeof d.count === 'number') {
					setCount(d.count);
				}
				done();
			})
			.catch(function () {
				slider.headsupPending = false;
				showProgress(slider, before);
				done();
			});
	}

	/* Each arrow key press is a change: wait for a short pause so a run of
	 * presses is one save (and one note line), not one per stop. */
	document.addEventListener('change', function (e) {
		var slider = e.target;
		if (!slider.classList || !slider.classList.contains('headsup-progress')) {
			return;
		}
		clearTimeout(slider.headsupTimer);
		slider.headsupTimer = setTimeout(function () {
			saveProgress(slider);
		}, 600);
	});

	/* Layout check: add headsup_overflow=1 to any page's address to outline
	 * whatever sticks out past the right edge of the screen (the thing that
	 * makes a phone page scroll sideways or zoom out), with a list on top. */
	function describe(node) {
		var name = node.tagName.toLowerCase();
		if (node.id) {
			name += '#' + node.id;
		}
		if (typeof node.className === 'string' && node.className.trim()) {
			name += '.' + node.className.trim().split(/\s+/).slice(0, 4).join('.');
		}
		return name;
	}

	function clipped(node, vw) {
		for (var a = node.parentElement; a && a !== document.body; a = a.parentElement) {
			var ox = getComputedStyle(a).overflowX;
			if (ox !== 'visible' && a.getBoundingClientRect().right <= vw + 1) {
				return true;
			}
		}
		return false;
	}

	function overflowReport() {
		var vw = document.documentElement.clientWidth;
		var lines = [];
		var mine = document.querySelector('table.headsup-mine');
		lines.push('Screen ' + vw + 'px, page ' + document.documentElement.scrollWidth + 'px wide');
		lines.push('queue.php 0.3.9+: ' + (document.querySelector('#headsup-events') ? (mine ? 'yes' : 'NO (old file)') : 'not this page'));
		if (mine && vw <= 1000) {
			var row = mine.querySelector('tr');
			lines.push('headsup.css 0.3.9+: ' + (row && getComputedStyle(row).display === 'flex' ? 'yes' : 'NO (old or cached)'));
		}
		var found = 0;
		Array.prototype.forEach.call(document.body.querySelectorAll('*'), function (node) {
			if (found >= 12 || node.closest('.headsup-overflow-panel')) {
				return;
			}
			var r = node.getBoundingClientRect();
			if (r.width === 0 || r.right <= vw + 1) {
				return;
			}
			var parent = node.parentElement;
			var pos = getComputedStyle(node).position;
			// Report where it starts: the parent still fits, or it is pinned to the screen
			if (pos !== 'fixed' && parent && parent !== document.body && parent.getBoundingClientRect().right > vw + 1) {
				return;
			}
			if (clipped(node, vw)) {
				return;
			}
			found++;
			node.style.outline = '3px solid #e00';
			node.style.outlineOffset = '-3px';
			lines.push(describe(node) + ' — right edge ' + Math.round(r.right) + 'px, ' + Math.round(r.width) + 'px wide' + (pos === 'fixed' ? ', fixed' : ''));
		});
		if (!found) {
			lines.push('Nothing sticks out past the screen edge.');
		}
		var panel = el('div', {className: 'headsup-overflow-panel'});
		panel.style.cssText = 'position:fixed;top:0;left:0;width:' + vw + 'px;box-sizing:border-box;z-index:100010;max-height:45vh;overflow:auto;padding:8px 10px;background:#fffbe6;color:#222;border-bottom:2px solid #e00;font:12px/1.4 monospace;white-space:pre-wrap;word-break:break-all;';
		panel.textContent = lines.join('\n') + '\n(tap to close)';
		panel.addEventListener('click', function () {
			panel.remove();
		});
		document.body.appendChild(panel);
		if (window.console) {
			console.log('[headsup overflow]\n' + lines.join('\n'));
		}
	}

	if (/[?&]headsup_overflow=1(&|$)/.test(window.location.search)) {
		window.addEventListener('load', function () {
			setTimeout(overflowReport, 300);
		});
	}
})();
