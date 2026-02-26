/**
 * Session Activity Tracker
 * Detects user activity dan update server untuk keep session active
 */

(function() {
    'use strict';

    const ActivityTracker = {
        // Configuration
        config: {
            updateUrl: '/reksorad/api/update-activity.php',
            updateInterval: 300000, // 5 menit = 300000 ms
            inactivityTimeout: 3600000, // 1 jam = 3600000 ms
            warningTime: 3300000, // Warning 5 menit sebelum logout (55 menit)
        },

        // State
        state: {
            lastUpdateTime: Date.now(),
            warningShown: false,
            updateIntervalId: null,
            warningIntervalId: null,
        },

        /**
         * Initialize activity tracker
         */
        init() {
            this.attachActivityListeners();
            this.startPeriodicUpdate();
            this.startInactivityWarning();
        },

        /**
         * Attach event listeners untuk activity detection
         */
        attachActivityListeners() {
            const events = ['mousedown', 'keypress', 'scroll', 'touchstart', 'click'];

            events.forEach(event => {
                document.addEventListener(event, () => {
                    this.onUserActivity();
                }, false);
            });
        },

        /**
         * Handle user activity
         */
        onUserActivity() {
            const now = Date.now();
            const timeSinceLastUpdate = now - this.state.lastUpdateTime;

            // Update server setiap 5 menit atau saat ada activity setelah warning
            if (timeSinceLastUpdate > 60000) { // 1 menit
                this.updateActivityServer();
            }

            // Reset warning flag jika user kembali aktif
            if (this.state.warningShown) {
                this.hideInactivityWarning();
                this.state.warningShown = false;
            }
        },

        /**
         * Update activity di server via AJAX
         */
        updateActivityServer() {
            fetch(this.config.updateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin' // Include cookies
            })
            .then(response => {
                if (response.ok) {
                    this.state.lastUpdateTime = Date.now();
                    console.log('[ActivityTracker] Activity updated at', new Date().toLocaleTimeString());
                }
            })
            .catch(error => {
                console.warn('[ActivityTracker] Failed to update activity:', error);
            });
        },

        /**
         * Start periodic update setiap 5 menit
         */
        startPeriodicUpdate() {
            this.state.updateIntervalId = setInterval(() => {
                this.updateActivityServer();
            }, this.config.updateInterval);
        },

        /**
         * Start inactivity warning
         */
        startInactivityWarning() {
            this.state.warningIntervalId = setInterval(() => {
                const now = Date.now();
                const timeSinceLastUpdate = now - this.state.lastUpdateTime;

                // Tampilkan warning 5 menit sebelum logout (jika sudah 55 menit tanpa activity)
                if (timeSinceLastUpdate > this.config.warningTime && !this.state.warningShown) {
                    this.showInactivityWarning();
                    this.state.warningShown = true;
                }
            }, 10000); // Check setiap 10 detik
        },

        /**
         * Tampilkan warning modal untuk inactivity
         */
        showInactivityWarning() {
            const warningHTML = `
                <div id="inactivityWarningModal" class="modal fade" tabindex="-1" role="dialog"
                     aria-labelledby="inactivityWarningTitle" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header bg-warning text-dark border-warning">
                                <h5 class="modal-title" id="inactivityWarningTitle">
                                    <i class="fas fa-clock"></i> Sesi Akan Berakhir
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="mb-2">
                                    <strong>Sesi Anda akan berakhir dalam 5 menit</strong> karena tidak ada aktivitas.
                                </p>
                                <p class="text-muted mb-0">
                                    Klik tombol "Lanjutkan Sesi" untuk melanjutkan bekerja atau Anda akan secara otomatis logout.
                                </p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-door-open"></i> Logout
                                </button>
                                <button type="button" class="btn btn-primary" id="extendSessionBtn">
                                    <i class="fas fa-sync-alt"></i> Lanjutkan Sesi
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Inject modal ke DOM jika belum ada
            if (!document.getElementById('inactivityWarningModal')) {
                document.body.insertAdjacentHTML('beforeend', warningHTML);
            }

            // Show modal menggunakan Bootstrap
            const modal = new (window.bootstrap ? window.bootstrap.Modal : BootstrapModal)(
                document.getElementById('inactivityWarningModal'),
                {
                    backdrop: 'static',
                    keyboard: false
                }
            );
            modal.show();

            // Handler untuk extend session button
            const extendBtn = document.getElementById('extendSessionBtn');
            if (extendBtn) {
                extendBtn.onclick = () => {
                    this.updateActivityServer();
                    modal.hide();
                    this.state.warningShown = false;
                    console.log('[ActivityTracker] Session extended by user');
                };
            }

            // Handler untuk logout button
            const logoutBtn = document.querySelector('#inactivityWarningModal .btn-secondary');
            if (logoutBtn) {
                logoutBtn.onclick = () => {
                    window.location.href = '/reksorad/logout.php';
                };
            }
        },

        /**
         * Hide inactivity warning modal
         */
        hideInactivityWarning() {
            const modal = document.getElementById('inactivityWarningModal');
            if (modal) {
                try {
                    const bsModal = window.bootstrap?.Modal?.getInstance(modal);
                    if (bsModal) {
                        bsModal.hide();
                    }
                } catch (e) {
                    console.warn('[ActivityTracker] Error hiding modal:', e);
                }
            }
        },

        /**
         * Cleanup - stop tracking
         */
        destroy() {
            if (this.state.updateIntervalId) {
                clearInterval(this.state.updateIntervalId);
            }
            if (this.state.warningIntervalId) {
                clearInterval(this.state.warningIntervalId);
            }
        }
    };

    // Auto-init ketika DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            ActivityTracker.init();
        });
    } else {
        ActivityTracker.init();
    }

    // Expose globally untuk debugging
    window.ActivityTracker = ActivityTracker;
})();
