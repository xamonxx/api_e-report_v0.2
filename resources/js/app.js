import Alpine from 'alpinejs';
import flatpickr from 'flatpickr';
import { Indonesian } from 'flatpickr/dist/l10n/id.js';
import Swal from 'sweetalert2';
import 'flatpickr/dist/flatpickr.css';

window.Alpine = Alpine;
window.Swal = Swal;

const THEME_STORAGE_KEY = 'e-report-theme';

const getSystemTheme = () =>
    window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';

const normalizeTheme = (value) => (value === 'dark' || value === 'light' ? value : getSystemTheme());

const getStoredTheme = () => {
    try {
        return window.localStorage.getItem(THEME_STORAGE_KEY);
    } catch (_error) {
        return null;
    }
};

const saveTheme = (mode) => {
    try {
        window.localStorage.setItem(THEME_STORAGE_KEY, mode);
    } catch (_error) {
        // Ignore storage failures so the UI still works.
    }
};

const applyThemeMode = (mode) => {
    const nextMode = normalizeTheme(mode);
    const root = document.documentElement;

    root.classList.remove('light', 'dark');
    root.classList.add(nextMode);
    root.dataset.theme = nextMode;
    root.style.colorScheme = nextMode;

    return nextMode;
};

const initializeThemeMode = () => applyThemeMode(getStoredTheme());

const createThemeController = () => ({
    themeMode: initializeThemeMode(),
    themeMediaQuery: null,
    initTheme() {
        this.themeMode = initializeThemeMode();
        this.themeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

        const syncWithSystem = (event) => {
            if (getStoredTheme()) {
                return;
            }

            this.themeMode = applyThemeMode(event.matches ? 'dark' : 'light');
        };

        if (typeof this.themeMediaQuery.addEventListener === 'function') {
            this.themeMediaQuery.addEventListener('change', syncWithSystem);
            return;
        }

        this.themeMediaQuery.addListener(syncWithSystem);
    },
    setTheme(mode) {
        this.themeMode = applyThemeMode(mode);
        saveTheme(this.themeMode);
    },
    toggleTheme() {
        this.setTheme(this.themeMode === 'dark' ? 'light' : 'dark');
    },
});

window.themeController = function themeController() {
    return createThemeController();
};

window.applyThemeMode = applyThemeMode;
window.toggleThemeMode = function toggleThemeMode() {
    const currentMode = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
    const nextMode = applyThemeMode(currentMode === 'dark' ? 'light' : 'dark');
    saveTheme(nextMode);
    return nextMode;
};

window.initDatePickers = function initDatePickers(scope = document) {
    scope.querySelectorAll('[data-datepicker]').forEach((input) => {
        if (input._flatpickr) {
            return;
        }

        flatpickr(input, {
            locale: Indonesian,
            altInput: true,
            altFormat: 'd/m/Y',
            dateFormat: 'Y-m-d',
            disableMobile: true,
            appendTo: document.body,
            monthSelectorType: 'static',
            prevArrow:
                '<svg viewBox="0 0 24 24" class="h-4 w-4"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            nextArrow:
                '<svg viewBox="0 0 24 24" class="h-4 w-4"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            onReady: (_selectedDates, _dateStr, instance) => {
                instance.altInput.classList.add('date-picker-input');
                instance.altInput.placeholder = input.dataset.datepickerPlaceholder || 'Pilih tanggal';
                instance.set('positionElement', instance.altInput);
                instance.calendarContainer.classList.add('app-datepicker-popover');
            },
            onOpen: (_selectedDates, _dateStr, instance) => {
                // Recalculate position after layout shifts to avoid clipped calendar popovers.
                instance._positionCalendar();
            },
        });
    });
};

window.setDatePickerValue = function setDatePickerValue(id, value = '') {
    const input = document.getElementById(id);
    if (!input || !input._flatpickr) {
        return;
    }

    input._flatpickr.setDate(value || '', false, 'Y-m-d');
};

window.appShell = function appShell(defaultSidebarOpen) {
    return {
        ...createThemeController(),
        isMobile: window.innerWidth < 1024,
        sidebarOpen: window.innerWidth >= 1024 ? defaultSidebarOpen : false,
        desktopSidebarOpen: window.innerWidth >= 1024 ? defaultSidebarOpen : false,
        lastFocusedElement: null,
        init() {
            this.initTheme();

            this.$watch('sidebarOpen', (value) => {
                if (!this.isMobile) {
                    this.desktopSidebarOpen = value;
                    document.cookie = `sidebar_open=${value}; path=/; max-age=31536000`;
                    return;
                }

                if (value) {
                    this.lastFocusedElement = document.activeElement;
                    this.$nextTick(() => this.focusSidebarFirstElement());
                } else if (this.lastFocusedElement instanceof HTMLElement) {
                    this.$nextTick(() => this.lastFocusedElement.focus());
                }
            });

            window.setTimeout(() => {
                document.querySelectorAll('.toast-container').forEach((element) => element.remove());
            }, 3000);
        },
        focusSidebarFirstElement() {
            const panel = document.getElementById('sidebar-drawer');
            if (!panel) {
                return;
            }

            const focusable = panel.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            );

            focusable[0]?.focus();
        },
        trapSidebarFocus(event) {
            if (!this.sidebarOpen || !this.isMobile || event.key !== 'Tab') {
                return;
            }

            const panel = document.getElementById('sidebar-drawer');
            if (!panel) {
                return;
            }

            const focusable = Array.from(
                panel.querySelectorAll(
                    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                )
            ).filter((element) => element instanceof HTMLElement && element.offsetParent !== null);

            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                last.focus();
                event.preventDefault();
                return;
            }

            if (!event.shiftKey && document.activeElement === last) {
                first.focus();
                event.preventDefault();
            }
        },
        handleResize() {
            const nextIsMobile = window.innerWidth < 1024;
            if (nextIsMobile === this.isMobile) {
                return;
            }

            this.isMobile = nextIsMobile;

            if (this.isMobile) {
                this.desktopSidebarOpen = this.sidebarOpen;
                this.sidebarOpen = false;
                return;
            }

            this.sidebarOpen = this.desktopSidebarOpen;
        },
    };
};

window.notificationBadge = function notificationBadge(initialCount, apiUrl, csrfToken) {
    return {
        badgeCount: initialCount,
        pollTimeout: null,
        pollFailures: 0,
        isPolling: false,
        startPolling() {
            if (this.isPolling) {
                return;
            }

            this.isPolling = true;

            const scheduleNext = (delay = 15000) => {
                this.pollTimeout = window.setTimeout(() => {
                    this.poll();
                }, delay);
            };

            this.poll = () => {
                if (document.visibilityState === 'hidden') {
                    scheduleNext(15000);
                    return;
                }

                fetch(apiUrl, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                })
                    .then((response) => (response.ok ? response.json() : Promise.reject(response)))
                    .then((data) => {
                        this.badgeCount = data.total || 0;
                        this.pollFailures = 0;
                        scheduleNext(15000);
                    })
                    .catch(() => {
                        this.pollFailures += 1;
                        scheduleNext(this.pollFailures >= 3 ? 60000 : 20000);
                    });
            };

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && this.pollFailures > 0) {
                    window.clearTimeout(this.pollTimeout);
                    this.poll();
                }
            });

            this.poll();
        },
    };
};

window.consultationsPage = function consultationsPage(config) {
    const parseJsonArray = (rawValue) => {
        try {
            const parsed = JSON.parse(rawValue ?? '[]');
            return Array.isArray(parsed) ? parsed.map((value) => String(value)) : [];
        } catch (_error) {
            return [];
        }
    };

    return {
        showImportModal: config.showImportModal,
        showCreateModal: config.showCreateModal,
        showEditModal: false,
        editData: {},
        init() {
            document.querySelectorAll('.btn-edit').forEach((button) => {
                button.addEventListener('click', () => {
                    this.editData = {
                        id: button.getAttribute('data-id'),
                        consultation_id: button.getAttribute('data-consultation-id'),
                        client_name: button.getAttribute('data-name'),
                        phone: button.getAttribute('data-phone'),
                        province: button.getAttribute('data-province'),
                        city: button.getAttribute('data-city'),
                        district: button.getAttribute('data-district'),
                        address: button.getAttribute('data-address'),
                        account_id: button.getAttribute('data-account'),
                        needs_category_id: button.getAttribute('data-category'),
                        needs_category_ids: parseJsonArray(button.getAttribute('data-category-ids')),
                        product_details: button.getAttribute('data-product-details'),
                        status_category_id: button.getAttribute('data-status'),
                        consultation_date: button.getAttribute('data-date'),
                        notes: button.getAttribute('data-notes'),
                    };
                    this.showEditModal = true;
                });
            });
        },
    };
};

window.modalLocationAutoFill = function modalLocationAutoFill(initialCity = '', initialProvince = '', initialDistrict = '') {
    return {
        city: initialCity,
        province: initialProvince,
        district: initialDistrict,
        loading: false,
        citySuggestions: [],
        districtSuggestions: [],
        showCitySuggestions: false,
        showDistrictSuggestions: false,
        cityMapping: null,
        districtMapping: null,
        async getCityMapping() {
            if (this.cityMapping) {
                return this.cityMapping;
            }

            this.loading = true;
            try {
                const response = await fetch(document.body.dataset.citiesUrl);
                this.cityMapping = await response.json();
            } catch (error) {
                this.cityMapping = {};
            } finally {
                this.loading = false;
            }

            return this.cityMapping;
        },
        async getDistrictMapping() {
            if (this.districtMapping) {
                return this.districtMapping;
            }

            this.loading = true;
            try {
                const response = await fetch(document.body.dataset.districtsUrl);
                this.districtMapping = await response.json();
            } catch (error) {
                this.districtMapping = {};
            } finally {
                this.loading = false;
            }

            return this.districtMapping;
        },
        async onCityInput() {
            const value = this.city.trim().toLowerCase();
            if (value.length < 2) {
                this.citySuggestions = [];
                this.showCitySuggestions = false;
                return;
            }

            const mapping = await this.getCityMapping();
            this.citySuggestions = Object.entries(mapping)
                .filter(([city]) => city.toLowerCase().includes(value))
                .slice(0, 8)
                .map(([city, province]) => ({ city, province }));
            this.showCitySuggestions = this.citySuggestions.length > 0;
        },
        async onDistrictInput() {
            const value = this.district.trim().toLowerCase();
            if (value.length < 2) {
                this.districtSuggestions = [];
                this.showDistrictSuggestions = false;
                return;
            }

            const mapping = await this.getDistrictMapping();
            const cityKeyword = this.city.trim().toLowerCase();

            this.districtSuggestions = Object.entries(mapping)
                .filter(([district, meta]) => {
                    const matchesDistrict = district.toLowerCase().includes(value);
                    const matchesCity = cityKeyword === '' || String(meta.city ?? '').toLowerCase().includes(cityKeyword);

                    return matchesDistrict && matchesCity;
                })
                .slice(0, 8)
                .map(([district, meta]) => ({
                    district,
                    city: meta.city,
                    province: meta.province,
                }));
            this.showDistrictSuggestions = this.districtSuggestions.length > 0;
        },
        selectCity(item) {
            this.city = item.city;
            this.province = item.province;
            this.showCitySuggestions = false;
            this.citySuggestions = [];
        },
        selectDistrict(item) {
            this.district = item.district;
            this.city = item.city;
            this.province = item.province;
            this.showDistrictSuggestions = false;
            this.districtSuggestions = [];
        },
        setPendingConfirmation(label = 'Belum ada konfirmasi') {
            this.province = label;
            this.city = label;
            this.district = label;
            this.showCitySuggestions = false;
            this.showDistrictSuggestions = false;
            this.citySuggestions = [];
            this.districtSuggestions = [];
        },
    };
};

window.modalCityAutoFill = function modalCityAutoFill(initialCity = '', initialProvince = '', initialDistrict = '') {
    return window.modalLocationAutoFill(initialCity, initialProvince, initialDistrict);
};

window.cityAutoFill = function cityAutoFill(initialCity = '', initialProvince = '', initialDistrict = '') {
    return window.modalLocationAutoFill(initialCity, initialProvince, initialDistrict);
};

window.searchableOptions = function searchableOptions(options = []) {
    const normalizedOptions = options.map((option) => {
        if (typeof option === 'string') {
            return {
                value: option,
                label: option,
            };
        }

        return {
            value: String(option?.value ?? option?.id ?? ''),
            label: String(option?.label ?? option?.name ?? option?.value ?? option?.id ?? ''),
        };
    });

    return {
        open: false,
        search: '',
        options: normalizedOptions,
        openPanel() {
            this.open = true;
            this.search = '';
            this.$nextTick(() => this.$refs.searchInput?.focus());
        },
        close() {
            this.open = false;
            this.search = '';
        },
        toggle() {
            if (this.open) {
                this.close();
                return;
            }

            this.openPanel();
        },
        filteredOptions() {
            const keyword = this.search.trim().toLowerCase();

            if (keyword === '') {
                return this.options;
            }

            return this.options.filter((option) => option.label.toLowerCase().includes(keyword));
        },
    };
};

window.searchableSelect = function searchableSelect(options = [], initialValue = '', onChangeHandler = null) {
    return {
        ...window.searchableOptions(options),
        selected: String(initialValue ?? ''),
        selectedLabel(placeholder = 'Pilih opsi...') {
            const current = this.options.find((option) => option.value === String(this.selected ?? ''));
            return current?.label ?? placeholder;
        },
        setSelected(value) {
            this.selected = String(value ?? '');

            if (onChangeHandler && typeof window[onChangeHandler] === 'function') {
                window[onChangeHandler](this.selected);
            }

            this.close();
        },
        clear() {
            this.setSelected('');
        },
    };
};

window.updatePreviewId = function updatePreviewId(accountId) {
    const target = document.getElementById('preview-consultation-id');
    if (!accountId || !target) {
        return;
    }

    target.style.opacity = '0.5';
    fetch(`${document.body.dataset.previewConsultationIdUrl}?account_id=${accountId}`, {
        headers: { Accept: 'application/json' },
    })
        .then((response) => response.json())
        .then((data) => {
            target.textContent = data.id;
            target.style.opacity = '1';
        })
        .catch(() => {
            target.style.opacity = '1';
        });
};

window.syncAnalyticsPeriodType = function syncAnalyticsPeriodType(periodType) {
    window.dispatchEvent(
        new CustomEvent('analytics-period-type', {
            detail: String(periodType ?? 'monthly'),
        })
    );
};

window.buildConsultationUpdateUrl = function buildConsultationUpdateUrl(id) {
    if (!id) {
        return '#';
    }

    return `${document.body.dataset.consultationsBaseUrl}/${id}`;
};

window.confirmDelete = function confirmDelete(formId, clientName) {
    Swal.fire({
        title: 'Hapus data konsultasi?',
        text: `Data lead atas nama '${clientName}' akan terhapus secara permanen dari sistem!`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#9f403d',
        cancelButtonColor: '#737c7f',
        confirmButtonText: 'Ya, hapus!',
        cancelButtonText: 'Batal',
        customClass: {
            popup: 'rounded-2xl shadow-2xl',
            title: 'text-xl font-headline font-bold text-on-surface',
            confirmButton: 'bg-error hover:bg-error-dim rounded-xl px-8 py-3 font-bold',
            cancelButton: 'bg-outline hover:bg-outline-variant rounded-xl px-8 py-3 font-bold',
        },
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById(formId)?.submit();
        }
    });
};

window.masterDataPage = function masterDataPage(config) {
    return {
        activeTab: config.initialTab,
        showEditUserModal: config.showEditUserModal,
        createUserRole: config.createUserRole ?? 'admin',
        editUser: {
            id: config.editUser.id ?? '',
            name: config.editUser.name ?? '',
            email: config.editUser.email ?? '',
            role: config.editUser.role ?? 'admin',
            account_id: config.editUser.account_id ?? '',
        },
        init() {
            const picker = document.getElementById('statusColorPicker');
            const text = document.getElementById('statusColorText');

            if (picker && text) {
                text.value = picker.value.toUpperCase();
                picker.addEventListener('input', (event) => {
                    text.value = event.target.value.toUpperCase();
                });
            }

            this.syncActiveTabToUrl();
            this.$watch('activeTab', (value) => {
                this.syncActiveTabToUrl(value);
            });

            if (this.showEditUserModal) {
                document.body.classList.add('overflow-hidden');
            }
        },
        syncActiveTabToUrl(tab = this.activeTab) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);

            if (tab !== 'users') {
                url.searchParams.delete('search_user');
                url.searchParams.delete('users_page');
            }

            const query = url.searchParams.toString();
            const nextUrl = `${url.pathname}${query ? `?${query}` : ''}`;
            window.history.replaceState({}, '', nextUrl);
        },
        openEditUser(payload) {
            this.editUser = {
                id: payload.id ?? '',
                name: payload.name ?? '',
                email: payload.email ?? '',
                role: payload.role ?? 'admin',
                account_id: payload.account_id ?? '',
            };
            this.activeTab = 'users';
            this.showEditUserModal = true;

            document.body.classList.add('overflow-hidden');
        },
        closeEditUserModal() {
            this.showEditUserModal = false;
            document.body.classList.remove('overflow-hidden');
        },
        buildUserUpdateUrl(id) {
            if (!id) {
                return '#';
            }

            return `${document.body.dataset.masterDataUsersBaseUrl}/${id}`;
        },
    };
};

window.toggleCatEdit = function toggleCatEdit(id) {
    document.querySelectorAll(`.cat-display-${id}`).forEach((element) => element.classList.toggle('hidden'));
    document.querySelectorAll(`.cat-edit-${id}`).forEach((element) => element.classList.toggle('hidden'));
};

window.toggleStatusEdit = function toggleStatusEdit(id) {
    document.querySelectorAll(`.status-display-${id}`).forEach((element) => element.classList.toggle('hidden'));
    document.querySelectorAll(`.status-edit-${id}`).forEach((element) => element.classList.toggle('hidden'));
};

window.promptResetPassword = function promptResetPassword(userId, userName) {
    Swal.fire({
        title: 'Reset Password',
        text: `Masukkan password baru untuk ${userName}`,
        input: 'password',
        inputAttributes: {
            autocapitalize: 'off',
            autocorrect: 'off',
        },
        showCancelButton: true,
        confirmButtonText: 'Simpan Password',
        cancelButtonText: 'Batal',
        showLoaderOnConfirm: true,
        customClass: {
            popup: 'rounded-2xl shadow-xl',
            input: 'bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20',
            confirmButton: 'bg-primary rounded-xl px-6 py-2.5 text-sm font-bold',
            cancelButton: 'bg-outline-variant/30 rounded-xl px-6 py-2.5 text-sm font-bold',
        },
        preConfirm: (newPassword) => {
            if (!newPassword || newPassword.length < 8) {
                Swal.showValidationMessage('Password minimal 8 karakter');
                return false;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `/master-data/users/${userId}/reset-password`;
            const params = new URLSearchParams(window.location.search);

            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = '_token';
            csrf.value = document.querySelector('meta[name="csrf-token"]').content;

            const method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            method.value = 'PUT';

            const passInput = document.createElement('input');
            passInput.type = 'hidden';
            passInput.name = 'password';
            passInput.value = newPassword;

            const appendStateInput = (name, value) => {
                if (!value && value !== '0') {
                    return;
                }

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            };

            form.append(csrf, method, passInput);
            appendStateInput('tab', 'users');
            appendStateInput('search_user', params.get('search_user') ?? '');
            appendStateInput('users_page', params.get('users_page') ?? '');
            document.body.appendChild(form);
            form.submit();
            return true;
        },
    });
};

window.loginPage = function loginPage(config) {
    return {
        showBugModal: false,
        showForgotPasswordModal: false,
        bugMessage: '',
        bugError: '',
        forgotPasswordAdminName: '',
        forgotPasswordAccountName: '',
        forgotPasswordError: '',
        waNumber: config.waNumber,
        activeSlide: 0,
        autoSlideMs: Number(config.autoSlideMs ?? 4500),
        sliderTimer: null,
        startSlider() {
            this.pauseSlider();
            this.sliderTimer = window.setInterval(() => {
                this.nextSlide();
            }, this.autoSlideMs);
        },
        pauseSlider() {
            if (!this.sliderTimer) {
                return;
            }

            window.clearInterval(this.sliderTimer);
            this.sliderTimer = null;
        },
        nextSlide() {
            this.activeSlide = (this.activeSlide + 1) % 3;
        },
        setSlide(index) {
            this.activeSlide = Number(index) || 0;
            this.startSlider();
        },
        submitBugReport() {
            if (this.bugMessage.trim() === '') {
                this.bugError = 'Isi pesan keluhan terlebih dahulu!';
                return;
            }

            this.bugError = '';

            const text = encodeURIComponent(
                `Halo Tim Database, saya ingin melaporkan bug/error di aplikasi E-REPORT:\n\n${this.bugMessage}`
            );
            window.open(`https://api.whatsapp.com/send?phone=${this.waNumber}&text=${text}`, '_blank');
            this.showBugModal = false;
            this.bugMessage = '';
        },
        submitForgotPasswordRequest() {
            if (this.forgotPasswordAdminName.trim() === '') {
                this.forgotPasswordError = 'Nama admin wajib diisi!';
                return;
            }

            if (this.forgotPasswordAccountName.trim() === '') {
                this.forgotPasswordError = 'Nama akun yang dipegang wajib diisi!';
                return;
            }

            this.forgotPasswordError = '';

            const text = encodeURIComponent(
                `Halo Tim Database, saya ingin meminta bantuan reset password E-REPORT.\n\nNama Admin: ${this.forgotPasswordAdminName}\nNama Akun yang Dipegang: ${this.forgotPasswordAccountName}`
            );

            window.open(`https://api.whatsapp.com/send?phone=${this.waNumber}&text=${text}`, '_blank');
            this.showForgotPasswordModal = false;
            this.forgotPasswordAdminName = '';
            this.forgotPasswordAccountName = '';
        },
    };
};

window.settingsPage = function settingsPage(initialColor) {
    const normalizeHex = (value) => {
        const hex = String(value ?? '')
            .trim()
            .toUpperCase();

        return /^#[0-9A-F]{6}$/.test(hex) ? hex : '#D97706';
    };

    const hexToRgb = (hex) => [
        parseInt(hex.slice(1, 3), 16),
        parseInt(hex.slice(3, 5), 16),
        parseInt(hex.slice(5, 7), 16),
    ];

    const mix = (base, target, amount) =>
        base.map((channel, index) => Math.round(channel * (1 - amount) + target[index] * amount));

    const contrastColor = (rgb) => {
        const luminance = (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) / 255;
        return luminance > 0.62 ? [43, 52, 55] : [255, 255, 255];
    };

    const toRgbString = (rgb) => rgb.join(' ');

    return {
        themeColor: normalizeHex(initialColor),
        presets: ['#D97706', '#C2410C', '#0F766E', '#2563EB', '#BE185D', '#4F46E5'],
        applyPreset(color) {
            this.themeColor = normalizeHex(color);
        },
        previewVariables() {
            const color = normalizeHex(this.themeColor);
            const primary = hexToRgb(color);

            return [
                `--color-primary-rgb: ${toRgbString(primary)}`,
                `--color-primary-dim-rgb: ${toRgbString(mix(primary, [0, 0, 0], 0.18))}`,
                `--color-primary-container-rgb: ${toRgbString(mix(primary, [255, 255, 255], 0.82))}`,
                `--color-primary-fixed-rgb: ${toRgbString(mix(primary, [255, 255, 255], 0.82))}`,
                `--color-primary-fixed-dim-rgb: ${toRgbString(mix(primary, [255, 255, 255], 0.68))}`,
                `--color-on-primary-rgb: ${toRgbString(contrastColor(primary))}`,
                `--color-on-primary-container-rgb: ${toRgbString(mix(primary, [0, 0, 0], 0.6))}`,
                `--color-on-primary-fixed-rgb: ${toRgbString(mix(primary, [0, 0, 0], 0.72))}`,
                `--color-on-primary-fixed-variant-rgb: ${toRgbString(mix(primary, [0, 0, 0], 0.24))}`,
                `--color-inverse-primary-rgb: ${toRgbString(mix(primary, [255, 255, 255], 0.45))}`,
                `--color-surface-tint-rgb: ${toRgbString(primary)}`,
                `--color-primary-hex: ${color}`,
            ].join('; ');
        },
    };
};

Alpine.start();
window.initDatePickers();
