/**
 * WordPress User Audit & Cleanup — Admin JS
 *
 * Vanilla ES module handling tabs, AJAX forms, sortable tables, CSV export,
 * bulk delete/flag, and dynamic UI. No build step required.
 *
 * @package WP_User_Audit_Cleanup
 * @since   1.3.0
 */

/* global wuacData */

(function () {
    'use strict';

    const { ajaxUrl, nonce, adminUrl } = wuacData;

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

    function updateTabCount(tabName, count) {
        const tab = document.querySelector('.wuac-tab[data-tab="' + tabName + '"]');
        if (!tab) return;
        
        let baseText = tab.dataset.baseText;
        if (!baseText) {
            baseText = tab.textContent.trim();
            tab.dataset.baseText = baseText;
        }
        
        if (count > 0) {
            tab.textContent = baseText + ' (' + count + ')';
        } else {
            tab.textContent = baseText;
        }
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
                panels.forEach(p => { p.hidden = p.dataset.tab !== target; });
                history.replaceState(null, '', '#' + target);
            });
        });

        const hash = location.hash.replace('#', '');
        if (hash) {
            const tab = document.querySelector('.wuac-tab[data-tab="' + hash + '"]');
            if (tab) tab.click();
        }
    }

    // ── CSV Export ───────────────────────────────────────────────────

    function downloadCSV(data, columns, filename) {
        const header = columns.map(c => '"' + c.label + '"').join(',');
        const rows = data.map(row =>
            columns.map(c => {
                let val = String(row[c.key] ?? '');
                return '"' + val.replace(/"/g, '""') + '"';
            }).join(',')
        );
        const csv = [header, ...rows].join('\n');
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        URL.revokeObjectURL(url);
    }

    // ── Generic Table Builder ───────────────────────────────────────

    function userLink(login, id) {
        return '<a href="' + esc(adminUrl) + 'user-edit.php?user_id=' + esc(id) + '" target="_blank">' + esc(login) + '</a>';
    }

    function scoreHtml(score) {
        const level = score >= 70 ? 'high' : (score >= 40 ? 'medium' : 'low');
        return '<span class="wuac-score wuac-score--' + level + '">' + esc(score) + '</span>';
    }

    function loginHtml(val) {
        if (!val) return '<span class="wuac-last-login--never">Never</span>';
        return esc(val);
    }

    /**
     * Build a results card with a sortable table, action buttons, and event wiring.
     *
     * @param {Object}   opts
     * @param {Array}    opts.users       - Array of user data objects.
     * @param {Array}    opts.columns     - Column definitions [{key, label, sortType, render}].
     * @param {string}   opts.prefix      - Unique prefix for DOM IDs/classes.
     * @param {string}   opts.heading     - Results heading text.
     * @param {Function} opts.refreshFn   - Function to call after delete/flag to refresh results.
     * @param {Object}   [opts.deleteAll] - {action, params, label} for server-side delete-all.
     */
    function renderResultsCard(container, opts) {
        const { columns, prefix, heading, refreshFn } = opts;
        let { users } = opts; // local copy we can sort

        let currentPage = 1;
        const perPage = 100;
        let sortKey = null;
        let sortAsc = true;
        const selectedIds = new Set();

        function sortData(key, type) {
            users.sort((a, b) => {
                let va = a[key] ?? '';
                let vb = b[key] ?? '';
                if (type === 'num') {
                    va = parseFloat(va) || 0;
                    vb = parseFloat(vb) || 0;
                } else {
                    va = String(va).toLowerCase();
                    vb = String(vb).toLowerCase();
                }
                if (va < vb) return sortAsc ? -1 : 1;
                if (va > vb) return sortAsc ? 1 : -1;
                return 0;
            });
        }

        function render() {
            const count = users.length;
            const totalPages = Math.ceil(count / perPage) || 1;
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const startIdx = (currentPage - 1) * perPage;
            const endIdx = Math.min(startIdx + perPage, count);
            const pageUsers = users.slice(startIdx, endIdx);

            let html = '<div class="wuac-card">';
            html += '<div class="wuac-results-summary">';
            html += '<span class="wuac-stat wuac-stat--matched">' + count + ' ' + heading + '</span>';
            html += '</div>';

            if (count > 0) {
                // Table
                html += '<table class="wp-list-table widefat fixed striped users wuac-sortable">';
                html += '<thead><tr>';
                
                // Select All checkbox on current page
                const allPageChecked = pageUsers.length > 0 && pageUsers.every(u => selectedIds.has(String(u.ID)));
                html += '<td class="manage-column column-cb check-column" style="width:2.2em">' +
                        '<input type="checkbox" id="' + prefix + '-select-all" ' + (allPageChecked ? 'checked' : '') + ' />' +
                        '</td>';

                columns.forEach(c => {
                    const isSorted = sortKey === c.key;
                    const sortClass = isSorted ? (sortAsc ? 'wuac-sort-asc' : 'wuac-sort-desc') : '';
                    const sAttr = c.sortType ? ' data-sort-key="' + c.key + '" data-sort-type="' + c.sortType + '"' : '';
                    
                    html += '<th' + sAttr + ' class="' + (c.sortType ? 'wuac-sortable-th ' : '') + sortClass + '">' + esc(c.label);
                    if (c.sortType) {
                        html += ' <span class="wuac-sort-icon">' + (isSorted ? (sortAsc ? '▲' : '▼') : '⇅') + '</span>';
                    }
                    html += '</th>';
                });
                html += '</tr></thead><tbody>';

                pageUsers.forEach(u => {
                    const isChecked = selectedIds.has(String(u.ID));
                    html += '<tr>';
                    html += '<th class="check-column"><input type="checkbox" class="' + prefix + '-cb" value="' + esc(u.ID) + '" ' + (isChecked ? 'checked' : '') + ' /></th>';
                    columns.forEach(c => {
                        html += '<td>' + (c.render ? c.render(u) : esc(String(u[c.key] ?? ''))) + '</td>';
                    });
                    html += '</tr>';
                });
                html += '</tbody></table>';

                // Pagination Controls
                if (totalPages > 1) {
                    html += '<div class="wuac-pagination">';
                    html += '<span class="wuac-pagination-info">Showing ' + (startIdx + 1) + '-' + endIdx + ' of ' + count + '</span>';
                    html += '<div class="wuac-pagination-buttons">';
                    html += '<button type="button" class="button wuac-page-first" ' + (currentPage === 1 ? 'disabled' : '') + '>&laquo; First</button>';
                    html += '<button type="button" class="button wuac-page-prev" ' + (currentPage === 1 ? 'disabled' : '') + '>&lsaquo; Prev</button>';
                    html += '<span class="wuac-page-current">Page ' + currentPage + ' of ' + totalPages + '</span>';
                    html += '<button type="button" class="button wuac-page-next" ' + (currentPage === totalPages ? 'disabled' : '') + '>Next &rsaquo;</button>';
                    html += '<button type="button" class="button wuac-page-last" ' + (currentPage === totalPages ? 'disabled' : '') + '>Last &raquo;</button>';
                    html += '</div>';
                    html += '</div>';
                }

                // Action bar
                html += '<div class="wuac-action-bar">';
                html += '<button type="button" id="' + prefix + '-delete-btn" class="button button-link-delete">Delete Selected (<span class="wuac-sel-count">' + selectedIds.size + '</span>)</button>';
                html += '<button type="button" id="' + prefix + '-flag-btn" class="button">Flag Selected as Spam (<span class="wuac-sel-count">' + selectedIds.size + '</span>)</button>';
                if (opts.deleteAll) {
                    html += '<button type="button" id="' + prefix + '-delete-all-btn" class="button button-secondary">' + esc(opts.deleteAll.label || ('Delete All ' + count)) + '</button>';
                }
                html += '<button type="button" id="' + prefix + '-csv-btn" class="button">Export CSV</button>';
                html += '</div>';
            }

            html += '</div>';
            container.innerHTML = html;
            container.hidden = false;

            if (count === 0) return;

            // Wire up event listeners
            // Sort headers
            container.querySelectorAll('th[data-sort-key]').forEach(th => {
                th.style.cursor = 'pointer';
                th.addEventListener('click', () => {
                    const key = th.dataset.sortKey;
                    const type = th.dataset.sortType;
                    if (sortKey === key) {
                        sortAsc = !sortAsc;
                    } else {
                        sortKey = key;
                        sortAsc = true;
                    }
                    sortData(key, type);
                    render();
                });
            });

            // Checkbox changes
            const selectAllCb = container.querySelector('#' + prefix + '-select-all');
            const rowCbs = container.querySelectorAll('.' + prefix + '-cb');

            selectAllCb.addEventListener('change', () => {
                pageUsers.forEach(u => {
                    if (selectAllCb.checked) {
                        selectedIds.add(String(u.ID));
                    } else {
                        selectedIds.delete(String(u.ID));
                    }
                });
                render();
            });

            rowCbs.forEach(cb => {
                cb.addEventListener('change', () => {
                    if (cb.checked) {
                        selectedIds.add(cb.value);
                    } else {
                        selectedIds.delete(cb.value);
                    }
                    render();
                });
            });

            // Pagination button events
            if (totalPages > 1) {
                container.querySelector('.wuac-page-first').addEventListener('click', () => {
                    currentPage = 1;
                    render();
                });
                container.querySelector('.wuac-page-prev').addEventListener('click', () => {
                    if (currentPage > 1) {
                        currentPage--;
                        render();
                    }
                });
                container.querySelector('.wuac-page-next').addEventListener('click', () => {
                    if (currentPage < totalPages) {
                        currentPage++;
                        render();
                    }
                });
                container.querySelector('.wuac-page-last').addEventListener('click', () => {
                    currentPage = totalPages;
                    render();
                });
            }

            // Delete selected
            const deleteBtn = container.querySelector('#' + prefix + '-delete-btn');
            deleteBtn.addEventListener('click', async () => {
                const ids = Array.from(selectedIds);
                if (!ids.length) { toast('No users selected.', 'error'); return; }
                if (!confirm('Delete ' + ids.length + ' user(s)? This cannot be undone.')) return;
                setLoading(deleteBtn, true);
                const res = await post('wuac_delete_users', { user_ids: ids });
                setLoading(deleteBtn, false);
                res.success ? toast(res.data.message) : toast(res.data.message, 'error');
                if (res.success) {
                    selectedIds.clear();
                    refreshFn();
                }
            });

            // Flag selected
            const flagBtn = container.querySelector('#' + prefix + '-flag-btn');
            flagBtn.addEventListener('click', async () => {
                const ids = Array.from(selectedIds);
                if (!ids.length) { toast('No users selected.', 'error'); return; }
                if (!confirm('Flag ' + ids.length + ' user(s) as spam?')) return;
                setLoading(flagBtn, true);
                const res = await post('wuac_flag_users', { user_ids: ids });
                setLoading(flagBtn, false);
                res.success ? toast(res.data.message) : toast(res.data.message, 'error');
                if (res.success) {
                    selectedIds.clear();
                    render();
                }
            });

            // Delete all button
            if (opts.deleteAll) {
                const deleteAllBtn = container.querySelector('#' + prefix + '-delete-all-btn');
                deleteAllBtn.addEventListener('click', async () => {
                    if (!confirm('Delete ALL ' + count + ' matching user(s)? This cannot be undone.')) return;
                    setLoading(deleteAllBtn, true);
                    const res = await post(opts.deleteAll.action, opts.deleteAll.params || {});
                    setLoading(deleteAllBtn, false);
                    res.success ? toast(res.data.message) : toast(res.data.message, 'error');
                    if (res.success) refreshFn();
                });
            }

            // Export CSV
            const csvBtn = container.querySelector('#' + prefix + '-csv-btn');
            csvBtn.addEventListener('click', () => {
                const csvCols = columns.map(c => ({ key: c.key, label: c.label }));
                downloadCSV(users, csvCols, prefix + '-export-' + new Date().toISOString().slice(0, 10) + '.csv');
                toast('CSV exported.');
            });
        }

        render();
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

            if (!res.success) { toast(res.data.message, 'error'); return; }

            const { matched, unmatched_count, patterns_used } = res.data;
            updateTabCount('lookup', matched.length);

            // Build summary header
            let summaryHtml = '<div class="wuac-results-summary">';
            summaryHtml += '<span class="wuac-stat wuac-stat--matched">' + matched.length + ' matched</span>';
            if (unmatched_count > 0) summaryHtml += '<span class="wuac-stat wuac-stat--unmatched">' + unmatched_count + ' unmatched</span>';
            if (patterns_used > 0) summaryHtml += '<span class="wuac-stat wuac-stat--pattern">' + patterns_used + ' pattern(s) used</span>';
            summaryHtml += '</div>';

            if (matched.length === 0) {
                resultsWrap.innerHTML = '<div class="wuac-card">' + summaryHtml + '</div>';
                resultsWrap.hidden = false;
                return;
            }

            const columns = [
                { key: 'ID', label: 'ID', sortType: 'num' },
                { key: 'user_login', label: 'Username', sortType: 'str', render: u => userLink(u.user_login, u.ID) },
                { key: 'user_email', label: 'Email', sortType: 'str' },
                { key: 'role', label: 'Role', sortType: 'str', render: u => '<span class="wuac-role-badge">' + esc(u.role || 'none') + '</span>' },
                { key: 'spam_score', label: 'Score', sortType: 'num', render: u => scoreHtml(u.spam_score || 0) },
                { key: 'user_registered', label: 'Registered', sortType: 'str' },
            ];

            // We use renderResultsCard but prepend the custom summary
            renderResultsCard(resultsWrap, {
                users: matched,
                columns,
                prefix: 'wuac-lookup',
                heading: 'matched',
                refreshFn: () => btn.click(),
            });

            // Replace the auto-generated summary with our custom one (includes unmatched/patterns)
            const autoSummary = resultsWrap.querySelector('.wuac-results-summary');
            if (autoSummary) autoSummary.outerHTML = summaryHtml;
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

            const params = {
                days: daysInput.value,
                role: roleSelect ? roleSelect.value : 'all',
                type: typeSelect ? typeSelect.value : 'both'
            };
            const res = await post('wuac_find_inactive', params);
            setLoading(findBtn, false);

            if (!res.success) { toast(res.data.message, 'error'); return; }

            updateTabCount('cleanup', res.data.count);

            const columns = [
                { key: 'ID', label: 'ID', sortType: 'num' },
                { key: 'user_login', label: 'Username', sortType: 'str', render: u => userLink(u.user_login, u.ID) },
                { key: 'user_email', label: 'Email', sortType: 'str' },
                { key: 'role', label: 'Role', sortType: 'str', render: u => '<span class="wuac-role-badge">' + esc(u.role || 'none') + '</span>' },
                { key: 'spam_score', label: 'Score', sortType: 'num', render: u => scoreHtml(u.spam_score || 0) },
                { key: 'last_login', label: 'Last Login', sortType: 'str', render: u => loginHtml(u.last_login) },
                { key: 'user_registered', label: 'Registered', sortType: 'str' },
            ];

            renderResultsCard(resultsWrap, {
                users: res.data.users,
                columns,
                prefix: 'wuac-inactive',
                heading: 'inactive user(s) found',
                refreshFn: () => findBtn.click(),
                deleteAll: {
                    action: 'wuac_delete_all_inactive',
                    params,
                    label: 'Delete All ' + res.data.count + ' Inactive Users (server-side)',
                },
            });
        });
    }

    // ── Spam Cleanup (High Risk) ──────────────────────────────────────

    function initSpamCleanup() {
        const findBtn = document.getElementById('wuac-find-spam-btn');
        const minScoreInput = document.getElementById('wuac-spam-min-score');
        const roleSelect = document.getElementById('wuac-spam-role');
        const resultsWrap = document.getElementById('wuac-spam-cleanup-results');
        if (!findBtn) return;

        findBtn.addEventListener('click', async () => {
            setLoading(findBtn, true);
            resultsWrap.hidden = true;

            const params = {
                min_score: minScoreInput ? minScoreInput.value : 70,
                role: roleSelect ? roleSelect.value : 'all'
            };

            const res = await post('wuac_find_high_risk', params);
            setLoading(findBtn, false);

            if (!res.success) { toast(res.data.message, 'error'); return; }

            updateTabCount('spam-cleanup', res.data.count);

            const columns = [
                { key: 'ID', label: 'ID', sortType: 'num' },
                { key: 'user_login', label: 'Username', sortType: 'str', render: u => userLink(u.user_login, u.ID) },
                { key: 'user_email', label: 'Email', sortType: 'str' },
                { key: 'role', label: 'Role', sortType: 'str', render: u => '<span class="wuac-role-badge">' + esc(u.role || 'none') + '</span>' },
                { key: 'spam_score', label: 'Score', sortType: 'num', render: u => scoreHtml(u.spam_score || 0) },
                { key: 'last_login', label: 'Last Login', sortType: 'str', render: u => loginHtml(u.last_login) },
                { key: 'user_registered', label: 'Registered', sortType: 'str' },
            ];

            renderResultsCard(resultsWrap, {
                users: res.data.users,
                columns,
                prefix: 'wuac-spam',
                heading: 'high risk user(s) found (Score ≥ ' + params.min_score + ')',
                refreshFn: () => findBtn.click(),
                deleteAll: {
                    action: 'wuac_delete_all_high_risk',
                    params,
                    label: 'Delete All ' + res.data.count + ' High Risk Users (server-side)',
                },
            });
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

            let html = '<div class="wuac-domain-list-container">';
            html += '<table class="wp-list-table widefat fixed striped">';
            html += '<thead><tr><th>Domain</th><th style="width:80px">Action</th></tr></thead><tbody>';
            domains.forEach(d => {
                html += '<tr><td>' + esc(d) + '</td><td><button type="button" class="button button-link-delete wuac-remove-domain" data-domain="' + esc(d) + '">Remove</button></td></tr>';
            });
            html += '</tbody></table></div>';
            listWrap.innerHTML = html;

            listWrap.querySelectorAll('.wuac-remove-domain').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Remove domain "' + btn.dataset.domain + '"?')) return;
                    setLoading(btn, true);
                    const res = await post('wuac_remove_domain', { domain: btn.dataset.domain });
                    setLoading(btn, false);
                    if (res.success) {
                        toast(res.data.message);
                        renderDomains(res.data.domains);
                    } else {
                        toast(res.data.message, 'error');
                    }
                });
            });
        }

        // Load domains on init
        post('wuac_get_domains').then(res => {
            if (res.success) renderDomains(res.data.domains);
        });

        addBtn.addEventListener('click', async () => {
            const domain = input.value.trim();
            if (!domain) { toast('Please enter a domain.', 'error'); return; }

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

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); addBtn.click(); }
        });
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
            res.success ? toast(res.data.message) : toast(res.data.message, 'error');
        });

        eraseBtn.addEventListener('click', async () => {
            if (!confirm('This will permanently remove ALL plugin data. This cannot be undone. Continue?')) return;
            setLoading(eraseBtn, true);
            const res = await post('wuac_erase_data');
            setLoading(eraseBtn, false);
            res.success ? toast(res.data.message) : toast(res.data.message, 'error');
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
