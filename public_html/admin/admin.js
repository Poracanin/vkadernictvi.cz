/* =============================================================
   Vkadeřnictví – administrační rozhraní
   /admin/admin.js
   Veškeré napojení na PHP backend, žádný localStorage.
   ============================================================= */

(() => {
    'use strict';

    // -------------------------------------------------------------
    // CSRF + API helpers
    // -------------------------------------------------------------
    const CSRF = (() => {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    })();
    const ADMIN_USER = (() => {
        const m = document.querySelector('meta[name="admin-user"]');
        return m ? m.getAttribute('content') : '';
    })();

    /**
     * Tenký wrapper nad fetch:
     *   apiFetch('GET',  '/api/admin/bookings.php?status=pending')
     *   apiFetch('PATCH','/api/admin/bookings.php', { id: 1, action: 'confirm' })
     */
    async function apiFetch(method, url, body = null) {
        const opts = {
            method,
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        };
        if (method !== 'GET' && method !== 'HEAD') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token']  = CSRF;
            if (body !== null && body !== undefined) {
                opts.body = JSON.stringify(body);
            }
        }
        const resp = await fetch(url, opts);

        // 401 = vypršela session, redirect na login
        if (resp.status === 401) {
            window.location.href = '/admin/login.php';
            throw new Error('Nepřihlášen');
        }

        let json = null;
        try { json = await resp.json(); } catch (e) { /* ignore */ }
        if (!resp.ok || (json && json.error)) {
            throw new Error((json && json.error) || ('HTTP ' + resp.status));
        }
        return json;
    }

    // -------------------------------------------------------------
    // Toast / chyba helpers
    // -------------------------------------------------------------
    function toast(msg, type = 'success') {
        const wrap = document.getElementById('toast-wrap') || (() => {
            const w = document.createElement('div');
            w.id = 'toast-wrap';
            w.style.cssText = 'position:fixed;top:18px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none;';
            document.body.appendChild(w);
            return w;
        })();
        const el = document.createElement('div');
        const colors = type === 'error'
            ? 'background:#3a0d0d;border:1px solid #7f1d1d;color:#fca5a5;'
            : type === 'info'
                ? 'background:#0c1a2a;border:1px solid #1d4ed8;color:#93c5fd;'
                : 'background:#0d2f1a;border:1px solid #166534;color:#86efac;';
        el.style.cssText = `${colors};padding:10px 14px;border-radius:8px;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,.5);max-width:340px;pointer-events:auto;font-family:'Montserrat',sans-serif;`;
        el.innerHTML = msg;
        wrap.appendChild(el);
        setTimeout(() => {
            el.style.transition = 'opacity .3s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 300);
        }, 3500);
    }

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, m => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[m]));
    }

    function fmtPrice(p) {
        if (p === null || p === undefined || p === '') return 'na dotaz';
        return Number(p).toLocaleString('cs-CZ') + ' Kč';
    }

    function fmtDateTime(dtStr) {
        // "2026-05-15 14:00:00" → "15. 5. 2026 v 14:00"
        const d = new Date((dtStr || '').replace(' ', 'T'));
        if (isNaN(d.getTime())) return dtStr;
        return `${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()} v ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
    }

    function fmtDateShort(dtStr) {
        const d = new Date((dtStr || '').replace(' ', 'T'));
        if (isNaN(d.getTime())) return dtStr;
        return `${d.getDate()}. ${d.getMonth() + 1}. v ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
    }

    // =============================================================
    // 1) NAVIGACE (švihací menu)
    // =============================================================
    function switchTab(index, sectionId) {
        const listItems = document.querySelectorAll('#nav-list li');
        listItems.forEach(li => li.classList.remove('active'));
        if (listItems[index]) listItems[index].classList.add('active');

        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active-tab'));
        const sec = document.getElementById(sectionId);
        if (sec) sec.classList.add('active-tab');

        window.scrollTo({ top: 0, behavior: 'smooth' });

        // Lazy-load dat pro vybranou sekci
        if (sectionId === 'rezervace') loadBookings();
        else if (sectionId === 'kalendar') loadCalendarMonth();
        else if (sectionId === 'sluzby')  loadServices();
        else if (sectionId === 'doba')    loadHours();
    }
    window.switchTab = switchTab;

    // =============================================================
    // 2) HEADER – badge s počtem čekajících
    // =============================================================
    async function refreshStats() {
        try {
            const r = await apiFetch('GET', '/api/admin/stats.php');
            const pending = (r.data && r.data.pending_total) || 0;
            const badge = document.getElementById('header-pending-badge');
            if (badge) {
                if (pending > 0) {
                    badge.querySelector('[data-pending-count]').textContent = pending;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            }
            const navBadge = document.getElementById('nav-pending-count');
            if (navBadge) {
                navBadge.textContent = pending > 0 ? pending : '';
                navBadge.classList.toggle('hidden', pending === 0);
            }
        } catch (e) {
            console.warn('stats:', e.message);
        }
    }

    // =============================================================
    // 3) REZERVACE – výpis, potvrzení, zrušení, reschedule, smazání
    // =============================================================
    let bookingsFilter = 'pending'; // 'pending' | 'confirmed' | 'history'

    function setBookingsFilter(filter) {
        bookingsFilter = filter;
        document.querySelectorAll('[data-bookings-filter]').forEach(b => {
            const isActive = b.dataset.bookingsFilter === filter;
            b.classList.toggle('text-gold',          isActive);
            b.classList.toggle('border-gold',        isActive);
            b.classList.toggle('font-semibold',      isActive);
            b.classList.toggle('text-gray-500',      !isActive);
            b.classList.toggle('border-transparent', !isActive);
        });
        loadBookings();
    }
    window.setBookingsFilter = setBookingsFilter;

    async function loadBookings() {
        const containerPending   = document.getElementById('bookings-pending-list');
        const containerConfirmed = document.getElementById('bookings-confirmed-list');
        if (!containerPending || !containerConfirmed) return;

        // Section headers
        const headerPending   = document.getElementById('bookings-pending-header');
        const headerConfirmed = document.getElementById('bookings-confirmed-header');

        containerPending.innerHTML = `<div class="text-center text-gray-500 py-8 text-sm"><i class="fas fa-spinner fa-spin text-gold/50 mr-2"></i>Načítám…</div>`;
        containerConfirmed.innerHTML = '';
        if (headerPending)   headerPending.style.display   = 'flex';
        if (headerConfirmed) headerConfirmed.style.display = '';

        try {
            let url;
            if (bookingsFilter === 'pending') {
                url = '/api/admin/bookings.php?status=pending,confirmed&limit=200';
            } else if (bookingsFilter === 'confirmed') {
                url = '/api/admin/bookings.php?status=confirmed&limit=200';
            } else {
                url = '/api/admin/bookings.php?status=cancelled,done,no_show&limit=200';
            }
            const r = await apiFetch('GET', url);
            const all = r.data || [];

            if (bookingsFilter === 'pending') {
                const pending   = all.filter(b => b.status === 'pending');
                const confirmed = all.filter(b => b.status === 'confirmed' && new Date(b.start_at.replace(' ','T')) >= new Date(new Date().setHours(0,0,0,0)));

                renderPendingCards(pending);
                renderConfirmedCards(confirmed);

                if (headerPending)   headerPending.querySelector('[data-count]').textContent   = pending.length;
                if (headerConfirmed) headerConfirmed.querySelector('[data-count]').textContent = confirmed.length;
            } else {
                if (headerPending)   headerPending.style.display   = 'none';
                if (headerConfirmed) headerConfirmed.style.display = 'none';
                renderConfirmedCards(all, /*forceFull=*/true);
            }

            // Aktualizuj header pill counts
            updatePendingPills();

        } catch (e) {
            containerPending.innerHTML = `<div class="text-center text-red-400 py-8 text-sm">Chyba: ${escapeHtml(e.message)}</div>`;
        }
    }

    function renderPendingCards(list) {
        const c = document.getElementById('bookings-pending-list');
        if (!c) return;
        if (!list.length) {
            c.innerHTML = `<div class="text-center text-gray-500 py-8 text-sm border border-dashed border-gray-800 rounded-lg"><i class="fas fa-inbox text-2xl block mb-2 text-gray-700"></i>Žádné čekající rezervace.</div>`;
            return;
        }
        c.innerHTML = list.map(b => {
            const noteHtml = b.note
                ? `<p class="text-xs text-gray-500 mt-2 italic"><i class="far fa-comment-dots mr-1"></i>„${escapeHtml(b.note)}"</p>`
                : '';
            return `
            <div class="bg-dark-light border border-gold border-opacity-30 p-5 rounded-lg shadow-lg relative overflow-hidden group" data-booking-id="${b.id}">
                <div class="absolute left-0 top-0 bottom-0 w-1 bg-gold"></div>
                <span class="absolute top-3 right-3 bg-gold/20 text-gold text-[10px] uppercase tracking-widest font-bold px-2 py-1 rounded">Nová</span>
                <div class="flex flex-col md:flex-row justify-between md:items-center gap-4">
                    <div>
                        <h3 class="text-white font-medium text-lg">${escapeHtml(b.customer_name)}</h3>
                        <p class="text-sm text-gray-400 mt-1"><i class="fas ${b.icon || 'fa-cut'} text-gold mr-2 text-xs"></i>${escapeHtml(b.service_name)} <span class="text-gold ml-2">${fmtPrice(b.price)}</span></p>
                        <p class="text-sm text-gray-400 mt-1"><i class="far fa-clock text-gold mr-2 text-xs"></i>${fmtDateTime(b.start_at)} <span class="text-gray-500 text-xs">(${b.duration_min} min)</span></p>
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-phone mr-1"></i>${escapeHtml(b.customer_phone)}
                            &nbsp;|&nbsp;
                            <i class="fas fa-envelope mr-1"></i><a href="mailto:${escapeHtml(b.customer_email)}" class="hover:text-gold">${escapeHtml(b.customer_email)}</a>
                        </p>
                        ${noteHtml}
                    </div>
                    <div class="flex gap-2 flex-shrink-0">
                        <button onclick="window.__adminBooking.cancel(${b.id})" class="flex-1 md:flex-none border border-red-900 text-red-500 hover:bg-red-900 hover:text-white px-4 py-2 text-sm uppercase tracking-wider transition-colors rounded"><i class="fas fa-times mr-1"></i>Zamítnout</button>
                        <button onclick="window.__adminBooking.confirm(${b.id})" class="flex-1 md:flex-none bg-gold-gradient text-black font-semibold px-4 py-2 text-sm uppercase tracking-wider hover:brightness-110 transition-colors rounded shadow-lg"><i class="fas fa-check mr-1"></i>Potvrdit</button>
                    </div>
                </div>
            </div>`;
        }).join('');
    }

    function renderConfirmedCards(list, forceFull = false) {
        const c = document.getElementById('bookings-confirmed-list');
        if (!c) return;
        if (!list.length) {
            c.innerHTML = `<div class="text-center text-gray-500 py-6 text-sm border border-dashed border-gray-800 rounded-lg">Žádné rezervace.</div>`;
            return;
        }
        c.innerHTML = list.map(b => {
            const statusBadge = {
                confirmed: '<span class="bg-green-900/30 text-green-400 w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"><i class="fas fa-check"></i></span>',
                pending:   '<span class="bg-gold/20 text-gold w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"><i class="fas fa-hourglass-half"></i></span>',
                cancelled: '<span class="bg-red-900/30 text-red-400 w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"><i class="fas fa-times"></i></span>',
                done:      '<span class="bg-blue-900/30 text-blue-400 w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"><i class="fas fa-check-double"></i></span>',
                no_show:   '<span class="bg-gray-700 text-gray-300 w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"><i class="fas fa-user-slash"></i></span>',
            }[b.status] || '';

            const borderColor = {
                confirmed: 'border-green-900/30',
                pending:   'border-gold/30',
                cancelled: 'border-red-900/30',
                done:      'border-blue-900/30',
                no_show:   'border-gray-700',
            }[b.status] || 'border-gray-800';

            const actionsHtml = (b.status === 'confirmed' || b.status === 'pending') ? `
                <button onclick="window.__adminBooking.reschedule(${b.id})" class="text-gray-500 hover:text-gold transition-colors px-2 py-1" title="Přesunout"><i class="fas fa-edit"></i></button>
                <button onclick="window.__adminBooking.done(${b.id})" class="text-gray-500 hover:text-blue-400 transition-colors px-2 py-1" title="Označit jako provedeno"><i class="fas fa-check-double"></i></button>
                <button onclick="window.__adminBooking.cancel(${b.id})" class="text-gray-500 hover:text-red-500 transition-colors px-2 py-1" title="Zrušit"><i class="fas fa-times"></i></button>` : `
                <button onclick="window.__adminBooking.del(${b.id})" class="text-gray-500 hover:text-red-500 transition-colors px-2 py-1" title="Smazat"><i class="fas fa-trash"></i></button>`;

            return `
            <div class="bg-dark-light border ${borderColor} p-4 rounded-lg flex flex-col md:flex-row md:items-center gap-3" data-booking-id="${b.id}">
                <div class="flex items-center flex-1 gap-4">
                    ${statusBadge}
                    <div class="flex-1">
                        <p class="text-white text-sm font-medium">${escapeHtml(b.customer_name)}</p>
                        <p class="text-xs text-gray-500"><i class="fas ${b.icon || 'fa-cut'} text-gold mr-1"></i>${escapeHtml(b.service_name)} · <span class="text-gold">${fmtPrice(b.price)}</span></p>
                    </div>
                </div>
                <div class="flex items-center gap-3 text-xs text-gray-400 md:border-l md:border-gray-800 md:pl-4">
                    <span><i class="far fa-calendar-alt text-gold mr-1"></i>${fmtDateShort(b.start_at)}</span>
                    ${actionsHtml}
                </div>
            </div>`;
        }).join('');
    }

    function updatePendingPills() {
        // Pillony "X Nové / Y Potvrzené" v hlavičce sekce
        const pillNew = document.getElementById('header-pill-new');
        const pillOk  = document.getElementById('header-pill-ok');
        // Stahujeme statistiku samostatně
        apiFetch('GET', '/api/admin/stats.php').then(r => {
            const pending  = (r.data && r.data.pending_total) || 0;
            const upcoming = (r.data && r.data.upcoming && r.data.upcoming.confirmed) || 0;
            if (pillNew) pillNew.textContent = `${pending} Nové`;
            if (pillOk)  pillOk.textContent  = `${upcoming} Potvrzené`;
        }).catch(() => {});
    }

    const adminBooking = {
        async confirm(id) {
            if (!confirm('Potvrdit rezervaci #' + id + '?')) return;
            try {
                await apiFetch('PATCH', '/api/admin/bookings.php', { id, action: 'confirm' });
                toast('Rezervace potvrzena, e-mail odeslán.');
                loadBookings(); refreshStats();
            } catch (e) { toast(e.message, 'error'); }
        },
        async cancel(id) {
            if (!confirm('Zrušit rezervaci #' + id + '? Zákazník dostane e-mail.')) return;
            try {
                await apiFetch('PATCH', '/api/admin/bookings.php', { id, action: 'cancel' });
                toast('Rezervace zrušena, e-mail odeslán.');
                loadBookings(); refreshStats();
            } catch (e) { toast(e.message, 'error'); }
        },
        async done(id) {
            try {
                await apiFetch('PATCH', '/api/admin/bookings.php', { id, action: 'done' });
                toast('Označeno jako provedeno.');
                loadBookings(); refreshStats();
            } catch (e) { toast(e.message, 'error'); }
        },
        async noShow(id) {
            try {
                await apiFetch('PATCH', '/api/admin/bookings.php', { id, action: 'no_show' });
                toast('Označeno: nedostavila se.');
                loadBookings(); refreshStats();
            } catch (e) { toast(e.message, 'error'); }
        },
        async del(id) {
            if (!confirm('Tvrdě smazat rezervaci #' + id + '? (Zákazník nedostane e-mail.)')) return;
            try {
                await apiFetch('DELETE', '/api/admin/bookings.php', { id });
                toast('Smazáno.');
                loadBookings(); refreshStats();
            } catch (e) { toast(e.message, 'error'); }
        },
        async reschedule(id) {
            const v = prompt('Nový termín ve formátu YYYY-MM-DD HH:MM (např. 2026-05-20 14:30):');
            if (!v) return;
            if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(v)) { toast('Špatný formát termínu.', 'error'); return; }
            try {
                await apiFetch('PATCH', '/api/admin/bookings.php', { id, action: 'reschedule', new_start_at: v });
                toast('Termín přesunut, e-mail odeslán.');
                loadBookings(); refreshStats();
            } catch (e) { toast(e.message, 'error'); }
        },
    };
    window.__adminBooking = adminBooking;

    // =============================================================
    // 4) SLUŽBY – CRUD přes /api/admin/services.php
    // =============================================================
    let servicesData = [];
    let editingId    = null;
    let selectedIcon = 'fa-cut';

    const CATEGORY_LABEL = { damske: 'Dámské', panske: 'Pánské', detske: 'Dětské' };
    const CATEGORY_ORDER = { damske: 0, panske: 1, detske: 2 };
    const SUBCATEGORY_LABEL = { poprve: 'Jsem u vás poprvé', pravidelne: 'Chodím pravidelně' };
    const BADGE_COLORS = {
        damske: 'bg-pink-900/20 text-pink-300',
        panske: 'bg-blue-900/20 text-blue-300',
        detske: 'bg-purple-900/20 text-purple-300',
    };

    async function loadServices() {
        const tbody = document.getElementById('services-tbody');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="5" class="p-8 text-center text-gray-500"><i class="fas fa-spinner fa-spin text-gold/50 mr-2"></i>Načítám…</td></tr>`;
        try {
            const r = await apiFetch('GET', '/api/admin/services.php');
            servicesData = r.data || [];
            renderServices();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="5" class="p-8 text-center text-red-400">Chyba: ${escapeHtml(e.message)}</td></tr>`;
        }
    }

    function renderServices() {
        const tbody = document.getElementById('services-tbody');
        if (!tbody) return;
        const sorted = [...servicesData].sort((a, b) => {
            const ca = CATEGORY_ORDER[a.category] ?? 99;
            const cb = CATEGORY_ORDER[b.category] ?? 99;
            if (ca !== cb) return ca - cb;
            return (a.sort_order || 0) - (b.sort_order || 0) || (a.id || 0) - (b.id || 0);
        });

        if (sorted.length === 0) {
            tbody.innerHTML = `
                <tr><td colspan="5" class="p-8 text-center text-gray-500 text-sm">
                    <i class="fas fa-inbox text-2xl mb-2 block text-gray-700"></i>
                    Zatím žádné služby. Přidejte první kliknutím na <span class="text-gold">"Přidat službu"</span>.
                </td></tr>`;
            return;
        }

        tbody.innerHTML = sorted.map(s => {
            const catLabel = CATEGORY_LABEL[s.category] || s.category || '';
            const subLabel = s.subcategory ? (SUBCATEGORY_LABEL[s.subcategory] || s.subcategory) : '';
            const dim = s.is_active ? '' : 'opacity-50';
            return `
            <tr class="hover:bg-white/5 transition-colors group ${dim}" data-id="${s.id}">
                <td class="p-4 text-sm text-white"><i class="fas ${s.icon || 'fa-cut'} text-gold/60 mr-2 text-xs"></i>${escapeHtml(s.name)}${!s.is_active ? ' <span class="text-[10px] uppercase text-red-400 ml-1">(deaktivováno)</span>' : ''}</td>
                <td class="p-4 text-xs">
                    <span class="${BADGE_COLORS[s.category] || 'bg-gray-700 text-gray-200'} px-2 py-1 rounded text-[10px] uppercase tracking-wider">${escapeHtml(catLabel)}</span>
                    ${subLabel ? `<span class="block text-[10px] text-gray-500 mt-1">${escapeHtml(subLabel)}</span>` : ''}
                </td>
                <td class="p-4 text-xs text-gray-400 hidden md:table-cell">${s.duration_min} min</td>
                <td class="p-4 text-sm text-gold text-right whitespace-nowrap font-semibold">${fmtPrice(s.price)}</td>
                <td class="p-4 text-right whitespace-nowrap">
                    <button onclick="window.__adminService.openEdit(${s.id})" class="text-gray-500 hover:text-gold transition-colors p-2" title="Upravit"><i class="fas fa-edit"></i></button>
                    <button onclick="window.__adminService.del(${s.id})" class="text-gray-500 hover:text-red-500 transition-colors p-2" title="Smazat"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        }).join('');
    }

    // -- Service modal --
    function showServiceModal() {
        const m = document.getElementById('serviceModal');
        const c = document.getElementById('serviceModalContent');
        m.classList.remove('hidden');
        setTimeout(() => {
            m.classList.remove('opacity-0');
            c.classList.remove('scale-95');
            c.classList.add('scale-100');
        }, 10);
    }
    function closeServiceModal() {
        const m = document.getElementById('serviceModal');
        const c = document.getElementById('serviceModalContent');
        m.classList.add('opacity-0');
        c.classList.remove('scale-100');
        c.classList.add('scale-95');
        setTimeout(() => { m.classList.add('hidden'); editingId = null; }, 300);
    }
    window.closeServiceModal = closeServiceModal;

    function setIconInPicker(icon) {
        selectedIcon = icon || 'fa-cut';
        document.querySelectorAll('#iconPicker .icon-option').forEach(b => {
            const sel = b.dataset.icon === selectedIcon;
            b.classList.toggle('border-gold', sel);
            b.classList.toggle('text-gold', sel);
            b.classList.toggle('bg-gold/10', sel);
            b.classList.toggle('border-gray-800', !sel);
            b.classList.toggle('text-gray-400', !sel);
        });
    }

    function refreshSubcategoryVisibility() {
        const cat = document.getElementById('serviceModalCategory').value;
        const wrap = document.getElementById('serviceModalSubcategoryWrapper');
        const sel  = document.getElementById('serviceModalSubcategory');
        if (cat === 'damske') {
            wrap.style.display = '';
        } else {
            wrap.style.display = 'none';
            sel.value = '';
        }
    }

    function openServiceModalForAdd() {
        editingId = null;
        document.getElementById('serviceModalTitle').textContent = 'Přidat službu';
        document.getElementById('serviceModalName').value = '';
        document.getElementById('serviceModalCategory').value = 'damske';
        document.getElementById('serviceModalSubcategory').value = '';
        document.getElementById('serviceModalDuration').value = '60';
        document.getElementById('serviceModalPrice').value = '';
        document.getElementById('serviceModalDescription').value = '';
        setIconInPicker('fa-cut');
        refreshSubcategoryVisibility();
        showServiceModal();
    }
    window.openServiceModalForAdd = openServiceModalForAdd;

    function openServiceModalForEdit(id) {
        const s = servicesData.find(x => x.id === id);
        if (!s) return;
        editingId = id;
        document.getElementById('serviceModalTitle').textContent = 'Upravit službu';
        document.getElementById('serviceModalName').value = s.name || '';
        document.getElementById('serviceModalCategory').value = s.category || 'damske';
        document.getElementById('serviceModalSubcategory').value = s.subcategory || '';
        document.getElementById('serviceModalDuration').value = String(s.duration_min || 60);
        document.getElementById('serviceModalPrice').value = (s.price === null || s.price === undefined) ? '' : s.price;
        document.getElementById('serviceModalDescription').value = s.description || '';
        setIconInPicker(s.icon || 'fa-cut');
        refreshSubcategoryVisibility();
        showServiceModal();
    }

    async function saveService(event) {
        if (event) event.preventDefault();
        const name        = document.getElementById('serviceModalName').value.trim();
        const category    = document.getElementById('serviceModalCategory').value;
        const subcategory = document.getElementById('serviceModalSubcategory').value || null;
        const durationStr = document.getElementById('serviceModalDuration').value.trim();
        const description = document.getElementById('serviceModalDescription').value.trim();
        const priceRaw    = document.getElementById('serviceModalPrice').value;

        if (!name) { toast('Vyplňte název služby.', 'error'); return; }

        // Délka může být zadaná jako číslo (60) nebo "60 min" – vytáhnout digit
        const durationMin = parseInt((durationStr.match(/\d+/) || [60])[0], 10);
        if (!durationMin || durationMin < 5) { toast('Zadejte platnou délku v minutách.', 'error'); return; }

        const finalSub = category === 'damske' ? (subcategory || null) : null;
        const body = {
            name,
            duration_min: durationMin,
            price: priceRaw === '' ? null : Number(priceRaw),
            icon: selectedIcon,
            category,
            subcategory: finalSub,
            description,
        };

        try {
            if (editingId !== null) {
                await apiFetch('PATCH', '/api/admin/services.php', { id: editingId, ...body });
                toast('Služba upravena.');
            } else {
                body.is_active = 1;
                body.sort_order = (servicesData.reduce((m, s) => Math.max(m, s.sort_order || 0), 0)) + 10;
                await apiFetch('POST', '/api/admin/services.php', body);
                toast('Služba přidána.');
            }
            closeServiceModal();
            loadServices();
        } catch (e) {
            toast(e.message, 'error');
        }
    }
    window.saveService = saveService;

    async function deleteService(id) {
        const s = servicesData.find(x => x.id === id);
        if (!s) return;
        if (!confirm(`Opravdu smazat službu „${s.name}"?`)) return;
        try {
            await apiFetch('DELETE', '/api/admin/services.php', { id });
            toast('Služba smazána.');
            loadServices();
        } catch (e) {
            // Když je 409 kvůli aktivním rezervacím, nabídni deaktivaci
            if (/[Ss]lužbu nelze smazat/i.test(e.message)) {
                if (confirm(e.message + '\n\nMáte zájem o deaktivaci místo smazání?')) {
                    try {
                        await apiFetch('DELETE', '/api/admin/services.php', { id, soft: true });
                        toast('Služba deaktivována.');
                        loadServices();
                    } catch (e2) { toast(e2.message, 'error'); }
                }
            } else {
                toast(e.message, 'error');
            }
        }
    }

    window.__adminService = { openEdit: openServiceModalForEdit, del: deleteService };

    // =============================================================
    // 5) OTEVÍRACÍ DOBA + výjimky
    // =============================================================
    const DAY_NAMES = ['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];
    let hoursData = [];
    let overridesData = [];

    async function loadHours() {
        const list = document.getElementById('hoursList');
        if (!list) return;
        list.innerHTML = `<div class="text-center text-gray-500 py-6 text-sm"><i class="fas fa-spinner fa-spin text-gold/50 mr-2"></i>Načítám…</div>`;
        try {
            const r = await apiFetch('GET', '/api/admin/hours.php');
            hoursData     = r.data.working_hours || [];
            overridesData = r.data.overrides || [];
            renderHours();
            renderOverrides();
        } catch (e) {
            list.innerHTML = `<div class="text-center text-red-400 py-6 text-sm">Chyba: ${escapeHtml(e.message)}</div>`;
        }
    }

    function renderHours() {
        const list = document.getElementById('hoursList');
        if (!list) return;

        list.innerHTML = hoursData.map(d => {
            const open    = !d.is_closed;
            const rowCls  = open ? 'bg-black/40 hover:bg-black/60' : 'bg-red-900/10 border border-red-900/30';
            const togBg   = open ? 'bg-green-700' : 'bg-gray-700';
            const dotCls  = open ? 'translate-x-5 bg-white' : 'translate-x-1 bg-gray-400';
            const nameCol = open ? 'text-white' : 'text-gray-500';
            const dayName = DAY_NAMES[d.day_of_week - 1] || ('Den ' + d.day_of_week);
            const fromVal = d.open_time  || '09:00';
            const toVal   = d.close_time || '17:00';

            const timesBlock = open ? `
                <div class="flex items-center gap-1.5 flex-1 justify-end min-w-0">
                    <input type="time" value="${fromVal}" data-day="${d.day_of_week}" data-field="open_time"  class="time-input p-1.5 text-xs sm:text-sm rounded bg-black/60 border border-gray-700 text-white w-[70px] sm:w-[88px] focus:border-gold focus:outline-none">
                    <span class="text-gray-500 text-xs">–</span>
                    <input type="time" value="${toVal}"   data-day="${d.day_of_week}" data-field="close_time" class="time-input p-1.5 text-xs sm:text-sm rounded bg-black/60 border border-gray-700 text-white w-[70px] sm:w-[88px] focus:border-gold focus:outline-none">
                </div>
            ` : '<span class="text-red-500/70 text-xs font-semibold uppercase tracking-widest">Zavřeno</span>';

            return `
            <div class="day-row ${rowCls} px-3 py-2.5 rounded-md transition-colors" data-day="${d.day_of_week}">
                <div class="flex items-center gap-2.5">
                    <button type="button" onclick="window.__adminHours.toggle(${d.day_of_week})" class="day-toggle relative inline-flex h-5 w-9 items-center rounded-full ${togBg} transition-colors flex-shrink-0" title="${open ? 'Otevřeno – kliknutím zavřít' : 'Zavřeno – kliknutím otevřít'}">
                        <span class="day-toggle-dot inline-block h-3 w-3 transform rounded-full transition-transform ${dotCls}"></span>
                    </button>
                    <div class="${nameCol} font-medium text-sm w-14 sm:w-20 flex-shrink-0">${dayName}</div>
                    ${timesBlock}
                </div>
            </div>`;
        }).join('');

        // Live sync inputů s lokálními daty
        list.querySelectorAll('input[type="time"]').forEach(input => {
            input.addEventListener('change', e => {
                const dow = parseInt(e.target.dataset.day, 10);
                const field = e.target.dataset.field;
                const day = hoursData.find(d => d.day_of_week === dow);
                if (day) day[field] = e.target.value;
            });
        });
    }

    function toggleDay(dow) {
        const d = hoursData.find(x => x.day_of_week === dow);
        if (!d) return;
        d.is_closed = !d.is_closed;
        if (!d.is_closed) {
            if (!d.open_time)  d.open_time  = '09:00';
            if (!d.close_time) d.close_time = '17:00';
        }
        renderHours();
    }

    function renderOverrides() {
        const list = document.getElementById('overridesList');
        if (!list) return;
        if (!overridesData.length) {
            list.innerHTML = `<p class="text-xs text-gray-500 py-3">Žádné výjimky. Přidejte svátek nebo dovolenou níže.</p>`;
            return;
        }
        list.innerHTML = overridesData.map((o, i) => {
            const closed = !!o.is_closed;
            const text = closed
                ? `<span class="text-red-400">Zavřeno</span>`
                : `${o.open_time || '?'} – ${o.close_time || '?'}`;
            return `
            <div class="flex items-center justify-between gap-3 px-3 py-2 bg-black/30 rounded mb-1.5">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <i class="far fa-calendar text-gold text-sm"></i>
                    <span class="text-white text-sm whitespace-nowrap">${o.date}</span>
                    <span class="text-gray-400 text-xs">${text}</span>
                    ${o.reason ? `<span class="text-gray-500 text-xs italic truncate">— ${escapeHtml(o.reason)}</span>` : ''}
                </div>
                <button onclick="window.__adminHours.removeOverride(${i})" class="text-gray-500 hover:text-red-400 px-2" title="Odebrat"><i class="fas fa-times"></i></button>
            </div>`;
        }).join('');
    }

    function addOverride() {
        const date   = document.getElementById('ovDate').value;
        const closed = document.getElementById('ovClosed').checked;
        const open   = document.getElementById('ovFrom').value;
        const close  = document.getElementById('ovTo').value;
        const reason = document.getElementById('ovReason').value.trim();

        if (!date) { toast('Zadejte datum výjimky.', 'error'); return; }
        if (!closed && (!open || !close)) { toast('Zadejte otvírací časy nebo zaškrtněte „Zavřeno".', 'error'); return; }

        // Replace existing for same date
        overridesData = overridesData.filter(o => o.date !== date);
        overridesData.push({
            date,
            is_closed: closed,
            open_time:  closed ? null : open,
            close_time: closed ? null : close,
            reason: reason || null,
        });
        overridesData.sort((a, b) => a.date.localeCompare(b.date));
        renderOverrides();

        document.getElementById('ovDate').value = '';
        document.getElementById('ovReason').value = '';
        document.getElementById('ovClosed').checked = false;
    }

    function removeOverride(idx) {
        overridesData.splice(idx, 1);
        renderOverrides();
    }

    async function saveHours() {
        try {
            const payload = {
                working_hours: hoursData.map(d => ({
                    day_of_week: d.day_of_week,
                    open_time:   d.is_closed ? null : (d.open_time  || '09:00'),
                    close_time:  d.is_closed ? null : (d.close_time || '17:00'),
                    is_closed:   !!d.is_closed,
                })),
                overrides: overridesData.map(o => ({
                    date: o.date,
                    is_closed: !!o.is_closed,
                    open_time:  o.is_closed ? null : (o.open_time  || null),
                    close_time: o.is_closed ? null : (o.close_time || null),
                    reason: o.reason || null,
                })),
            };
            await apiFetch('POST', '/api/admin/hours.php', payload);
            toast('Otevírací doba uložena.');
        } catch (e) {
            toast(e.message, 'error');
        }
    }
    window.saveHours = saveHours;

    window.__adminHours = { toggle: toggleDay, addOverride, removeOverride };

    // =============================================================
    // 6) KALENDÁŘ – fetch reálných rezervací z API
    // =============================================================
    const monthNames = ['Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen', 'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
    let calCurrentMonth = new Date().getMonth();
    let calCurrentYear  = new Date().getFullYear();
    let calBookings     = []; // raw fetched bookings for current month

    async function loadCalendarMonth() {
        const grid  = document.getElementById('calGrid');
        const label = document.getElementById('calMonthLabel');
        if (!grid || !label) return;

        label.textContent = `${monthNames[calCurrentMonth]} ${calCurrentYear}`;
        grid.innerHTML = `<div class="col-span-7 text-center text-gray-500 py-8 text-sm"><i class="fas fa-spinner fa-spin text-gold/50 mr-2"></i>Načítám…</div>`;

        const from = `${calCurrentYear}-${String(calCurrentMonth + 1).padStart(2,'0')}-01`;
        const lastDay = new Date(calCurrentYear, calCurrentMonth + 1, 0).getDate();
        const to   = `${calCurrentYear}-${String(calCurrentMonth + 1).padStart(2,'0')}-${String(lastDay).padStart(2,'0')}`;

        try {
            const r = await apiFetch('GET', `/api/admin/bookings.php?status=pending,confirmed,done&from=${from}&to=${to}&limit=500`);
            calBookings = r.data || [];
            renderCalendar();
        } catch (e) {
            grid.innerHTML = `<div class="col-span-7 text-center text-red-400 py-6">Chyba: ${escapeHtml(e.message)}</div>`;
        }
    }

    function renderCalendar() {
        const grid = document.getElementById('calGrid');
        if (!grid) return;
        const firstDay = new Date(calCurrentYear, calCurrentMonth, 1);
        let firstDayOfWeek = firstDay.getDay() - 1;
        if (firstDayOfWeek < 0) firstDayOfWeek = 6;
        const daysInMonth = new Date(calCurrentYear, calCurrentMonth + 1, 0).getDate();
        const daysInPrev  = new Date(calCurrentYear, calCurrentMonth, 0).getDate();

        // Mapuj rezervace na den měsíce
        const byDay = {};
        calBookings.forEach(b => {
            const d = new Date(b.start_at.replace(' ', 'T'));
            if (d.getFullYear() === calCurrentYear && d.getMonth() === calCurrentMonth) {
                const day = d.getDate();
                (byDay[day] = byDay[day] || []).push(b);
            }
        });

        let html = '';
        for (let i = firstDayOfWeek - 1; i >= 0; i--) {
            html += `<div class="p-3 text-gray-700 opacity-50">${daysInPrev - i}</div>`;
        }

        const today = new Date();
        const todayDay = today.getDate(), todayMonth = today.getMonth(), todayYear = today.getFullYear();

        for (let day = 1; day <= daysInMonth; day++) {
            const dateObj = new Date(calCurrentYear, calCurrentMonth, day);
            let dow = dateObj.getDay() - 1; if (dow < 0) dow = 6;
            const isSunday  = dow === 6;
            const isToday   = day === todayDay && calCurrentMonth === todayMonth && calCurrentYear === todayYear;
            const dayBookings = byDay[day] || [];
            const hasPending  = dayBookings.some(b => b.status === 'pending');
            const hasBooking  = dayBookings.length > 0;

            let cls = 'p-3 rounded cursor-pointer transition-colors relative bg-black/40 hover:bg-gray-800 hover:text-white ';
            cls += isSunday ? 'text-red-400/50 ' : 'text-gray-300 ';
            if (isToday) cls += 'ring-1 ring-gold/60 ';

            let dotHtml = '';
            if (hasBooking) {
                const dotColor = hasPending ? 'bg-gold' : 'bg-green-500';
                dotHtml = `<span class="absolute bottom-1 left-1/2 transform -translate-x-1/2 w-1.5 h-1.5 ${dotColor} rounded-full"></span>`;
                if (dayBookings.length > 1) {
                    dotHtml += `<span class="absolute top-1 right-1 text-[9px] font-bold text-gold">${dayBookings.length}</span>`;
                }
            }

            html += `<div class="${cls}" onclick="window.__adminCalendar.openDay(${calCurrentYear}, ${calCurrentMonth}, ${day})">${day}${dotHtml}</div>`;
        }

        const total = firstDayOfWeek + daysInMonth;
        const remaining = (7 - (total % 7)) % 7;
        for (let day = 1; day <= remaining; day++) {
            html += `<div class="p-3 text-gray-700 opacity-50">${day}</div>`;
        }
        grid.innerHTML = html;
    }

    function openDayDetail(year, month, day) {
        const dateStr = `${year}-${String(month + 1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
        const list = calBookings.filter(b => b.start_at.startsWith(dateStr));

        const modal = document.getElementById('dayDetailModal');
        const content = document.getElementById('dayDetailContent');
        if (!modal || !content) {
            // Fallback: alert přehled
            const txt = list.length
                ? list.map(b => `${b.start_at.substring(11,16)} – ${b.customer_name} (${b.service_name}) [${b.status}]`).join('\n')
                : 'V tento den žádné rezervace.';
            alert(`${day}. ${month + 1}. ${year}\n\n${txt}`);
            return;
        }

        const items = list.length
            ? list.sort((a,b)=>a.start_at.localeCompare(b.start_at)).map(b => `
                <div class="bg-black/30 border border-gray-800 p-3 rounded mb-2">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <p class="text-white text-sm font-medium">${escapeHtml(b.customer_name)}</p>
                            <p class="text-xs text-gray-500"><i class="fas ${b.icon || 'fa-cut'} text-gold mr-1"></i>${escapeHtml(b.service_name)} · ${fmtPrice(b.price)}</p>
                        </div>
                        <span class="text-gold text-sm font-semibold">${b.start_at.substring(11,16)}</span>
                    </div>
                    <div class="mt-2 flex items-center gap-2 text-xs">
                        <span class="px-2 py-0.5 rounded bg-gray-800 text-gray-400 uppercase tracking-wider">${b.status}</span>
                        <span class="text-gray-500"><i class="fas fa-phone mr-1"></i>${escapeHtml(b.customer_phone)}</span>
                    </div>
                </div>`).join('')
            : `<p class="text-center text-gray-500 py-6 text-sm">V tento den žádné rezervace.</p>`;

        content.innerHTML = `
            <h3 class="font-serif text-xl text-white mb-4">${day}. ${month + 1}. ${year}</h3>
            ${items}
            <button onclick="window.__adminCalendar.closeDay()" class="mt-3 w-full border border-gray-700 text-gray-300 hover:bg-gray-800 px-4 py-2 text-xs uppercase tracking-wider rounded transition-colors">Zavřít</button>
        `;
        modal.classList.remove('hidden');
    }

    function closeDayDetail() {
        const m = document.getElementById('dayDetailModal');
        if (m) m.classList.add('hidden');
    }

    window.__adminCalendar = { openDay: openDayDetail, closeDay: closeDayDetail };

    // =============================================================
    // 7) Init
    // =============================================================
    function init() {
        // Logout
        const logoutBtn = document.querySelector('.logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', () => { window.location.href = '/admin/logout.php'; });
        }

        // Service modal – kategorie změna
        const catSel = document.getElementById('serviceModalCategory');
        if (catSel) catSel.addEventListener('change', refreshSubcategoryVisibility);

        // Service modal – ikony
        document.querySelectorAll('#iconPicker .icon-option').forEach(b => {
            b.addEventListener('click', () => setIconInPicker(b.dataset.icon));
        });

        // Service modal – click mimo / ESC
        const sm = document.getElementById('serviceModal');
        if (sm) sm.addEventListener('click', e => { if (e.target === sm) closeServiceModal(); });

        // Day detail modal – click mimo
        const dm = document.getElementById('dayDetailModal');
        if (dm) dm.addEventListener('click', e => { if (e.target === dm) closeDayDetail(); });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                if (sm && !sm.classList.contains('hidden')) closeServiceModal();
                if (dm && !dm.classList.contains('hidden')) closeDayDetail();
            }
        });

        // Kalendář – navigace měsíci
        const prev = document.getElementById('calPrev');
        const next = document.getElementById('calNext');
        const tdy  = document.getElementById('calToday');
        if (prev) prev.addEventListener('click', () => {
            calCurrentMonth--;
            if (calCurrentMonth < 0) { calCurrentMonth = 11; calCurrentYear--; }
            loadCalendarMonth();
        });
        if (next) next.addEventListener('click', () => {
            calCurrentMonth++;
            if (calCurrentMonth > 11) { calCurrentMonth = 0; calCurrentYear++; }
            loadCalendarMonth();
        });
        if (tdy) tdy.addEventListener('click', () => {
            const d = new Date();
            calCurrentMonth = d.getMonth();
            calCurrentYear  = d.getFullYear();
            loadCalendarMonth();
        });

        // Filtr záložky rezervací
        document.querySelectorAll('[data-bookings-filter]').forEach(b => {
            b.addEventListener('click', () => setBookingsFilter(b.dataset.bookingsFilter));
        });

        // Initial load – sekce Rezervace je defaultně aktivní
        refreshStats();
        loadBookings();

        // Auto-refresh stats každých 60s
        setInterval(refreshStats, 60_000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
