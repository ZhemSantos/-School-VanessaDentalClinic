/**
 * Notification System
 * Handles polling, counting, and displaying notifications in a Modal Window.
 */

document.addEventListener('DOMContentLoaded', function () {
    const notificationBtn = document.querySelector('.btn-notifications') || document.querySelector('button[aria-label="Notifications"]');
    const notificationBadge = notificationBtn?.querySelector('.badge-dot');

    // Modal HTML Structure
    const modalHTML = `
        <div id="notification-modal" class="fixed inset-0 z-50 hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <!-- Backdrop -->
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" id="modal-backdrop"></div>

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <!-- Modal Panel -->
                    <div class="relative transform overflow-hidden rounded-2xl bg-white dark:bg-[#2d1b36] text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg border border-gray-100 dark:border-gray-700">
                        
                        <!-- Header -->
                        <div class="border-b border-gray-100 dark:border-gray-700 px-4 py-3 sm:px-6 flex justify-between items-center bg-white dark:bg-gray-800/50">
                            <h3 class="text-lg font-bold leading-6 text-text-main dark:text-white" id="modal-title">Notifications</h3>
                            <div class="flex gap-2">
                                <button id="mark-all-read-btn" class="text-xs font-bold text-primary hover:text-primary/80 transition-colors px-2 py-1 bg-primary/10 rounded-lg">Mark all read</button>
                                <button type="button" class="text-gray-400 hover:text-gray-500 focus:outline-none" id="close-modal-btn">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="px-4 py-2 border-b border-gray-100 dark:border-gray-700 flex gap-2 overflow-x-auto no-scrollbar">
                            <button class="filter-btn active px-3 py-1.5 rounded-full text-xs font-bold bg-primary text-white transition-all" data-filter="all">All</button>
                            <button class="filter-btn px-3 py-1.5 rounded-full text-xs font-bold text-text-sub hover:bg-gray-100 dark:hover:bg-gray-700 transition-all" data-filter="unread">Unread</button>
                            <button class="filter-btn px-3 py-1.5 rounded-full text-xs font-bold text-text-sub hover:bg-gray-100 dark:hover:bg-gray-700 transition-all" data-type="Appointment">Appointments</button>
                            <button class="filter-btn px-3 py-1.5 rounded-full text-xs font-bold text-text-sub hover:bg-gray-100 dark:hover:bg-gray-700 transition-all" data-type="System">System</button>
                        </div>

                        <!-- Content List -->
                        <div id="notification-list-content" class="max-h-[60vh] overflow-y-auto p-0">
                            <div class="flex flex-col items-center justify-center py-12 text-center text-text-sub">
                                <span class="material-symbols-outlined text-4xl mb-2 opacity-50">sync</span>
                                <p>Loading notifications...</p>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="bg-gray-50 dark:bg-gray-800/30 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-gray-100 dark:border-gray-700">
                             <button id="delete-all-btn" class="text-xs text-red-500 font-bold hover:text-red-700 transition-colors flex items-center gap-1">
                                <span class="material-symbols-outlined text-[16px]">delete</span> Clear My Notifications
                             </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Inject Modal
    document.body.insertAdjacentHTML('beforeend', modalHTML);

    const modal = document.getElementById('notification-modal');
    const backdrop = document.getElementById('modal-backdrop');
    const closeBtn = document.getElementById('close-modal-btn');
    const listContent = document.getElementById('notification-list-content');
    let currentFilter = 'all';
    let currentType = 'all';

    // Toggle Modal
    if (notificationBtn) {
        // Ensure parent is relative for badge positioning if needed, but badge logic is separate
        notificationBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openModal();
        });
    }

    [backdrop, closeBtn].forEach(el => {
        el?.addEventListener('click', closeModal);
    });

    // Filter Logic
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            // Reset classes
            document.querySelectorAll('.filter-btn').forEach(b => {
                b.classList.remove('bg-primary', 'text-white');
                b.classList.add('text-text-sub');
            });
            this.classList.remove('text-text-sub');
            this.classList.add('bg-primary', 'text-white');

            // Set State
            if (this.dataset.filter) {
                currentFilter = this.dataset.filter;
                currentType = 'all'; // Reset type if main filter used, or combine logic
            }
            if (this.dataset.type) {
                currentType = this.dataset.type;
                // keep filter as all/unread? Let's simplify: simple tabs
                // If type clicked, we probably imply 'all' status unless we want 'unread appointments'
            }

            fetchNotifications(true);
        });
    });

    // Delete All
    document.getElementById('delete-all-btn')?.addEventListener('click', () => {
        if (confirm('Are you sure you want to clear your personal notifications?')) {
            deleteNotification('all');
        }
    });

    // Mark All Read
    document.getElementById('mark-all-read-btn')?.addEventListener('click', () => {
        markAsRead('all');
    });


    function openModal() {
        modal.classList.remove('hidden');
        fetchNotifications(true); // Force fetch with render
    }

    function closeModal() {
        modal.classList.add('hidden');
    }

    function fetchNotifications(render = false) {
        // Build URL
        const apiPath = '../api/get_notifications.php';
        const url = `${apiPath}?limit=50&filter=${currentFilter}&type=${currentType}`;

        fetch(url)
            .then(response => response.json())
            .catch(() => null)
            .then(data => {
                if (!data) return;

                // Update Badge (Global count)
                if (data.unread_count > 0) {
                    if (notificationBadge) notificationBadge.style.display = 'block';
                    else if (notificationBtn) {
                        // Create badge if missing
                        const badge = document.createElement('span');
                        badge.className = 'absolute top-2 right-2.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-gray-700 badge-dot z-10';
                        notificationBtn.appendChild(badge);
                    }
                } else {
                    if (notificationBadge) notificationBadge.style.display = 'none';
                    const existingBadge = notificationBtn?.querySelector('.badge-dot');
                    if (existingBadge) existingBadge.style.display = 'none';
                }

                if (render) {
                    renderList(data.notifications);
                }
            });
    }

    function renderList(notifications) {
        if (!notifications || notifications.length === 0) {
            listContent.innerHTML = `
                <div class="flex flex-col items-center justify-center py-12 text-center text-text-sub opacity-70">
                    <span class="material-symbols-outlined text-4xl mb-2">notifications_off</span>
                    <p class="text-sm">No notifications found.</p>
                </div>
            `;
            return;
        }

        let html = '';
        notifications.forEach(notif => {
            const isUnread = notif.is_read == 0;
            const bgClass = isUnread ? 'bg-primary/5 dark:bg-primary/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/30';
            const iconConfig = getIconConfig(notif.type);
            const userCanDelete = notif.user_id !== null; // Can only delete personal notifs in this basic setup

            html += `
                <div class="group relative p-4 border-b border-gray-100 dark:border-gray-800 ${bgClass} transition-colors">
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full ${iconConfig.bg} flex items-center justify-center">
                            <span class="material-symbols-outlined ${iconConfig.text} text-[20px]">${iconConfig.icon}</span>
                        </div>
                        <div class="flex-1 min-w-0 pr-8 cursor-pointer" onclick="handleNotificationClick(${notif.notification_id}, '${notif.link}')">
                            <p class="text-xs font-bold text-text-main dark:text-white mb-0.5">${notif.title}</p>
                            <p class="text-xs text-text-sub leading-snug">${notif.message}</p>
                            <p class="text-[10px] text-text-sub/70 mt-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-[10px]">schedule</span>
                                ${notif.time_ago}
                            </p>
                        </div>
                        
                        <!-- Actions -->
                        <div class="absolute right-4 top-4 flex flex-col items-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                            ${userCanDelete ? `
                            <button onclick="deleteNotification('single', ${notif.notification_id})" class="text-gray-400 hover:text-red-500 transition-colors" title="Delete">
                                <span class="material-symbols-outlined text-[18px]">delete</span>
                            </button>` : ''}
                        </div>
                         ${isUnread ? '<div class="absolute right-4 top-1/2 -translate-y-1/2 w-2 h-2 rounded-full bg-primary flex-shrink-0"></div>' : ''}
                    </div>
                </div>
            `;
        });
        listContent.innerHTML = html;
    }

    function getIconConfig(type) {
        switch (type) {
            case 'Appointment': return { bg: 'bg-blue-50 dark:bg-blue-900/20', text: 'text-blue-600', icon: 'calendar_month' };
            case 'Payment': return { bg: 'bg-green-50 dark:bg-green-900/20', text: 'text-green-600', icon: 'payments' };
            case 'Inventory': return { bg: 'bg-amber-50 dark:bg-amber-900/20', text: 'text-amber-600', icon: 'inventory_2' };
            default: return { bg: 'bg-purple-50 dark:bg-purple-900/20', text: 'text-primary', icon: 'notifications' };
        }
    }

    window.handleNotificationClick = function (id, link) {
        markAsRead('single', id).then(() => {
            if (link && link !== '#' && link !== 'null') window.location.href = link;
            else fetchNotifications(true);
        });
    }

    window.deleteNotification = function (action, id) {
        fetch('../api/delete_notification.php', {
            method: 'POST',
            body: JSON.stringify({ action, notification_id: id })
        })
            .then(res => res.json())
            .then(() => {
                fetchNotifications(true); // Refresh
            });
    }

    function markAsRead(action, id = null) {
        return fetch('../api/mark_notification_read.php', {
            method: 'POST',
            body: JSON.stringify({ action, notification_id: id })
        })
            .then(res => res.json())
            .then(() => {
                fetchNotifications(true);
            });
    }

    // Initial Poll
    if (notificationBtn) {
        fetchNotifications(false);
        setInterval(() => fetchNotifications(false), 30000);
    }
});
