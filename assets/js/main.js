/* =============================================================
   Vkadeřnictví – hlavní JS
   Soubor: assets/js/main.js
   Načítáno z index.html s atributem `defer` (DOM je tedy hotový).
   ============================================================= */

(() => {
    'use strict';

    // -------------------------------------------------------------
    // Konfigurace
    // -------------------------------------------------------------
    const SERVICES_STORAGE_KEY = 'vkadernice_services_v1';
    const SERVICES_JSON_URL = 'services.json';

    // Otevírací doba pro generování slotů (0 = Ne, 1 = Po, …)
    const SLOTS_BY_DAY = {
        0: null,                              // Neděle – zavřeno
        1: { from: '09:00', to: '17:00' },    // Pondělí
        2: { from: '09:00', to: '17:00' },    // Úterý
        3: { from: '10:00', to: '18:00' },    // Středa
        4: { from: '09:00', to: '17:00' },    // Čtvrtek
        5: null,                              // Pátek – zavřeno
        6: null,                              // Sobota – zavřeno
    };
    const SLOT_STEP_MIN = 30;

    // -------------------------------------------------------------
    // Pomocné funkce
    // -------------------------------------------------------------
    const $ = (id) => document.getElementById(id);

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (m) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[m]));
    }

    function formatPrice(p) {
        if (p === null || p === undefined || p === '') return 'na dotaz';
        return Number(p).toLocaleString('cs-CZ') + ' Kč';
    }

    // -------------------------------------------------------------
    // Patička – aktuální rok
    // -------------------------------------------------------------
    function initFooterYear() {
        const yearEl = $('year');
        if (yearEl) yearEl.textContent = new Date().getFullYear();
    }

    // -------------------------------------------------------------
    // Galerie / Lightbox
    // -------------------------------------------------------------
    const galleryImages = [
        'photos/vkadernice-ukazky-prace-1.webp',
        'photos/vkadernice-ukazky-prace-2.webp',
        'photos/vkadernice-ukazky-prace-3.webp',
        'photos/vkadernice-ukazky-prace-4.webp',
    ];
    let currentImageIndex = 0;

    function updateLightboxImage() {
        const lightboxImage = $('lightboxImage');
        const lightboxCounter = $('lightboxCounter');
        if (!lightboxImage || !lightboxCounter) return;

        lightboxImage.src = galleryImages[currentImageIndex];
        lightboxImage.alt = 'Ukázka práce - kadeřnictví Kladno ' + (currentImageIndex + 1);
        lightboxCounter.textContent = (currentImageIndex + 1) + ' / ' + galleryImages.length;
    }

    function openLightbox(index) {
        const lightbox = $('lightbox');
        if (!lightbox) return;

        currentImageIndex = index;
        updateLightboxImage();
        lightbox.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(() => lightbox.classList.remove('opacity-0'), 10);
    }

    function closeLightbox() {
        const lightbox = $('lightbox');
        if (!lightbox) return;

        lightbox.classList.add('opacity-0');
        setTimeout(() => {
            lightbox.classList.add('hidden');
            document.body.style.overflow = '';
        }, 300);
    }

    function nextImage() {
        currentImageIndex = (currentImageIndex + 1) % galleryImages.length;
        updateLightboxImage();
    }

    function prevImage() {
        currentImageIndex = (currentImageIndex - 1 + galleryImages.length) % galleryImages.length;
        updateLightboxImage();
    }

    function initLightbox() {
        const lightbox = $('lightbox');
        if (!lightbox) return;

        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) closeLightbox();
        });

        document.addEventListener('keydown', (e) => {
            if (lightbox.classList.contains('hidden')) return;
            if (e.key === 'Escape')      closeLightbox();
            if (e.key === 'ArrowRight')  nextImage();
            if (e.key === 'ArrowLeft')   prevImage();
        });
    }

    // -------------------------------------------------------------
    // Mobilní menu
    // -------------------------------------------------------------
    function initMobileMenu() {
        const btn   = $('mobile-menu-btn');
        const close = $('mobile-menu-close');
        const modal = $('mobile-menu-modal');
        if (!btn || !close || !modal) return;

        const open = () => {
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        };
        const hide = () => {
            modal.classList.remove('open');
            document.body.style.overflow = '';
        };

        btn.addEventListener('click', open);
        close.addEventListener('click', hide);

        modal.querySelectorAll('a[href^="#"]').forEach((link) => {
            link.addEventListener('click', hide);
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('open')) hide();
        });
    }

    // -------------------------------------------------------------
    // Navbar (smrštění + tlačítko nahoru)
    // -------------------------------------------------------------
    function initNavbarAndBackToTop() {
        const nav = $('navbar');
        const backToTopBtn = $('back-to-top');

        const onScroll = () => {
            if (nav) {
                if (window.scrollY > 50) {
                    nav.classList.add('scrolled', 'bg-dark/95', 'shadow-lg');
                    nav.classList.remove('bg-dark/90');
                } else {
                    nav.classList.remove('scrolled', 'bg-dark/95', 'shadow-lg');
                    nav.classList.add('bg-dark/90');
                }
            }
            if (backToTopBtn) {
                backToTopBtn.classList.toggle('visible', window.scrollY > 400);
            }
        };

        window.addEventListener('scroll', onScroll, { passive: true });

        if (backToTopBtn) {
            backToTopBtn.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    }

    // -------------------------------------------------------------
    // Modal „rezervace odeslána"
    // -------------------------------------------------------------
    function openMessageModal() {
        const modal = $('messageModal');
        const content = $('modalContent');
        if (!modal || !content) return;

        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            content.classList.remove('scale-95');
            content.classList.add('scale-100');
        }, 10);
    }

    function closeModal() {
        const modal = $('messageModal');
        const content = $('modalContent');
        if (!modal || !content) return;

        modal.classList.add('opacity-0');
        content.classList.remove('scale-100');
        content.classList.add('scale-95');

        setTimeout(() => modal.classList.add('hidden'), 300);
    }

    function initMessageModal() {
        const modal = $('messageModal');
        if (!modal) return;
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    // -------------------------------------------------------------
    // Formulář – odeslání + reset
    // -------------------------------------------------------------
    function handleFormSubmit(event) {
        event.preventDefault();

        const category = $('categoryInput').value;
        const service  = $('serviceInput').value;
        const time     = $('time').value;

        if (!category) {
            alert('Vyberte prosím kategorii (Dámské / Pánské / Dětské).');
            $('category-picker').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (!service) {
            alert('Vyberte prosím službu.');
            $('service-section').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (!time) {
            alert('Vyberte prosím volný termín.');
            $('slots-section').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        openMessageModal();

        const form = $('bookingForm');
        if (form) form.reset();

        document.querySelectorAll('.picker-btn.selected').forEach((b) => b.classList.remove('selected'));
        $('subcategory-section').classList.add('hidden');
        $('service-section').classList.add('hidden');
        showDatetimePlaceholder();
        formState = { category: null, subcategory: null, serviceId: null };
    }

    function initBookingForm() {
        const form = $('bookingForm');
        if (form) form.addEventListener('submit', handleFormSubmit);
    }

    // -------------------------------------------------------------
    // Datum + volné termíny
    // -------------------------------------------------------------
    function takenForDate(dateStr) {
        // Deterministicky obsazené sloty (demo data)
        let hash = 0;
        for (let i = 0; i < dateStr.length; i++) {
            hash = (hash * 31 + dateStr.charCodeAt(i)) | 0;
        }
        const seed = Math.abs(hash);
        const taken = new Set();
        for (let i = 0; i < 5; i++) taken.add((seed + i * 7) % 16);
        return taken;
    }

    function buildSlots(from, to, step) {
        const [fh, fm] = from.split(':').map(Number);
        const [th, tm] = to.split(':').map(Number);
        const start = fh * 60 + fm;
        const end   = th * 60 + tm;
        const list  = [];
        for (let m = start; m < end; m += step) {
            const hh = String(Math.floor(m / 60)).padStart(2, '0');
            const mm = String(m % 60).padStart(2, '0');
            list.push(`${hh}:${mm}`);
        }
        return list;
    }

    function initDatetimeAndSlots() {
        const dateEl = $('date');
        const timeEl = $('time');
        if (!dateEl) return;

        // Minimální datum = dnešek
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        dateEl.min = `${yyyy}-${mm}-${dd}`;

        const syncEmpty = (el) => { el.dataset.empty = el.value ? 'false' : 'true'; };
        syncEmpty(dateEl);
        ['input', 'change', 'blur'].forEach((ev) => dateEl.addEventListener(ev, () => syncEmpty(dateEl)));

        const slotsGrid   = $('slots-grid');
        const slotsEmpty  = $('slots-empty');
        const slotsClosed = $('slots-closed');
        const slotsInfo   = $('slots-info');

        function setSelected(time) {
            timeEl.value = time;
            slotsGrid.querySelectorAll('.slot-btn').forEach((btn) => {
                btn.classList.toggle('selected', btn.dataset.time === time);
            });
            if (slotsInfo && time) slotsInfo.textContent = `Vybraný čas: ${time}`;
        }

        function renderSlots() {
            timeEl.value = '';
            slotsInfo.textContent = '';

            if (!dateEl.value) {
                slotsEmpty.classList.remove('hidden');
                slotsClosed.classList.add('hidden');
                slotsGrid.classList.add('hidden');
                slotsGrid.innerHTML = '';
                return;
            }

            const d = new Date(dateEl.value + 'T00:00');
            const dayCfg = SLOTS_BY_DAY[d.getDay()];

            if (!dayCfg) {
                slotsEmpty.classList.add('hidden');
                slotsClosed.classList.remove('hidden');
                slotsGrid.classList.add('hidden');
                slotsGrid.innerHTML = '';
                return;
            }

            const list = buildSlots(dayCfg.from, dayCfg.to, SLOT_STEP_MIN);
            const taken = takenForDate(dateEl.value);
            const free = list.length - [...taken].filter((i) => i < list.length).length;

            slotsGrid.innerHTML = list
                .map((t, i) => {
                    const disabled = taken.has(i);
                    return `<button type="button" class="slot-btn" data-time="${t}" ${disabled ? 'disabled' : ''}>${t}</button>`;
                })
                .join('');

            slotsEmpty.classList.add('hidden');
            slotsClosed.classList.add('hidden');
            slotsGrid.classList.remove('hidden');
            slotsInfo.textContent = `${free} volných termínů`;

            slotsGrid.querySelectorAll('.slot-btn:not(:disabled)').forEach((btn) => {
                btn.addEventListener('click', () => setSelected(btn.dataset.time));
            });
        }

        ['change', 'input'].forEach((ev) => dateEl.addEventListener(ev, renderSlots));
        renderSlots();

        // Validace při odeslání: musí být zvolený slot
        const form = $('booking-form') || dateEl.closest('form');
        if (form) {
            form.addEventListener('submit', (e) => {
                if (!timeEl.value) {
                    e.preventDefault();
                    e.stopPropagation();
                    slotsInfo.innerHTML = '<span class="text-red-400">Prosím zvolte volný termín</span>';
                    slotsGrid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, true);
        }
    }

    // =============================================================
    // Služby – načtení z services.json (s překryvem z localStorage)
    // =============================================================
    let SERVICES_DATA = { categories: [], services: [] };
    let formState = { category: null, subcategory: null, serviceId: null };

    async function loadServicesData() {
        // 1) Základ ze services.json (kanonický zdroj)
        let data = { categories: [], services: [] };
        try {
            const res = await fetch(SERVICES_JSON_URL, { cache: 'no-store' });
            if (res.ok) data = await res.json();
        } catch (e) {
            console.warn('Nepodařilo se načíst services.json:', e);
        }

        // 2) Překryv úprav z administrace (localStorage)
        try {
            const cached = localStorage.getItem(SERVICES_STORAGE_KEY);
            if (cached) {
                const parsed = JSON.parse(cached);
                if (parsed && Array.isArray(parsed.services)) {
                    data.services = parsed.services;
                }
                if (parsed && Array.isArray(parsed.categories) && parsed.categories.length) {
                    data.categories = parsed.categories;
                }
            }
        } catch (e) {
            console.warn('Cache překryv selhal:', e);
        }

        return data;
    }

    function renderServicesSection(data) {
        const grid = $('services-grid');
        if (!grid) return;

        if (!data.categories.length) {
            grid.innerHTML = `
                <div class="col-span-full text-center text-gray-500 py-12">
                    <i class="fas fa-exclamation-circle text-gold/50 text-2xl mb-3"></i>
                    <p class="text-sm">Ceník se nepodařilo načíst.</p>
                </div>`;
            return;
        }

        grid.innerHTML = data.categories
            .map((cat) => {
                const catServices = data.services.filter((s) => s.category === cat.id);
                const items = catServices
                    .map((s) => `
                        <div class="py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <p class="text-white font-semibold leading-snug">${escapeHtml(s.name)}</p>
                                    ${s.duration ? `<p class="text-xs text-gray-400 mt-1 font-medium tracking-wide">${escapeHtml(s.duration)}</p>` : ''}
                                </div>
                                <p class="text-gold font-semibold whitespace-nowrap text-sm">${formatPrice(s.price)}</p>
                            </div>
                            ${s.description ? `<p class="text-xs text-gray-500 mt-2 leading-relaxed font-light">${escapeHtml(s.description)}</p>` : ''}
                        </div>
                    `)
                    .join('');

                // Dámské zabírají na PC celý levý sloupec přes 2 řady
                const spanClass = cat.id === 'damske' ? 'lg:row-span-2' : '';

                return `
                    <div class="bg-dark border border-gray-800 shadow-2xl relative overflow-hidden flex flex-col h-full ${spanClass}">
                        <div class="bg-gold-gradient px-4 py-2.5 flex items-center justify-center text-black">
                            <i class="fas ${escapeHtml(cat.icon)} text-sm mr-2"></i>
                            <h3 class="font-serif text-base font-bold uppercase tracking-widest">${escapeHtml(cat.label)}</h3>
                        </div>
                        ${cat.description ? `<p class="px-6 pt-5 text-xs text-gray-400 font-light leading-relaxed text-center">${escapeHtml(cat.description)}</p>` : ''}
                        <div class="px-6 py-2 divide-y divide-gold/10 flex-1">
                            ${items || '<p class="py-6 text-center text-sm text-gray-500">Zatím žádné služby</p>'}
                        </div>
                    </div>`;
            })
            .join('');
    }

    // =============================================================
    // Rezervační formulář – kategorie / podkategorie / služba
    // =============================================================
    function renderCategoryPicker() {
        const wrap = $('category-picker');
        if (!wrap) return;

        wrap.innerHTML = SERVICES_DATA.categories
            .map((cat) => `
                <button type="button" class="picker-btn picker-btn--category" data-category="${escapeHtml(cat.id)}">
                    <i class="fas ${escapeHtml(cat.icon)} picker-icon"></i>
                    <span class="picker-title">${escapeHtml(cat.label)}</span>
                </button>
            `)
            .join('');

        wrap.querySelectorAll('[data-category]').forEach((btn) => {
            btn.addEventListener('click', () => selectCategory(btn.dataset.category));
        });
    }

    function selectCategory(catId) {
        formState.category    = catId;
        formState.subcategory = null;
        formState.serviceId   = null;
        $('categoryInput').value    = catId;
        $('subcategoryInput').value = '';
        $('serviceInput').value     = '';

        document.querySelectorAll('#category-picker [data-category]').forEach((b) => {
            b.classList.toggle('selected', b.dataset.category === catId);
        });

        const cat = SERVICES_DATA.categories.find((c) => c.id === catId);
        const subSection     = $('subcategory-section');
        const serviceSection = $('service-section');

        showDatetimePlaceholder();

        if (cat && Array.isArray(cat.subcategories) && cat.subcategories.length) {
            renderSubcategoryPicker(cat.subcategories);
            subSection.classList.remove('hidden');
            serviceSection.classList.add('hidden');
        } else {
            subSection.classList.add('hidden');
            renderServicePicker();
            serviceSection.classList.remove('hidden');
        }
    }

    function showDatetimePlaceholder() {
        $('datetime-placeholder').classList.remove('hidden');
        $('datetime-section').classList.add('hidden');
    }

    function showDatetimeSection() {
        $('datetime-placeholder').classList.add('hidden');
        $('datetime-section').classList.remove('hidden');
    }

    function renderSubcategoryPicker(subs) {
        const wrap = $('subcategory-picker');
        if (!wrap) return;

        wrap.innerHTML = subs
            .map((sub) => `
                <button type="button" class="picker-btn" data-subcategory="${escapeHtml(sub.id)}">
                    <span class="picker-title">${escapeHtml(sub.label)}</span>
                </button>
            `)
            .join('');

        wrap.querySelectorAll('[data-subcategory]').forEach((btn) => {
            btn.addEventListener('click', () => selectSubcategory(btn.dataset.subcategory));
        });
    }

    function selectSubcategory(subId) {
        formState.subcategory = subId;
        formState.serviceId   = null;
        $('subcategoryInput').value = subId;
        $('serviceInput').value     = '';

        document.querySelectorAll('#subcategory-picker [data-subcategory]').forEach((b) => {
            b.classList.toggle('selected', b.dataset.subcategory === subId);
        });

        renderServicePicker();
        $('service-section').classList.remove('hidden');
        showDatetimePlaceholder();
    }

    function renderServicePicker() {
        const wrap = $('service-picker');
        if (!wrap) return;

        const filtered = SERVICES_DATA.services.filter((s) => {
            if (s.category !== formState.category) return false;
            if (formState.subcategory && s.subcategory && s.subcategory !== formState.subcategory) return false;
            if (formState.subcategory && !s.subcategory) return false;
            return true;
        });

        if (!filtered.length) {
            wrap.innerHTML = `<p class="text-sm text-gray-500 py-6 text-center border border-dashed border-gray-800 rounded">Pro tuto volbu zatím nejsou dostupné služby.</p>`;
            return;
        }

        wrap.innerHTML = filtered
            .map((s) => `
                <button type="button" class="picker-btn" data-service-id="${s.id}">
                    <span class="picker-title">${escapeHtml(s.name)}</span>
                    <span class="picker-meta">
                        <span><i class="far fa-clock mr-1"></i>${escapeHtml(s.duration || '')}</span>
                        <span class="text-gold font-semibold">${formatPrice(s.price)}</span>
                    </span>
                    ${s.description ? `<span class="text-xs text-gray-500 font-light leading-relaxed mt-2">${escapeHtml(s.description)}</span>` : ''}
                </button>
            `)
            .join('');

        wrap.querySelectorAll('[data-service-id]').forEach((btn) => {
            btn.addEventListener('click', () => selectService(Number(btn.dataset.serviceId)));
        });
    }

    function selectService(id) {
        formState.serviceId = id;
        $('serviceInput').value = id;

        document.querySelectorAll('#service-picker [data-service-id]').forEach((b) => {
            b.classList.toggle('selected', Number(b.dataset.serviceId) === id);
        });

        showDatetimeSection();
        // Scroll jen na mobilu (na PC je termín hned vedle)
        if (window.matchMedia('(max-width: 1023px)').matches) {
            $('datetime-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    async function initServices() {
        SERVICES_DATA = await loadServicesData();
        renderServicesSection(SERVICES_DATA);
        renderCategoryPicker();
    }

    function initServicesRefreshOnFocus() {
        // Reload při návratu do okna (kdyby se v adminu něco změnilo)
        window.addEventListener('focus', async () => {
            const fresh = await loadServicesData();
            if (JSON.stringify(fresh) !== JSON.stringify(SERVICES_DATA)) {
                SERVICES_DATA = fresh;
                renderServicesSection(SERVICES_DATA);
                renderCategoryPicker();
                formState = { category: null, subcategory: null, serviceId: null };
                $('subcategory-section').classList.add('hidden');
                $('service-section').classList.add('hidden');
                showDatetimePlaceholder();
            }
        });
    }

    // =============================================================
    // Globální exporty (pro inline onclick="..." v HTML)
    // =============================================================
    window.openLightbox  = openLightbox;
    window.closeLightbox = closeLightbox;
    window.nextImage     = nextImage;
    window.prevImage     = prevImage;
    window.closeModal    = closeModal;

    // =============================================================
    // Inicializace po načtení DOM
    // =============================================================
    initFooterYear();
    initLightbox();
    initMobileMenu();
    initNavbarAndBackToTop();
    initMessageModal();
    initBookingForm();
    initDatetimeAndSlots();
    initServices();
    initServicesRefreshOnFocus();
})();
