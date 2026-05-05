/* =============================================================
   booking-api.js
   Napojení rezervačního formuláře na PHP backend.
   Tento soubor se načítá jako non-defer <script> na konci <body>,
   což zaručí, že běží synchronně PŘED defer main.js.

   Strategie:
     1) Monkey-patch fetch:  services.json  →  /api/public/services.php
        (převede backend payload do formátu, který main.js očekává)
     2) Po DOMContentLoaded:
        - po změně data + služby fetch /api/public/slots.php a
          překreslit #slots-grid reálnými sloty
        - capture-phase submit listener → POST /api/public/book.php
     3) Vykreslit potvrzovací stav po úspěšné rezervaci.
   ============================================================= */

(() => {
    'use strict';

    // -------------------------------------------------------------
    // 1) Monkey-patch fetch pro services.json → /api/public/services.php
    // -------------------------------------------------------------
    const _origFetch = window.fetch.bind(window);

    // Frontendová struktura kategorií, kterou main.js očekává.
    // Zachovávám popisky, které již na webu byly v services.json.
    const CATEGORY_META = [
        {
            id: 'damske',
            label: 'Dámské',
            icon: 'fa-venus',
            description: 'Nabízím dámské střihy, barvení, přelivy, melíry i speciální péči o vlasy. Každé vlasy jsou jiné, proto se vždy domluvíme podle jejich aktuálního stavu, délky a toho, jaký výsledek si přejete.',
            subcategories: [
                { id: 'poprve',     label: 'Jsem u vás poprvé' },
                { id: 'pravidelne', label: 'Chodím pravidelně' },
            ],
        },
        {
            id: 'panske',
            label: 'Pánské',
            icon: 'fa-mars',
            description: 'Pánské střihy jsou rychlé, praktické a upravené podle přání zákazníka. Můžete přijít na klasický střih, kombinaci s úpravou vousů nebo komplexní péči.',
        },
        {
            id: 'detske',
            label: 'Dětské',
            icon: 'fa-child',
            description: 'Dětský střih probíhá v klidu a bez zbytečného spěchu. Cílem je, aby se dítě cítilo příjemně a odcházelo spokojené.',
        },
    ];

    function adaptApiServicesToFrontend(apiPayload) {
        const list = (apiPayload && apiPayload.data) || [];
        const services = list.map((s) => ({
            id:           s.id,
            icon:         s.icon || 'fa-cut',
            category:     s.category,
            subcategory:  s.subcategory || undefined,
            name:         s.name,
            duration:     `${s.duration_min} min`,
            durationMin:  s.duration_min,
            price:        s.price === null ? null : Number(s.price),
            description:  s.description || '',
        }));
        return { categories: CATEGORY_META, services };
    }

    window.fetch = function (input, init) {
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        if (url === 'services.json' || url.endsWith('/services.json')) {
            return _origFetch('/api/public/services.php', { cache: 'no-store' })
                .then(async (resp) => {
                    if (!resp.ok) throw new Error('services API HTTP ' + resp.status);
                    const j = await resp.json();
                    const adapted = adaptApiServicesToFrontend(j);
                    return new Response(JSON.stringify(adapted), {
                        status: 200,
                        headers: { 'Content-Type': 'application/json' },
                    });
                });
        }
        return _origFetch(input, init);
    };

    // -------------------------------------------------------------
    // Pomůcky
    // -------------------------------------------------------------
    const $ = (id) => document.getElementById(id);

    const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (m) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[m]));

    /** Debounce pro fetch slotů – uživatel mění datum rychle. */
    function debounce(fn, ms) {
        let t = null;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    // -------------------------------------------------------------
    // 2) Sloty – načtení z /api/public/slots.php
    // -------------------------------------------------------------
    function getSelectedServiceId() {
        const el = $('serviceInput');
        return el && el.value ? parseInt(el.value, 10) : null;
    }

    function showSlotsState({ empty, closed, loading, info }) {
        const slotsGrid   = $('slots-grid');
        const slotsEmpty  = $('slots-empty');
        const slotsClosed = $('slots-closed');
        const slotsInfo   = $('slots-info');
        if (!slotsGrid || !slotsEmpty || !slotsClosed) return;

        slotsEmpty.classList.toggle('hidden',  !empty);
        slotsClosed.classList.toggle('hidden', !closed);
        slotsGrid.classList.toggle('hidden',   empty || closed || loading);

        if (slotsInfo) slotsInfo.textContent = info || '';
    }

    function renderSlotsList(slots) {
        const slotsGrid = $('slots-grid');
        const timeEl    = $('time');
        const slotsInfo = $('slots-info');
        if (!slotsGrid) return;

        if (!slots.length) {
            slotsGrid.innerHTML = '';
            slotsGrid.classList.add('hidden');
            $('slots-empty').classList.remove('hidden');
            if (slotsInfo) slotsInfo.innerHTML = '<span class="text-red-400">Pro vybraný den už není volný termín. Zkuste prosím jiný den.</span>';
            return;
        }

        slotsGrid.innerHTML = slots
            .map((t) => `<button type="button" class="slot-btn" data-time="${escapeHtml(t)}">${escapeHtml(t)}</button>`)
            .join('');
        slotsGrid.classList.remove('hidden');
        $('slots-empty').classList.add('hidden');
        $('slots-closed').classList.add('hidden');

        if (slotsInfo) slotsInfo.textContent = `${slots.length} volných termínů`;

        slotsGrid.querySelectorAll('.slot-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (timeEl) timeEl.value = btn.dataset.time;
                slotsGrid.querySelectorAll('.slot-btn').forEach((b) => b.classList.toggle('selected', b === btn));
                if (slotsInfo) slotsInfo.textContent = `Vybraný čas: ${btn.dataset.time}`;
            });
        });
    }

    async function refreshSlots() {
        const dateEl = $('date');
        const date = dateEl && dateEl.value;
        const serviceId = getSelectedServiceId();
        const slotsGrid = $('slots-grid');
        const timeEl    = $('time');

        if (!slotsGrid) return;
        if (timeEl) timeEl.value = '';

        if (!date || !serviceId) {
            showSlotsState({ empty: true, closed: false, loading: false, info: '' });
            slotsGrid.innerHTML = '';
            return;
        }

        // Loading state
        slotsGrid.innerHTML = '';
        $('slots-empty').classList.add('hidden');
        $('slots-closed').classList.add('hidden');
        slotsGrid.classList.remove('hidden');
        slotsGrid.innerHTML = '<div class="col-span-full text-center text-gray-500 py-4 text-sm"><i class="fas fa-spinner fa-spin text-gold/50 mr-2"></i>Načítám volné termíny…</div>';
        const slotsInfo = $('slots-info');
        if (slotsInfo) slotsInfo.textContent = '';

        try {
            const url = `/api/public/slots.php?date=${encodeURIComponent(date)}&service_id=${encodeURIComponent(serviceId)}`;
            const resp = await _origFetch(url, { cache: 'no-store' });
            const j = await resp.json();
            if (!resp.ok || j.error) {
                throw new Error(j.error || ('HTTP ' + resp.status));
            }
            const data = j.data || {};
            if (data.closed) {
                slotsGrid.innerHTML = '';
                showSlotsState({ empty: false, closed: true, loading: false, info: '' });
                return;
            }
            renderSlotsList(data.slots || []);
        } catch (err) {
            console.error('slots.php error:', err);
            slotsGrid.innerHTML = '';
            showSlotsState({ empty: true, closed: false, loading: false, info: '' });
            if (slotsInfo) slotsInfo.innerHTML = '<span class="text-red-400">Nepodařilo se načíst termíny. Zkuste to prosím znovu.</span>';
        }
    }
    const refreshSlotsDebounced = debounce(refreshSlots, 200);

    // -------------------------------------------------------------
    // 3) Submit – POST /api/public/book.php
    // -------------------------------------------------------------
    function showSuccess(message) {
        const form     = $('bookingForm');
        const success  = $('booking-success');
        if (!form || !success) return;

        const msgEl = success.querySelector('[data-success-message]');
        if (msgEl) msgEl.textContent = message || 'Vaše rezervace byla úspěšně přijata.';

        form.classList.add('hidden');
        success.classList.remove('hidden');
        success.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function resetToForm() {
        const form    = $('bookingForm');
        const success = $('booking-success');
        if (form)    form.classList.remove('hidden');
        if (success) success.classList.add('hidden');
        if (form)    form.reset();

        // vyčisti vybrané UI prvky
        document.querySelectorAll('.picker-btn.selected, .slot-btn.selected').forEach((b) => b.classList.remove('selected'));
        ['categoryInput', 'subcategoryInput', 'serviceInput', 'time'].forEach((id) => {
            const el = $(id); if (el) el.value = '';
        });
        const sub = $('subcategory-section'); if (sub) sub.classList.add('hidden');
        const svc = $('service-section');     if (svc) svc.classList.add('hidden');
        const dt  = $('datetime-section');    if (dt)  dt.classList.add('hidden');
        showSlotsState({ empty: true, closed: false, loading: false, info: '' });
        const sg  = $('slots-grid');          if (sg)  sg.innerHTML = '';
    }
    window.__bookingResetToForm = resetToForm;

    async function handleFormSubmitApi(event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        const firstName = ($('firstName').value || '').trim();
        const lastName  = ($('lastName').value  || '').trim();
        const email     = ($('email').value     || '').trim();
        const phone     = ($('phone').value     || '').trim();
        const serviceId = getSelectedServiceId();
        const date      = ($('date').value      || '').trim();
        const time      = ($('time').value      || '').trim();
        const note      = ($('message').value   || '').trim();
        const gdpr      = $('gdpr') && $('gdpr').checked;
        const honeypot  = ($('website') && $('website').value) || '';

        if (!firstName || !lastName) { alert('Vyplňte prosím své jméno a příjmení.'); return; }
        if (!email)                  { alert('Vyplňte prosím e-mail.'); return; }
        if (!phone)                  { alert('Vyplňte prosím telefon.'); return; }
        if (!serviceId)              { alert('Vyberte prosím službu.'); return; }
        if (!date)                   { alert('Vyberte prosím den.'); return; }
        if (!time)                   { alert('Vyberte prosím volný termín.'); return; }
        if (!gdpr)                   { alert('Pro odeslání rezervace prosím odsouhlaste zpracování osobních údajů.'); return; }

        const submitBtn = event.target.querySelector('button[type="submit"]');
        const origLabel = submitBtn ? submitBtn.innerHTML : null;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Odesílám…</span>';
        }

        const payload = {
            service_id: serviceId,
            date,
            time,
            name:  `${firstName} ${lastName}`.trim(),
            email,
            phone,
            note:  note || null,
            website: honeypot,
        };

        try {
            const resp = await _origFetch('/api/public/book.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });
            const j = await resp.json();
            if (!resp.ok || j.error) {
                throw new Error(j.error || ('HTTP ' + resp.status));
            }
            showSuccess('Děkujeme! Vaše rezervace byla přijata a brzy vás budu kontaktovat pro potvrzení termínu. Potvrzovací e-mail dorazil do vaší schránky.');
        } catch (err) {
            console.error('book.php error:', err);
            alert(err.message || 'Rezervaci se nepodařilo odeslat. Zkuste to prosím znovu.');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                if (origLabel !== null) submitBtn.innerHTML = origLabel;
            }
        }
    }

    // -------------------------------------------------------------
    // Synchronní registrace listenerů
    //
    // Tento skript je na konci <body> jako non-defer, takže běží
    // synchronně PŘED defer main.js. Tj. naše listenery jsou
    // registrované jako PRVNÍ → capture-phase listener s
    // stopImmediatePropagation zabrání spuštění listenerů z main.js.
    // -------------------------------------------------------------
    const form = $('bookingForm');
    if (form) {
        form.addEventListener('submit', handleFormSubmitApi, true);
        form.removeAttribute('onsubmit');
    }

    const dateEl = $('date');
    if (dateEl) {
        const onDateChange = (ev) => {
            // zastav main.js renderSlots() (mock sloty) – sloty plníme my z API
            ev.stopImmediatePropagation();

            // udrž visualizační flag (placeholder nad date inputem)
            dateEl.dataset.empty = dateEl.value ? 'false' : 'true';

            refreshSlotsDebounced();
        };
        ['change', 'input', 'blur'].forEach((ev) => dateEl.addEventListener(ev, onDateChange, true));
    }

    // Klik na výběr služby v #service-picker (skrytý #serviceInput
    // měněn main.js teprve po této události → setTimeout 0)
    document.addEventListener('click', (ev) => {
        const btn = ev.target.closest && ev.target.closest('[data-service-id]');
        if (btn) {
            setTimeout(refreshSlotsDebounced, 30);
        }
    }, false);

    // První inicializační render proveden po DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshSlotsDebounced);
    } else {
        refreshSlotsDebounced();
    }
})();
