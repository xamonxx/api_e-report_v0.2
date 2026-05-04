import Alpine from 'alpinejs';

window.Alpine = Alpine;

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

window.loginPage = function loginPage(config) {
    return {
        ...createThemeController(),
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
            if (!window.matchMedia('(min-width: 1280px)').matches) {
                this.pauseSlider();
                return;
            }

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

Alpine.start();
