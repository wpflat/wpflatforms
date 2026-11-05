/* WPFlat Forms public page: draws the form from its definition, repeats the company sections, saves as people type. */
(function () {
	'use strict';
	var C = window.WPFLATFORMS, D = C.def, G = D.group || {}, MAX = G.max || 0;
	var root = document.getElementById('bf-app');
	var LS = 'wpflatforms:' + C.slug;
	var S = { count: 0, general: {}, entities: [{}], tab: 0 };
	var FILES = (C.files || []).slice(), GO = LS + ':go';
	var consentGiven = false, lastPdf = C.pdf || '';
	var token = C.token || '', timer = null, saving = false, dirty = false, status = C.state === 'submitted' ? 'submitted' : 'new', lastSaved = '', tabButtons = [];

	function el(tag, attrs, kids) {
		var e = document.createElement(tag), k;
		for (k in (attrs || {})) {
			if (k === 'text') e.textContent = attrs[k];
			else if (k === 'class') e.className = attrs[k];
			else if (k === 'on') { for (var ev in attrs.on) e.addEventListener(ev, attrs.on[ev]); }
			else if (attrs[k] !== null && attrs[k] !== false) e.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
		}
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function each(list, fn) { (list || []).forEach(fn); }
	function sections() { return D.sections || []; }
	function fieldsOf(sec) { return sec.fields || []; }

	/* ── state ── */
	function normalise() {
		S.count = Math.max(0, Math.min(MAX, parseInt(S.count, 10) || 0));
		while (S.entities.length < S.count + 1) S.entities.push({});
		S.entities.length = S.count + 1;
		each(sections(), function (sec) {
			each(fieldsOf(sec), function (f) {
				if (f.type !== 'companies') return;
				var rows = S.general[f.id] = Array.isArray(S.general[f.id]) ? S.general[f.id] : [];
				while (rows.length < S.count + 1) rows.push({});
				rows.length = S.count + 1;
				each(rows, function (r, i) {
					each(f.columns, function (c) {
						if (r[c.id] == null) r[c.id] = (i === 0 && f.parent_defaults && f.parent_defaults[c.id]) || '';
					});
				});
			});
		});
		each(S.entities, function (e, i) { Object.defineProperty(e, '__i', { value: i, enumerable: false, configurable: true }); });
		if (S.tab > S.count) S.tab = 0;
	}
	Object.defineProperty(S.general, '__i', { value: 0, enumerable: false, configurable: true });
	function load() {
		var d = C.data;
		if (!d) { try { d = JSON.parse(localStorage.getItem(LS) || 'null'); } catch (e) { d = null; } }
		if (d && typeof d === 'object') {
			S.count = d.count || 0;
			S.general = d.general || {};
			Object.defineProperty(S.general, '__i', { value: 0, enumerable: false, configurable: true });
			S.entities = Array.isArray(d.entities) && d.entities.length ? d.entities : [{}];
		}
		normalise();
	}
	function payload() { return { count: S.count, general: S.general, entities: S.entities }; }
	function entityLabel(i) {
		var t = G.table, r = t && S.general[t] && S.general[t][i];
		var n = r && r.name ? String(r.name).trim() : '';
		return n || (i === 0 ? (G.parent || 'Main company') : (G.sub || 'Subsidiary') + ' ' + i);
	}
	function answered(i) {
		var n = 0, e = S.entities[i] || {};
		for (var k in e) { var v = e[k]; if (Array.isArray(v) ? v.length : String(v || '').trim() !== '') n++; }
		each(sections(), function (sec) { if (sec.repeat) each(fieldsOf(sec), function (f) { if (f.type === 'files' && FILES.some(function (x) { return x.field === f.id && x.entity === i; })) n++; }); });
		return n;
	}
	function totalQuestions() {
		var n = 0;
		each(sections(), function (sec) { if (sec.repeat) each(fieldsOf(sec), function (f) { if (f.id) n++; }); });
		return n;
	}

	/* ── saving ── */
	function setStatus(txt, ok) {
		var s = document.getElementById('bf-status');
		if (s) { s.textContent = txt; s.className = ok ? 'bf-ok' : ''; }
	}
	function localSave() { try { localStorage.setItem(LS, JSON.stringify(payload())); } catch (e) {} }
	function validEmail() { var m = S.general.email; return typeof m === 'string' && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(m); }
	function changed() {
		dirty = true; localSave();
		if (status === 'submitted') setStatus('Changes not sent yet. Press "Send my changes".', false);
		else setStatus(token || validEmail() ? 'Saving…' : 'Saved on this device. Press "Save and get my link" to save online.', false);
		clearTimeout(timer);
		if ((token || validEmail()) && status !== 'submitted') timer = setTimeout(function () { send(false); }, 1500);
	}
	function post(submit) {
		var hp = document.getElementById('bf-hp');
		return fetch(C.save, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: token, data: payload(), submit: submit, hp: hp ? hp.value : '', consent: consentGiven || !!C.consented }) })
			.then(function (r) { return r.json().then(function (j) { if (!r.ok) { var e = new Error(j.message || 'Could not save.'); e.data = j; throw e; } return j; }); });
	}
	function send(submit) {
		if (saving) { clearTimeout(timer); timer = setTimeout(function () { send(submit); }, 800); return Promise.resolve(); }
		saving = true; dirty = false;
		return post(submit).then(function (j) {
			saving = false;
			token = j.token;
			if (history.replaceState) { try { history.replaceState(null, '', C.url + '?bd_resume=' + token); } catch (e) {} }
			lastSaved = j.resume_url; lastPdf = j.pdf_url || lastPdf;
			if (submit) { status = 'submitted'; showThanks(j); }
			else { setStatus('Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }), true); showLinkBox(); }
			return j;
		}).catch(function (e) {
			saving = false; dirty = true;
			setStatus('Not saved yet: ' + e.message + ' (kept on this device)', false);
			throw e;
		});
	}

	/* ── private link ── */
	function resumeUrl() { return lastSaved || (token ? C.url + '?bd_resume=' + token : ''); }
	function copyText(u, done) {
		(navigator.clipboard ? navigator.clipboard.writeText(u) : Promise.reject()).then(function () { done(true); }, function () { window.prompt('Copy your private link:', u); done(false); });
	}
	function showLinkBox() {
		var box = document.getElementById('bf-linkbox');
		if (!box || !token) return;
		box.hidden = false; box.innerHTML = '';
		var btn = el('button', { type: 'button', class: 'bf-btn bf-ghost', text: 'Copy link' });
		btn.addEventListener('click', function () { copyText(resumeUrl(), function (ok) { btn.textContent = ok ? 'Copied ✓' : 'Copy link'; setTimeout(function () { btn.textContent = 'Copy link'; }, 2000); }); });
		box.appendChild(el('b', { text: 'Your private link' }));
		box.appendChild(el('p', { class: 'bf-help', text: 'Keep this link. Opening it later brings back everything you have filled in so far. Anyone with the link can open your answers.' }));
		box.appendChild(el('div', { class: 'bf-link', text: resumeUrl() }));
		box.appendChild(btn);
		if (lastPdf) box.appendChild(el('a', { class: 'bf-pdf', href: lastPdf, target: '_blank', rel: 'noopener', text: 'View a PDF of my answers so far' }));
	}
	function saveAndLink() {
		var b = document.getElementById('bf-savelink');
		if (b) { b.disabled = true; b.textContent = 'Saving…'; }
		send(false).then(function () {
			if (b) { b.disabled = false; b.textContent = 'Save and get my link'; }
			var box = document.getElementById('bf-linkbox'); if (box) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}).catch(function () { if (b) { b.disabled = false; b.textContent = 'Save and get my link'; } });
	}

	/* ── files ── */
	function fmtSize(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
	function filesNode(f, entity) {
		var wrap = el('div', { class: 'bf-field' });
		wrap.appendChild(el('span', { class: 'bf-label', text: f.label }));
		var drop = el('div', { class: 'bf-drop', tabindex: '0', role: 'button' }, [el('b', { text: 'Drop files here' }), el('span', { text: ' or click to choose. Up to ' + C.maxMb + ' MB each.' })]);
		var inp = el('input', { type: 'file', multiple: true, hidden: true });
		var list = el('ul', { class: 'bf-files' });
		var msg = el('p', { class: 'bf-help', 'aria-live': 'polite' });
		function paint() {
			list.innerHTML = '';
			each(FILES, function (fl) {
				if (fl.field !== f.id || fl.entity !== (entity.__i || 0)) return;
				var rm = el('button', { type: 'button', class: 'bf-x', 'aria-label': 'Remove ' + fl.name, text: '×' });
				rm.addEventListener('click', function () {
					fetch(C.save.replace(/save$/, 'file/' + fl.id) + '?token=' + encodeURIComponent(token), { method: 'DELETE' }).then(function () { FILES = FILES.filter(function (x) { return x.id !== fl.id; }); paint(); refreshTabs(); });
				});
				list.appendChild(el('li', {}, [el('span', { text: fl.name }), el('small', { text: ' ' + fmtSize(fl.size) }), rm]));
			});
		}
		function up(file) {
			var item = el('li', { class: 'bf-up' }, [el('span', { text: file.name }), el('small', { text: ' waiting…' })]);
			list.appendChild(item);
			var big = file.size > C.maxMb * 1048576;
			if (big) { item.lastChild.textContent = ' too large (limit ' + C.maxMb + ' MB)'; return Promise.resolve(); }
			return (token ? Promise.resolve() : send(false)).then(function () {
				return new Promise(function (resolve) {
					var x = new XMLHttpRequest(), fd = new FormData();
					fd.append('token', token); fd.append('entity', entity.__i || 0); fd.append('field', f.id); fd.append('file', file);
					x.open('POST', C.save.replace(/save$/, 'upload'));
					x.upload.onprogress = function (e) { if (e.lengthComputable) item.lastChild.textContent = ' ' + Math.round(e.loaded / e.total * 100) + '%'; };
					x.onload = function () {
						var j = {}; try { j = JSON.parse(x.responseText); } catch (e) {}
						if (x.status >= 200 && x.status < 300 && j.id) { FILES.push(j); } else { msg.textContent = (j.message || 'Upload failed.') + ' (' + file.name + ')'; }
						resolve();
					};
					x.onerror = function () { msg.textContent = 'Upload failed. Check your connection and try again. (' + file.name + ')'; resolve(); };
					x.send(fd);
				});
			}).catch(function (e) { msg.textContent = 'Could not save before uploading: ' + e.message; });
		}
		function handle(files) {
			msg.textContent = '';
			var chain = Promise.resolve();
			each(Array.prototype.slice.call(files), function (file) { chain = chain.then(function () { return up(file); }); });
			chain.then(function () { paint(); refreshTabs(); showLinkBox(); });
		}
		drop.addEventListener('click', function () { inp.click(); });
		drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } });
		inp.addEventListener('change', function () { handle(inp.files); inp.value = ''; });
		['dragenter', 'dragover'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('bf-over'); }); });
		['dragleave', 'drop'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('bf-over'); }); });
		drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) handle(e.dataTransfer.files); });
		paint();
		[drop, inp, list, msg].forEach(function (n) { wrap.appendChild(n); });
		if (f.help) wrap.appendChild(el('p', { class: 'bf-help', text: f.help }));
		return wrap;
	}

	/* ── fields ── */
	function bind(target, key, f) {
		function set(v) { target[key] = v; changed(); }
		var v = target[key];
		var labelText = (f.n ? f.n + '. ' : '') + f.label;
		var label = el('label', { class: 'bf-label' }, [labelText, f.required ? el('span', { class: 'bf-req', text: ' *' }) : null]);
		var input, wrap = el('div', { class: 'bf-field' });
		var id = 'f_' + Math.random().toString(36).slice(2, 9);
		switch (f.type) {
			case 'textarea':
				input = el('textarea', { id: id, rows: 4 }); input.value = v || '';
				input.addEventListener('input', function () { set(input.value); });
				break;
			case 'select':
				input = el('select', { id: id }, [el('option', { value: '', text: 'Choose…' })].concat((f.options || []).map(function (o) { return el('option', { value: o, text: o }); })));
				input.value = v || '';
				input.addEventListener('change', function () { set(input.value); });
				break;
			case 'radio':
				input = el('div', { class: 'bf-opts' }, (f.options || []).map(function (o) {
					var r = el('input', { type: 'radio', name: id, value: o }); r.checked = v === o;
					r.addEventListener('change', function () { set(o); });
					return el('label', {}, [r, el('span', { text: o })]);
				}));
				label.removeAttribute('for');
				break;
			case 'checkboxes':
				input = el('div', { class: 'bf-opts' }, (f.options || []).map(function (o) {
					var cb = el('input', { type: 'checkbox' }); cb.checked = Array.isArray(v) && v.indexOf(o) > -1;
					cb.addEventListener('change', function () {
						var cur = Array.isArray(target[key]) ? target[key].slice() : [];
						var at = cur.indexOf(o);
						if (cb.checked && at < 0) cur.push(o); if (!cb.checked && at > -1) cur.splice(at, 1);
						set(cur);
					});
					return el('label', {}, [cb, el('span', { text: o })]);
				}));
				break;
			default:
				input = el('input', { id: id, type: ({ email: 'email', tel: 'tel', url: 'url', number: 'number' })[f.type] || 'text', autocomplete: f.type === 'email' ? 'email' : null });
				input.value = v || '';
				input.addEventListener('input', function () { set(input.value); });
		}
		if (input.tagName !== 'DIV') label.setAttribute('for', id);
		wrap.appendChild(label); wrap.appendChild(input);
		if (f.help) wrap.appendChild(el('p', { class: 'bf-help', text: f.help }));
		wrap.setAttribute('data-field', f.id);
		return wrap;
	}

	function countField(f) {
		var wrap = el('div', { class: 'bf-field' });
		var sel = el('select', { id: 'bf-count' }, []);
		for (var i = 0; i <= MAX; i++) sel.appendChild(el('option', { value: i, text: i === 0 ? 'None' : String(i) }));
		sel.value = String(S.count);
		sel.addEventListener('change', function () {
			var n = parseInt(sel.value, 10) || 0, lost = 0;
			for (var i = n + 1; i < S.entities.length; i++) lost += answered(i);
			if (lost && !window.confirm('This removes the answers you already typed for ' + (S.count - n) + ' subsidiary(ies). Continue?')) { sel.value = String(S.count); return; }
			S.count = n; normalise(); changed(); draw();
		});
		wrap.appendChild(el('label', { class: 'bf-label', for: 'bf-count' }, [f.label, f.required ? el('span', { class: 'bf-req', text: ' *' }) : null]));
		wrap.appendChild(sel);
		if (f.help) wrap.appendChild(el('p', { class: 'bf-help', text: f.help }));
		return wrap;
	}

	function companiesField(f) {
		var wrap = el('div', { class: 'bf-field' });
		wrap.appendChild(el('span', { class: 'bf-label', text: f.label }));
		var rows = S.general[f.id];
		var head = el('tr', {}, [el('th', { text: '' })].concat(f.columns.map(function (c) { return el('th', { text: c.label + (c.required ? ' *' : '') }); })));
		var body = el('tbody', {}, rows.map(function (r, i) {
			var tds = [el('td', { text: i === 0 ? (G.parent || 'Parent') : (G.sub || 'Subsidiary') + ' ' + i })];
			each(f.columns, function (c) {
				var inp = el('input', { type: 'text', 'aria-label': c.label + ', ' + (i === 0 ? 'parent' : 'subsidiary ' + i) }); inp.value = r[c.id] || '';
				inp.addEventListener('input', function () { r[c.id] = inp.value; changed(); if (c.id === 'name') refreshTabs(); });
				tds.push(el('td', {}, [inp]));
			});
			return el('tr', {}, tds);
		}));
		wrap.appendChild(el('div', { class: 'bf-tablewrap' }, [el('table', { class: 'bf-t' }, [el('thead', {}, [head]), body])]));
		return wrap;
	}

	function fieldNode(f, target) {
		if (f.type === 'heading') return el('h3', { text: f.label });
		if (f.type === 'note') return el('p', { class: 'bf-intro', text: f.label });
		if (f.type === 'count') return countField(f);
		if (f.type === 'companies') return companiesField(f);
		if (f.type === 'files') return filesNode(f, target);
		return bind(target, f.id, f);
	}

	/* ── page ── */
	var panel, tabBar;
	function refreshTabs() {
		each(tabButtons, function (b, i) {
			b.firstChild.textContent = entityLabel(i);
			b.lastChild.textContent = answered(i) + '/' + totalQuestions();
		});
	}
	function drawPanel() {
		panel.innerHTML = '';
		each(sections(), function (sec) {
			if (!sec.repeat) return;
			panel.appendChild(el('h2', { text: sec.title }));
			if (sec.intro) panel.appendChild(el('p', { class: 'bf-intro', text: sec.intro }));
			each(fieldsOf(sec), function (f) { panel.appendChild(fieldNode(f, S.entities[S.tab])); });
		});
	}
	function selectTab(i) {
		S.tab = i; drawPanel();
		each(tabButtons, function (b, j) { b.setAttribute('aria-selected', j === i ? 'true' : 'false'); });
		var top = tabBar.getBoundingClientRect().top + window.scrollY - 70; window.scrollTo({ top: top > 0 ? top : 0 });
	}
	function draw() {
		root.innerHTML = '';
		tabButtons = [];
		var head = el('div', { class: 'bf-card' }, [el('h1', { text: D.title })]);
		each(D.intro, function (p) { head.appendChild(el('p', { class: 'bf-intro', text: p })); });
		if (status === 'submitted') head.appendChild(el('p', { class: 'bf-help', text: 'You already sent this form. You can change your answers below and press "Send my changes".' }));
		root.appendChild(head);
		root.appendChild(el('div', { id: 'bf-linkbox', class: 'bf-card bf-linkcard', hidden: true }));
		var errBox = el('div', { id: 'bf-errors', class: 'bf-err', hidden: true });
		root.appendChild(errBox);
		var companyDone = false;
		each(sections(), function (sec) {
			if (sec.repeat) {
				if (companyDone) return;
				companyDone = true;
				var card = el('section', { class: 'bf-card', id: 'bf-company' });
				tabBar = el('div', { class: 'bf-tabs', role: 'tablist' });
				for (var i = 0; i <= S.count; i++) {
					(function (i) {
						var b = el('button', { type: 'button', class: 'bf-tab', role: 'tab', 'aria-selected': i === S.tab ? 'true' : 'false' }, [el('span'), el('small')]);
						b.addEventListener('click', function () { selectTab(i); });
						tabButtons.push(b); tabBar.appendChild(b);
					})(i);
				}
				panel = el('div');
				card.appendChild(tabBar); card.appendChild(panel);
				root.appendChild(card);
				drawPanel(); refreshTabs();
				return;
			}
			var c = el('section', { class: 'bf-card' }, [el('h2', { text: sec.title })]);
			if (sec.intro) c.appendChild(el('p', { class: 'bf-intro', text: sec.intro }));
			each(fieldsOf(sec), function (f) { c.appendChild(fieldNode(f, S.general)); });
			root.appendChild(c);
		});
		root.appendChild(el('input', { id: 'bf-hp', class: 'bf-hp', type: 'text', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true', name: 'company_website' }));
		var btn = el('button', { type: 'button', class: 'bf-btn', id: 'bf-submit', text: status === 'submitted' ? 'Send my changes' : 'Submit my answers' });
		btn.addEventListener('click', submit);
		var copy = el('button', { type: 'button', class: 'bf-btn bf-ghost', id: 'bf-savelink', text: 'Save and get my link' });
		copy.addEventListener('click', saveAndLink);
		showLinkBox();
		root.appendChild(el('div', { class: 'bf-bar' }, [el('span', { id: 'bf-status', text: status === 'submitted' ? 'Sent' : 'Saved on this device as you type.' }), el('div', { style: 'display:flex;gap:8px' }, [copy, btn])]));
	}

	/* ── submit ── */
	function problems() {
		var out = [];
		each(sections(), function (sec) {
			each(fieldsOf(sec), function (f) {
				if (f.type === 'companies') {
					each(S.general[f.id], function (r, i) {
						each(f.columns, function (c) { if (c.required && !String(r[c.id] || '').trim()) out.push((i === 0 ? 'Parent company' : 'Subsidiary ' + i) + ': ' + c.label); });
					});
				} else if (f.required && !sec.repeat) {
					var v = S.general[f.id];
					if (f.type === 'count') { if (v === undefined && S.count === 0) return; return; }
					if (v == null || v === '' || (Array.isArray(v) && !v.length)) out.push(f.label);
				}
			});
		});
		return out;
	}
	function submit() {
		var box = document.getElementById('bf-errors'), btn = document.getElementById('bf-submit');
		var p = problems();
		if (p.length) {
			box.hidden = false; box.textContent = 'Please complete: ' + p.slice(0, 8).join('; ') + (p.length > 8 ? '…' : '');
			box.scrollIntoView({ behavior: 'smooth', block: 'center' }); return;
		}
		if (status !== 'submitted' && !validEmail()) { box.hidden = false; box.textContent = 'Please add a valid email so we can send you a copy.'; return; }
		box.hidden = true; btn.disabled = true; btn.textContent = 'Sending…';
		send(true).catch(function (e) {
			btn.disabled = false; btn.textContent = status === 'submitted' ? 'Send my changes' : 'Submit my answers';
			box.hidden = false; box.textContent = e.message; box.scrollIntoView({ behavior: 'smooth', block: 'center' });
		});
	}
	function showThanks(j) {
		root.innerHTML = '';
		try { localStorage.removeItem(LS); } catch (e) {}
		var c = el('div', { class: 'bf-card' }, [
			el('h1', { text: 'Thank you' }),
			el('p', { class: 'bf-intro', text: D.thanks || 'We have your answers.' }),
			el('p', { class: 'bf-help', text: 'Your reference: ' + j.reference + '. We also emailed you a copy.' }),
			el('p', { class: 'bf-help', text: 'Your private link, to change your answers later:' }),
			el('div', { class: 'bf-link', text: j.resume_url })
		]);
		if (j.pdf_url) c.appendChild(el('p', {}, [el('a', { class: 'bf-btn', href: j.pdf_url, target: '_blank', rel: 'noopener', text: 'View or download a PDF copy' })]));
		var again = el('button', { type: 'button', class: 'bf-btn bf-ghost', text: 'Change my answers' });
		again.addEventListener('click', function () { C.data = payload(); draw(); });
		c.appendChild(again);
		root.appendChild(c);
		window.scrollTo({ top: 0 });
	}

	/* ── cover: information and consent ── */
	function cover() {
		var cv = D.cover, started = !!token, agreed = !!C.consented;
		root.innerHTML = '';
		var c = el('div', { class: 'bf-card bf-cover' }, [
			C.logo ? el('img', { class: 'bf-cover-logo', src: C.logo, alt: C.biz || '' }) : null,
			el('p', { class: 'bf-eyebrow', text: cv.eyebrow || '' }),
			el('h1', { text: D.title }),
			cv.for ? el('p', { class: 'bf-for', text: 'For ' + cv.for }) : null
		]);
		each(cv.lead, function (p) { c.appendChild(el('p', { class: 'bf-lead', text: p })); });
		if (cv.parties && cv.parties.length) {
			c.appendChild(el('h3', { text: 'Who is asking' }));
			c.appendChild(el('div', { class: 'bf-parties' }, cv.parties.map(function (p) {
				return el('div', { class: 'bf-party' }, [el('b', { text: p.name }), el('div', { class: 'bf-help', text: p.detail || '' }), el('div', { text: p.address || '' })]);
			})));
			if (cv.parties_note) c.appendChild(el('p', { class: 'bf-help', text: cv.parties_note }));
		}
		each(cv.sections, function (sec) { c.appendChild(el('h3', { text: sec.title })); c.appendChild(el('p', { class: 'bf-text', text: sec.text })); });
		var go = el('button', { type: 'button', class: 'bf-btn bf-big', text: started ? (cv.resume || 'Continue') : (cv.cta || 'Continue') });
		if (cv.consent && !agreed) {
			var box = el('input', { type: 'checkbox', id: 'bf-agree' });
			go.disabled = true;
			box.addEventListener('change', function () { go.disabled = !box.checked; });
			c.appendChild(el('label', { class: 'bf-agree', for: 'bf-agree' }, [box, el('span', { text: cv.consent })]));
		} else if (agreed) {
			c.appendChild(el('p', { class: 'bf-help', text: 'You agreed to the above on ' + C.consented.replace('T', ' ').slice(0, 16) + '.' }));
		}
		go.addEventListener('click', function () {
			if (cv.consent && !agreed) consentGiven = true;
			try { sessionStorage.setItem(GO, '1'); } catch (e) {}
			draw(); window.scrollTo({ top: 0 });
		});
		c.appendChild(el('div', { style: 'margin-top:18px' }, [go]));
		root.appendChild(c);
	}

	/* ── start ── */
	if (!C.open) {
		root.appendChild(el('div', { class: 'bf-card' }, [el('h1', { text: D.title }), el('p', { class: 'bf-intro', text: 'This form is closed and is not taking answers right now.' })]));
		return;
	}
	load();
	var seen = false; try { seen = sessionStorage.getItem(GO) === '1'; } catch (e) {}
	if (seen && !C.consented) consentGiven = true;
	if (D.cover && !seen && status !== 'submitted') cover(); else draw();
	window.addEventListener('beforeunload', function (e) { if (dirty && !validEmail()) { e.preventDefault(); e.returnValue = ''; } });
})();
