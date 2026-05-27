/**
 * WordPress User Audit & Cleanup — Admin JS
 *
 * Vanilla ES module handling tabs, AJAX forms, and dynamic UI.
 * No build step required.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.3.0
 */

/* global wuacData */

(function () {
    'use strict';

    const { ajaxUrl, nonce } = wuacData;

    // ── Helpers ──────────────────────────────────────────────────────

    function post(action, data = {}) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', nonce);
        Object.entries(data).forEach(([k, v]) => {
            if (Array.isArray(v)) {
                v.forEach(item => body.append(k + '[]', item));
            } else {
                body.append(k, v);
            }
        });
        return fetch(ajaxUrl, { method: 'POST', body, credentials: 'same-origin' })
            .then(r => r.json());
    }

    function toast(msg, type = 'success') {
        const el = document.getElementById('wuac-toast');
        el.textContent = msg;
        el.className = 'wuac-toast wuac-toast--' + type;
        el.hidden = false;
        clearTimeout(el._timer);
        el._timer = setTimeout(() => { el.hidden = true; }, 5000);
    }

    function setLoading(btn, loading) {
        btn.disabled = loading;
        btn.classList.toggle('wuac-loading', loading);
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // ── Tabs ─────────────────────────────────────────────────────────

    function initTabs() {
        const tabs = document.querySelectorAll('.wuac-tab');
        const panels = document.querySelectorAll('.wuac-panel');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;

                tabs.forEach(t => {
                    t.classList.toggle('wuac-tab--active', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });

                panels.forEach(p => {
                    p.hidden = p.dataset.tab !== target;
                });

                // Update URL hash without scroll
                history.replaceState(null, '', '#' + target);
            });
        });

        // Restore tab from URL hash
        const hash = location.hash.replace('#', '');
        if (hash) {
            const tab = document.querySelector('.wuac-tab[data-tab="' + hash + '"]');
            if (tab) tab.click();
        }
    }

    // ── Email Lookup ─────────────────────────────────────────────────

    function initEmailLookup() {
        const btn = document.getElementById('wuac-lookup-btn');
        const textarea = document.getElementById('wuac-email-list');
        const resultsWrap = document.getElementById('wuac-lookup-results');

        btn.addEventListener('click', async () => {
            setLoading(btn, true);
            resultsWrap.hidden = true;

            const res = await post('wuac_email_lookup', { emails: textarea.value });
            setLoading(btn, false);

            if (!res.success) {
                toast(res.data.message, 'error');
                return;
            }

            const { matched, unmatched_count, patterns_used } = res.data;
            let html = '<div class="wuac-card">';
            html += '<div class="wuac-results-summary">';
            html += '<span class="wuac-stat wuac-stat--matched">' + matched.length + ' matched</span>';
            if (unmatched_count > 0) {
                html += '<span class="wuac-stat wuac-stat--unmatched">' + unmatched_count + ' unmatched</span>';
            }
            if (patterns_used > 0) {
                html += '<span class="wuac-stat wuac-stat--pattern">' + patterns_used + ' pattern(s) used</span>';
            }
            html += '</div>';

            if (matched.length > 0) {
                html += '<table class="wp-list-table widefat fixed striped users">';
                html += '<thead><tr>';
                html += '<td class="manage-column column-cb check-column"><input type="checkbox" id="wuac-select-all" /></td>';
                html += '<th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Score</th><th>Registered</th>';
                html += '</tr></thead><tbody>';
                matched.forEach(u => {
                    const scoreLevel = u.spam_score >= 70 ? 'high' : (u.spam_score >= 40 ? 'medium' : 'low');
                    html += '<tr>';
                    html += '<th class="check-column"><input type="checkbox" class="wuac-user-cb" value="' + esc(u.ID) + '" /></th>';
                    html += '<td>' + esc(u.ID) + '</td>';
                    html += '<td>' + esc(u.user_login) + '</td>';
                    html += '<td>' + esc(u.user_email) + '</td>';
                    html += '<td><span class="wuac-role-badge">' + esc(u.role || 'none') + '</span></td>';
                    html += '<td><span class="wuac-score wuac-score--' + scoreLevel + '">' + (u.spam_score || 0) + '</span></td>';
                    html += '<td>' + esc(u.user_registered) + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                html += '<button type="button" id="wuac-delete-selected-btn" class="button button-link-delete" style="margin-top:12px">Delete Selected</button>';
            }

            html += '</div>';
            resultsWrap.innerHTML = html;
            resultsWrap.hidden = false;

            // Select all checkbox
            const selectAll = document.getElementById('wuac-select-all');
            if (selectAll) {
                selectAll.addEventListener('change', () => {
                    document.querySelectorAll('.wuac-user-cb').forEach(cb => { cb.checked = selectAll.checked; });
                });
            }

            // Delete selected
            const deleteBtn = document.getElementById('wuac-delete-selected-btn');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', async () => {
                    const ids = [...document.querySelectorAll('.wuac-user-cb:checked')].map(cb => cb.value);
                    if (!ids.length) { toast('No users selected.', 'error'); return; }
                    if (!confirm('Delete ' + ids.length + ' user(s)? This cannot be undone.')) return;

                    setLoading(deleteBtn, true);
                    const delRes = await post('wuac_delete_users', { user_ids: ids });
                    setLoading(deleteBtn, false);

                    if (delRes.success) {
                        toast(delRes.data.message);
                        // Re-run lookup to refresh results
                        btn.click();
                    } else {
                        toast(delRes.data.message, 'error');
                    }
                });
            }
        });
    }

    // ── Inactive Cleanup ─────────────────────────────────────────────

    function initInactiveCleanup() {
        const findBtn = document.getElementById('wuac-find-inactive-btn');
        const daysInput = document.getElementById('wuac-inactive-days');
        const roleSelect = document.getElementById('wuac-inactive-role');
        const typeSelect = document.getElementById('wuac-inactive-type');
        const resultsWrap = document.getElementById('wuac-inactive-results');

        findBtn.addEventListener('click', async () => {
            setLoading(findBtn, true);
            resultsWrap.hidden = true;

            const res = await post('wuac_find_inactive', {
                days: daysInput.value,
                role: roleSelect ? roleSelect.value : 'all',
                type: typeSelect ? typeSelect.value : 'both'
            });
            setLoading(findBtn, false);

            if (!res.success) {
                toast(res.data.message, 'error');
                return;
            }

            const { users, count } = res.data;
            let html = '<div class="wuac-card">';
            html += '<h3>Found ' + count + ' inactive user(s)</h3>';

            if (count > 0) {
                html += '<table class="wp-list-table widefat fixed striped users">';
                html += '<thead><tr>';
                html += '<td class="manage-column column-cb check-column" style="width: 2.2em;"><input type="checkbox" id="wuac-inactive-select-all" /></td>';
                html += '<th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Registered</th>';
                html += '</tr></thead><tbody>';
                users.forEach(u => {
                    html += '<tr>';
                    html += '<th class="check-column"><input type="checkbox" class="wuac-inactive-cb" value="' + esc(u.ID) + '" /></th>';
                    html += '<td>' + esc(u.ID) + '</td>';
                    html += '<td>' + esc(u.user_login) + '</td>';
                    html += '<td>' + esc(u.user_email) + '</td>';
                    html += '<td><span class="wuac-role-badge">' + esc(u.role || 'none') + '</span></td>';
                    html += '<td>' + esc(u.user_registered) + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                html += '<button type="button" id="wuac-delete-inactive-selected-btn" class="button button-link-delete" style="margin-top:12px">Delete Selected</button>';
            }

            html += '</div>';
            resultsWrap.innerHTML = html;
            resultsWrap.hidden = false;

            // Handle Select All
            const selectAll = document.getElementById('wuac-inactive-select-all');
            if (selectAll) {
                selectAll.addEventListener('change', () => {
                    document.querySelectorAll('.wuac-inactive-cb').forEach(cb => {
                        cb.checked = selectAll.checked;
                    });
                });
            }

            const deleteBtn = document.getElementById('wuac-delete-inactive-selected-btn');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', async () => {
                    const selectedIds = Array.from(document.querySelectorAll('.wuac-inactive-cb:checked')).map(cb => cb.value);
                    
                    if (selectedIds.length === 0) {
                        toast('No users selected.', 'error');
                        return;
                    }

                    if (!confirm('Delete ' + selectedIds.length + ' selected inactive user(s)? This cannot be undone.')) {
                        return;
                    }

                    setLoading(deleteBtn, true);
                    const delRes = await post('wuac_delete_users', { user_ids: selectedIds });
                    setLoading(deleteBtn, false);

                    if (delRes.success) {
                        toast(delRes.data.message);
                        // Refresh the search list
                        findBtn.click();
                    } else {
                        toast(delRes.data.message, 'error');
                    }
                });
            }
        });
    }

    // ── Spam Cleanup (High Risk) ──────────────────────────────────────

    function initSpamCleanup() {
        const findBtn = document.getElementById('wuac-find-spam-btn');
        const resultsWrap = document.getElementById('wuac-spam-cleanup-results');

        if (!findBtn) return;

        findBtn.addEventListener('click', async () => {
            setLoading(findBtn, true);
            resultsWrap.hidden = true;

            const res = await post('wuac_find_high_risk');
            setLoading(findBtn, false);

            if (!res.success) {
                toast(res.data.message, 'error');
                return;
            }

            const { users, count } = res.data;
            let html = '<div class="wuac-card">';
            html += '<h3>Found ' + count + ' high risk user(s) (Score ≥ 70)</h3>';

            if (count > 0) {
                html += '<table class="wp-list-table widefat fixed striped users">';
                html += '<thead><tr>';
                html += '<td class="manage-column column-cb check-column" style="width: 2.2em;"><input type="checkbox" id="wuac-spam-select-all" /></td>';
                html += '<th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Score</th><th>Registered</th>';
                html += '</tr></thead><tbody>';
                users.forEach(u => {
                    html += '<tr>';
                    html += '<th class="check-column"><input type="checkbox" class="wuac-spam-user-cb" value="' + esc(u.ID) + '" /></th>';
                    html += '<td>' + esc(u.ID) + '</td>';
                    html += '<td>' + esc(u.user_login) + '</td>';
                    html += '<td>' + esc(u.user_email) + '</td>';
                    html += '<td><span class="wuac-role-badge">' + esc(u.role || 'none') + '</span></td>';
                    html += '<td><span class="wuac-score wuac-score--high">' + esc(u.spam_score || 0) + '</span></td>';
                    html += '<td>' + esc(u.user_registered) + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                html += '<div style="margin-top:12px; display:flex; gap:8px;">';
                html += '<button type="button" id="wuac-delete-spam-selected-btn" class="button button-link-delete">Delete Selected</button>';
                html += '<button type="button" id="wuac-delete-spam-all-btn" class="button button-secondary">Delete All ' + count + ' High Risk Users</button>';
                html += '</div>';
            }

            html += '</div>';
            resultsWrap.innerHTML = html;
            resultsWrap.hidden = false;

            // Select all checkbox
            const selectAll = document.getElementById('wuac-spam-select-all');
            if (selectAll) {
                selectAll.addEventListener('change', () => {
                    document.querySelectorAll('.wuac-spam-user-cb').forEach(cb => { cb.checked = selectAll.checked; });
                });
            }

            // Delete selected
            const deleteSelectedBtn = document.getElementById('wuac-delete-spam-selected-btn');
            if (deleteSelectedBtn) {
                deleteSelectedBtn.addEventListener('click', async () => {
                    const ids = [...document.querySelectorAll('.wuac-spam-user-cb:checked')].map(cb => cb.value);
                    if (!ids.length) { toast('No users selected.', 'error'); return; }
                    if (!confirm('Delete ' + ids.length + ' user(s)? This cannot be undone.')) return;

                    setLoading(deleteSelectedBtn, true);
                    const delRes = await post('wuac_delete_users', { user_ids: ids });
                    setLoading(deleteSelectedBtn, false);

                    if (delRes.success) {
                        toast(delRes.data.message);
                        findBtn.click();
                    } else {
                        toast(delRes.data.message, 'error');
                    }
                });
            }

            // Delete all
            const deleteAllBtn = document.getElementById('wuac-delete-spam-all-btn');
            if (deleteAllBtn) {
                deleteAllBtn.addEventListener('click', async () => {
                    const ids = users.map(u => u.ID);
                    if (!confirm('Delete all ' + ids.length + ' high risk user(s)? This cannot be undone.')) return;

                    setLoading(deleteAllBtn, true);
                    const delRes = await post('wuac_delete_users', { user_ids: ids });
                    setLoading(deleteAllBtn, false);

                    if (delRes.success) {
                        toast(delRes.data.message);
                        findBtn.click();
                    } else {
                        toast(delRes.data.message, 'error');
                    }
                });
            }
        });
    }

    // ── Settings: Domain Management ──────────────────────────────────

    function initDomainManagement() {
        const addBtn = document.getElementById('wuac-add-domain-btn');
        const input = document.getElementById('wuac-new-domain');
        const listWrap = document.getElementById('wuac-domain-list-wrap');

        function renderDomains(domains) {
            if (!domains.length) {
                listWrap.innerHTML = '<p><em>No disposable domains configured.</em></p>';
                return;
            }

            let html = '<p>Total domains: ' + domains.length + '</p>';
            html += '<div class="wuac-domain-list-container">';
            html += '<table class="widefat striped"><thead><tr>';
            html += '<th>Domain</th><th style="width:100px">Action</th>';
            html += '</tr></thead><tbody>';
            domains.forEach(d => {
                html += '<tr><td>' + esc(d) + '</td>';
                html += '<td><button type="button" class="button button-small wuac-remove-domain-btn" data-domain="' + esc(d) + '">Remove</button></td></tr>';
            });
            html += '</tbody></table></div>';
            listWrap.innerHTML = html;

            // Bind remove buttons
            listWrap.querySelectorAll('.wuac-remove-domain-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const domain = btn.dataset.domain;
                    setLoading(btn, true);
                    const res = await post('wuac_remove_domain', { domain });
                    if (res.success) {
                        toast(res.data.message);
                        renderDomains(res.data.domains);
                    } else {
                        toast(res.data.message, 'error');
                        setLoading(btn, false);
                    }
                });
            });
        }

        // Load domains on settings tab first view
        async function loadDomains() {
            const res = await post('wuac_get_domains');
            if (res.success) renderDomains(res.data.domains);
        }

        addBtn.addEventListener('click', async () => {
            const domain = input.value.trim();
            if (!domain) return;

            setLoading(addBtn, true);
            const res = await post('wuac_add_domain', { domain });
            setLoading(addBtn, false);

            if (res.success) {
                toast(res.data.message);
                input.value = '';
                renderDomains(res.data.domains);
            } else {
                toast(res.data.message, 'error');
            }
        });

        // Enter key support
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); addBtn.click(); }
        });

        // Load on tab switch
        let loaded = false;
        document.querySelector('.wuac-tab[data-tab="settings"]').addEventListener('click', () => {
            if (!loaded) { loaded = true; loadDomains(); }
        });

        // If settings tab is active on load (via hash)
        if (location.hash === '#settings') { loaded = true; loadDomains(); }
    }

    // ── Settings: Scan & Erase ───────────────────────────────────────

    function initScanAndErase() {
        const scanBtn = document.getElementById('wuac-scan-btn');
        const eraseBtn = document.getElementById('wuac-erase-btn');

        scanBtn.addEventListener('click', async () => {
            if (!confirm('This will scan all users, backfill login data, and auto-flag high-risk accounts. Continue?')) return;

            setLoading(scanBtn, true);
            const res = await post('wuac_scan_users');
            setLoading(scanBtn, false);

            if (res.success) {
                toast(res.data.message);
            } else {
                toast(res.data.message, 'error');
            }
        });

        eraseBtn.addEventListener('click', async () => {
            if (!confirm('This will permanently remove ALL plugin data. This cannot be undone. Continue?')) return;

            setLoading(eraseBtn, true);
            const res = await post('wuac_erase_data');
            setLoading(eraseBtn, false);

            if (res.success) {
                toast(res.data.message);
            } else {
                toast(res.data.message, 'error');
            }
        });
    }

    // ── Init ─────────────────────────────────────────────────────────

    function init() {
        if (!document.getElementById('wuac-app')) return;

        initTabs();
        initEmailLookup();
        initInactiveCleanup();
        initSpamCleanup();
        initDomainManagement();
        initScanAndErase();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
