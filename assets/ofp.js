(function () {
	if (typeof OFP === 'undefined') return;
	document.querySelectorAll('.ofp-form').forEach(function (form) {
		var drop = form.querySelector('.ofp-drop'),
			input = form.querySelector('input[type=file]'),
			prev = form.querySelector('.ofp-prev'),
			fname = form.querySelector('.ofp-fname'),
			btn = form.querySelector('.ofp-btn'),
			msg = form.querySelector('.ofp-msg'),
			prog = form.querySelector('.ofp-progress'),
			bar = form.querySelector('.ofp-bar span'),
			ptxt = form.querySelector('.ofp-ptxt'),
			file = null;

		function setMsg(t, bad) { msg.textContent = t || ''; msg.className = 'ofp-msg' + (bad ? ' bad' : ''); }
		function pick(f) {
			if (!f) return;
			var ext = (f.name.split('.').pop() || '').toLowerCase();
			if (OFP.ext.indexOf(ext) < 0) { setMsg(OFP.msg.badType, 1); return; }
			if (f.size > OFP.maxMb * 1048576) { setMsg(OFP.msg.tooBig, 1); return; }
			file = f; setMsg('');
			fname.textContent = f.name + ' · ' + (f.size / 1048576).toFixed(1) + 'MB';
			drop.classList.add('has');
			if (/^image\//.test(f.type)) {
				var r = new FileReader();
				r.onload = function () { prev.src = r.result; prev.hidden = false; };
				r.readAsDataURL(f);
			} else { prev.hidden = true; }
		}
		input.addEventListener('change', function () { pick(input.files[0]); });
		drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
		['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
		['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
		drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) pick(e.dataTransfer.files[0]); });

		function poll(token) {
			var t = setInterval(function () {
				fetch(OFP.ajax + '?action=ofp_status&t=' + encodeURIComponent(token), { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (!j.success) return;
						bar.style.width = j.data.pct + '%'; ptxt.textContent = j.data.text;
						if (j.data.done) { clearInterval(t); setMsg(j.data.text); }
					}).catch(function () {});
			}, 5000);
			setTimeout(function () { clearInterval(t); }, 20 * 60 * 1000);
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!file) { setMsg('בחרו קובץ להעלאה', 1); return; }
			if (!form.elements['name'].value.trim() || !form.elements['phone'].value.trim()) { setMsg('נא למלא שם וטלפון', 1); return; }
			if (!form.elements['consent'].checked) { setMsg('נא לאשר את התנאים', 1); return; }
			var fd = new FormData(form);
			fd.set('file', file); fd.append('action', 'ofp_upload'); fd.append('nonce', OFP.nonce);
			btn.disabled = true; prog.hidden = false; setMsg('');
			var x = new XMLHttpRequest();
			x.open('POST', OFP.ajax);
			x.upload.onprogress = function (ev) { if (ev.lengthComputable) { bar.style.width = Math.round(ev.loaded / ev.total * 30) + '%'; ptxt.textContent = 'מעלה… ' + Math.round(ev.loaded / ev.total * 100) + '%'; } };
			x.onload = function () {
				var j = {}; try { j = JSON.parse(x.responseText); } catch (er) {}
				if (j.success) {
					form.classList.add('sent');
					setMsg(j.data.message || OFP.msg.success);
					bar.style.width = '35%'; ptxt.textContent = 'בתור לעיבוד…';
					if (j.data.token) poll(j.data.token);
				} else {
					btn.disabled = false; prog.hidden = true;
					setMsg((j.data && j.data.message) || OFP.msg.error, 1);
				}
			};
			x.onerror = function () { btn.disabled = false; prog.hidden = true; setMsg(OFP.msg.error, 1); };
			x.send(fd);
		});
	});
})();
