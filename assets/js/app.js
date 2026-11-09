(function () {
    'use strict';

    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    /* Mobile menu */
    const toggle = $('#navToggle');
    if (toggle) {
        toggle.addEventListener('click', () => $('#mainNav').classList.toggle('open'));
    }

    /* Form validation */
    function showError(field, message) {
        field.classList.add('invalid');
        const err = document.createElement('div');
        err.className = 'field-error';
        err.textContent = message;
        field.parentNode.appendChild(err);
    }

    function clearErrors(form) {
        $$('.field-error', form).forEach((n) => n.remove());
        $$('.invalid', form).forEach((n) => n.classList.remove('invalid'));
    }

    function validateForm(form) {
        clearErrors(form);
        let ok = true;
        $$('input, select, textarea', form).forEach((f) => {
            if (f.disabled || f.type === 'hidden' || f.type === 'checkbox' || f.type === 'submit') { return; }
            const v = f.value.trim();
            const label = f.dataset.label || (f.name || 'This field').replace(/_/g, ' ');
            if (f.required && v === '') {
                showError(f, 'Please fill in ' + label + '.'); ok = false; return;
            }
            if (v === '') { return; }
            if (f.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
                showError(f, 'Please enter a valid email address.'); ok = false; return;
            }
            if (f.type === 'tel' && !/^[0-9+ ()\-]{9,15}$/.test(v)) {
                showError(f, 'Phone number must be 9-15 digits (numbers, spaces, + - allowed).'); ok = false; return;
            }
            if (f.minLength > 0 && v.length < f.minLength) {
                showError(f, 'Must be at least ' + f.minLength + ' characters.'); ok = false; return;
            }
            if (f.dataset.password !== undefined && !(/[A-Za-z]/.test(v) && /\d/.test(v))) {
                showError(f, 'Password must contain at least one letter and one number.'); ok = false; return;
            }
            if (f.dataset.match) {
                const other = document.getElementById(f.dataset.match);
                if (other && other.value !== f.value) {
                    showError(f, 'Passwords do not match.'); ok = false; return;
                }
            }
            if (f.type === 'number' && f.min !== '' && parseFloat(v) < parseFloat(f.min)) {
                showError(f, 'Value must be at least ' + f.min + '.'); ok = false;
            }
        });
        return ok;
    }

    $$('form[data-validate]').forEach((form) => {
        form.setAttribute('novalidate', 'novalidate');
        form.addEventListener('submit', (ev) => {
            if (!validateForm(form)) {
                ev.preventDefault();
                const first = $('.invalid', form);
                if (first) { first.focus(); }
            }
        });
    });

    /* Live booking widget */
    const booking = $('#bookingForm');
    if (booking) {
        const api = booking.dataset.api;
        const svc = $('#service_id');
        const staff = $('#staff_id');
        const date = $('#appointment_date');
        const timeInput = $('#appointment_time');
        const box = $('#slots');
        const exclude = booking.dataset.exclude || '0';
        let preStaff = booking.dataset.staff || '';
        let preTime = (booking.dataset.time || '').slice(0, 5);

        const setMessage = (text) => { box.textContent = ''; const p = document.createElement('span'); p.className = 'muted'; p.textContent = text; box.appendChild(p); };

        async function loadStaff() {
            if (!staff || staff.tagName !== 'SELECT') { return loadSlots(); }
            staff.innerHTML = '';
            if (!svc.value) {
                staff.add(new Option('Select a service first', ''));
                return loadSlots();
            }
            try {
                const res = await fetch(api + '/staff.php?service_id=' + encodeURIComponent(svc.value));
                const data = await res.json();
                staff.add(new Option('Choose a staff member', ''));
                (data.staff || []).forEach((s) => staff.add(new Option(s.name + ' - ' + s.job_title, s.staff_id)));
                if (preStaff) { staff.value = preStaff; preStaff = ''; }
            } catch (err) {
                staff.add(new Option('Could not load staff', ''));
            }
            loadSlots();
        }

        async function loadSlots() {
            timeInput.value = '';
            if (!svc.value || !staff.value || !date.value) {
                setMessage('Choose a service, staff member and date to see available times.');
                return;
            }
            setMessage('Loading available times...');
            try {
                const url = api + '/slots.php?service_id=' + encodeURIComponent(svc.value) +
                    '&staff_id=' + encodeURIComponent(staff.value) +
                    '&date=' + encodeURIComponent(date.value) +
                    '&exclude=' + encodeURIComponent(exclude);
                const res = await fetch(url);
                const data = await res.json();
                const slots = data.slots || [];
                if (!slots.length) {
                    setMessage('No free times on that date. Please try another date or staff member.');
                    return;
                }
                box.textContent = '';
                slots.forEach((t) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'slot';
                    b.textContent = t;
                    b.addEventListener('click', () => {
                        $$('.slot', box).forEach((x) => x.classList.remove('selected'));
                        b.classList.add('selected');
                        timeInput.value = t;
                        const old = $('#timeError');
                        if (old) { old.remove(); }
                    });
                    box.appendChild(b);
                    if (preTime && t === preTime) { b.click(); }
                });
                preTime = '';
            } catch (err) {
                setMessage('Could not load times. Please refresh the page.');
            }
        }

        if (svc && svc.tagName === 'SELECT') { svc.addEventListener('change', () => { preStaff = ''; loadStaff(); }); }
        if (staff && staff.tagName === 'SELECT') { staff.addEventListener('change', loadSlots); }
        date.addEventListener('change', loadSlots);

        booking.addEventListener('submit', (ev) => {
            if (!timeInput.value) {
                ev.preventDefault();
                if (!$('#timeError')) {
                    const err = document.createElement('div');
                    err.id = 'timeError';
                    err.className = 'field-error';
                    err.textContent = 'Please pick one of the available times.';
                    box.parentNode.appendChild(err);
                }
            }
        });

        loadStaff();
    }

    /* Dashboard bar chart  */
    $$('[data-chart]').forEach((chart) => {
        let points = [];
        try { points = JSON.parse(chart.dataset.chart); } catch (err) { return; }
        const max = Math.max.apply(null, points.map((p) => p.value).concat([1]));
        points.forEach((p) => {
            const col = document.createElement('div');
            col.className = 'bar-col';
            const val = document.createElement('div');
            val.className = 'bar-value';
            val.textContent = 'R ' + Math.round(p.value).toLocaleString('en-ZA');
            const bar = document.createElement('div');
            bar.className = 'bar';
            const lab = document.createElement('div');
            lab.className = 'bar-label';
            lab.textContent = p.label;
            col.appendChild(val); col.appendChild(bar); col.appendChild(lab);
            chart.appendChild(col);
            setTimeout(() => { bar.style.height = Math.max(2, (p.value / max) * 150) + 'px'; }, 80);
        });
    });

    /* Live table search  */
    $$('input[data-filter-table]').forEach((input) => {
        const table = document.getElementById(input.dataset.filterTable);
        if (!table) { return; }
        input.addEventListener('input', () => {
            const q = input.value.toLowerCase();
            $$('tbody tr', table).forEach((row) => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    });

    /* Confirm destructive actions  */
    $$('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (ev) => {
            if (!window.confirm(form.dataset.confirm)) { ev.preventDefault(); }
        });
    });
})();
