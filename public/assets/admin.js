var activeGroup = null;
var activeQueryGroup = null;
var activeMethod = null;
var selected = {};

function deleteEntry(captureId) {
    if (!confirm('Delete this entry?')) return;
    var url = '/admin?delete=' + encodeURIComponent(captureId);
    var csrf = document.querySelector('meta[name="csrf-token"]');
    if (csrf) url += '&_csrf=' + encodeURIComponent(csrf.getAttribute('content'));
    var m = window.location.search.match(/[?&]file=([^&]+)/);
    if (m) url += '&file=' + encodeURIComponent(m[1]);
    location.href = url;
}

function toggleSelect(cb) {
    var id = cb.getAttribute('data-capture-id');
    var row = cb.closest('tr');
    if (cb.checked) selected[id] = true;
    else delete selected[id];
    if (row) row.classList.toggle('row--selected', cb.checked);
    updateBulkUI();
}

function toggleSelectAll(cb) {
    var rows = document.querySelectorAll('#log-table tbody .row');
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        if (row.style.display === 'none') continue;
        var chk = row.querySelector('.row-check');
        if (!chk) continue;
        chk.checked = cb.checked;
        var id = chk.getAttribute('data-capture-id');
        if (cb.checked) selected[id] = true;
        else delete selected[id];
        row.classList.toggle('row--selected', cb.checked);
    }
    updateBulkUI();
}

function updateBulkUI() {
    var ids = Object.keys(selected);
    var n = ids.length;
    var btn = document.getElementById('bulk-delete');
    if (btn) {
        btn.disabled = n === 0;
        btn.textContent = 'Delete selected (' + n + ')';
    }
    var badge = document.getElementById('bulk-count');
    if (badge) {
        badge.textContent = n;
        badge.hidden = n === 0;
    }
    var selAll = document.getElementById('select-all');
    if (!selAll) return;
    var rows = document.querySelectorAll('#log-table tbody .row');
    var visible = 0, visibleSelected = 0;
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].style.display === 'none') continue;
        visible++;
        var chk = rows[i].querySelector('.row-check');
        if (chk && chk.checked) visibleSelected++;
    }
    selAll.checked = visible > 0 && visibleSelected === visible;
    selAll.indeterminate = visibleSelected > 0 && visibleSelected < visible;
}

function deleteSelected() {
    var ids = Object.keys(selected);
    if (ids.length === 0) return;
    if (!confirm('Delete ' + ids.length + ' entries? This cannot be undone.')) return;
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var url = '/admin?';
    for (var i = 0; i < ids.length; i++) {
        url += 'delete[]=' + encodeURIComponent(ids[i]) + '&';
    }
    if (csrf) url += '_csrf=' + encodeURIComponent(csrf.getAttribute('content'));
    var m = window.location.search.match(/[?&]file=([^&]+)/);
    if (m) url += '&file=' + encodeURIComponent(m[1]);
    location.href = url;
}

function toggleBulkMenu() {
    var menu = document.getElementById('bulk-menu');
    if (!menu) return;
    var open = menu.hidden;
    menu.hidden = !open;
    var backdrop = document.getElementById('bulk-backdrop');
    if (backdrop) backdrop.hidden = !open;
    var btn = document.getElementById('bulk-btn');
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
        var item = menu.querySelector('button');
        if (item) item.focus();
    }
}

function closeBulkMenu() {
    var menu = document.getElementById('bulk-menu');
    if (menu) menu.hidden = true;
    var backdrop = document.getElementById('bulk-backdrop');
    if (backdrop) backdrop.hidden = true;
    var btn = document.getElementById('bulk-btn');
    if (btn) {
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
    }
}

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var modal = document.getElementById('replay-modal');
    if (modal && modal.style.display === 'flex') {
        closeReplayModal();
        return;
    }
    var menu = document.getElementById('bulk-menu');
    if (menu && !menu.hidden) closeBulkMenu();
});

function logout() {
    location.href = '/admin/logout';
}

function toggle(id) {
    var r = document.getElementById(id);
    var b = r.previousElementSibling.querySelector('.expand-btn');
    if (r.style.display === 'none' || r.style.display === '') {
        r.style.display = 'table-row';
        if (b) b.innerHTML = '&#9650;';
    } else {
        r.style.display = 'none';
        if (b) b.innerHTML = '&#9660;';
    }
}

function filterTable(val) {
    var q = val.toLowerCase();
    var rows = document.querySelectorAll('#log-table tbody .row');
    var visible = 0;
    rows.forEach(function (row) {
        var detail = row.nextElementSibling;
        var text = row.textContent.toLowerCase();
        if (detail && detail.classList.contains('details-row')) {
            text += ' ' + detail.textContent.toLowerCase();
        }
        var textMatch = !q || text.indexOf(q) !== -1;
    var groupMatch = !activeGroup || row.getAttribute('data-group') === activeGroup;
    var qgroupMatch = !activeQueryGroup || (row.getAttribute('data-qgroups') && row.getAttribute('data-qgroups').indexOf('|' + activeQueryGroup + '|') !== -1);
    var methodMatch = !activeMethod || row.getAttribute('data-method') === activeMethod;
    var match = textMatch && groupMatch && qgroupMatch && methodMatch;
        row.style.display = match ? '' : 'none';
        if (detail && detail.classList.contains('details-row')) {
            detail.style.display = (match && detail.style.display !== 'none') ? 'table-row' : 'none';
        }
        if (match) visible++;
    });
    document.getElementById('count').textContent = visible + ' entries';
    updateBulkUI();
}

function filterByGroup(el) {
    var group = el.getAttribute('data-group');
    if (!group) return;
    activeGroup = group;
    document.getElementById('group-clear').style.display = '';
    document.querySelectorAll('.uri-group--active').forEach(function (s) {
        s.classList.remove('uri-group--active');
    });
    el.classList.add('uri-group--active');
    var input = document.querySelector('.filter-input');
    filterTable(input ? input.value : '');
}

function clearGroupFilter() {
    activeGroup = null;
    document.getElementById('group-clear').style.display = 'none';
    document.querySelectorAll('.uri-group--active').forEach(function (s) {
        s.classList.remove('uri-group--active');
    });
    var input = document.querySelector('.filter-input');
    filterTable(input ? input.value : '');
}

function filterByQueryGroup(el) {
    var qg = el.getAttribute('data-qgroup');
    if (!qg) return;
    activeQueryGroup = qg;
    document.getElementById('qgroup-clear').style.display = '';
    document.querySelectorAll('.uri-qgroup--active').forEach(function (s) {
        s.classList.remove('uri-qgroup--active');
    });
    el.classList.add('uri-qgroup--active');
    var input = document.querySelector('.filter-input');
    filterTable(input ? input.value : '');
}

function clearQueryGroupFilter() {
    activeQueryGroup = null;
    document.getElementById('qgroup-clear').style.display = 'none';
    document.querySelectorAll('.uri-qgroup--active').forEach(function (s) {
        s.classList.remove('uri-qgroup--active');
    });
    var input = document.querySelector('.filter-input');
    filterTable(input ? input.value : '');
}

function filterByMethod(el) {
    var method = el.getAttribute('data-method');
    if (!method) return;
    if (activeMethod === method) {
        clearMethodFilter();
        return;
    }
    activeMethod = method;
    document.getElementById('method-clear').style.display = '';
    document.querySelectorAll('.method-pill--active').forEach(function (s) {
        s.classList.remove('method-pill--active');
    });
    el.classList.add('method-pill--active');
    var input = document.querySelector('.filter-input');
    filterTable(input ? input.value : '');
}

function clearMethodFilter() {
    activeMethod = null;
    document.getElementById('method-clear').style.display = 'none';
    document.querySelectorAll('.method-pill--active').forEach(function (s) {
        s.classList.remove('method-pill--active');
    });
    var input = document.querySelector('.filter-input');
    filterTable(input ? input.value : '');
}

function splitUri(uri) {
    var qIdx = uri.indexOf('?');
    var path = qIdx !== -1 ? uri.substring(0, qIdx) : uri;
    var trimmed = path.replace(/^\/+/, '');
    if (trimmed === '') return {group: '', rest: path};
    var slashIdx = trimmed.indexOf('/');
    if (slashIdx === -1) return {group: trimmed, rest: ''};
    return {group: trimmed.substring(0, slashIdx), rest: '/' + trimmed.substring(slashIdx + 1)};
}

function getGroupCounts() {
    var counts = {};
    document.querySelectorAll('#log-table tbody .row[data-uri]').forEach(function (row) {
        var parts = splitUri(row.getAttribute('data-uri'));
        if (parts.group) counts[parts.group] = (counts[parts.group] || 0) + 1;
    });
    return counts;
}

(function () {
    var KEY = 'ar', INT = 5, btn = document.getElementById('live-btn'), t = null, c = INT;

    if (!btn) return;

    var mFile = window.location.search.match(/[?&]file=([^&]+)/);
    if (mFile) {
        var fileDate = decodeURIComponent(mFile[1]);
        var d = new Date();
        var todayStr = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        if (fileDate !== todayStr) {
            btn.style.display = 'none';
            return;
        }
    }

    var knownCaptureIds = new Set();
    document.querySelectorAll('#log-table tbody .row[data-capture-id]').forEach(function (row) {
        knownCaptureIds.add(row.getAttribute('data-capture-id'));
    });

    function start() {
        sessionStorage.setItem(KEY, '1');
        btn.classList.add('live-btn--on');
        c = INT;
        tick();
        t = setInterval(function () {
            c--;
            if (c <= 0) {
                poll();
                c = INT;
            }
            tick();
        }, 1000);
    }

    function poll() {
        var url = '/admin?format=rows';
        var m = window.location.search.match(/[?&]file=([^&]+)/);
        if (m) url += '&file=' + encodeURIComponent(m[1]);

        fetch(url)
            .then(function (r) {
                return r.text();
            })
            .then(function (html) {
                var tbody = document.querySelector('#log-table tbody');
                if (!tbody || html === '') return;

                var doc = new DOMParser().parseFromString('<table><tbody>' + html + '</tbody></table>', 'text/html');
                var rows = doc.querySelectorAll('tr.row');

                var added = 0;
                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i];
                    var id = row.getAttribute('data-capture-id');
                    if (!id || knownCaptureIds.has(id)) continue;
                    knownCaptureIds.add(id);
                    var detail = row.nextElementSibling;
                    var frag = row.outerHTML;
                    if (detail && detail.classList.contains('details-row')) {
                        frag += detail.outerHTML;
                    }
                    tbody.insertAdjacentHTML('afterbegin', frag);
                    added++;
                }

                if (added === 0) return;

                syncDataGroups();
                var input = document.querySelector('.filter-input');
                filterTable(input ? input.value : '');
            })
            .catch(function () {
            });
    }

    function syncDataGroups() {
        var counts = getGroupCounts();
        document.querySelectorAll('#log-table tbody .row[data-uri]:not([data-group])').forEach(function (row) {
            var parts = splitUri(row.getAttribute('data-uri'));
            if (parts.group && (counts[parts.group] || 0) > 1) {
                row.setAttribute('data-group', parts.group);
            }
        });
    }

    function tick() {
        btn.textContent = 'live ' + c + 's';
    }

    function stop() {
        sessionStorage.removeItem(KEY);
        btn.classList.remove('live-btn--on');
        btn.textContent = 'live';
        if (t) {
            clearInterval(t);
            t = null;
        }
        c = INT;
    }

    btn.onclick = function () {
        t ? stop() : start();
    };
    if (sessionStorage.getItem(KEY) === '1') start();
})();

var replayState = {captureId: null, format: 'http', requestId: 0};

function showReplayModal(captureId) {
    replayState.captureId = captureId;
    replayState.format = 'http';
    var modal = document.getElementById('replay-modal');
    if (!modal) return;
    modal.style.display = 'flex';
    document.querySelectorAll('.modal-tab').forEach(function (tab) {
        tab.classList.toggle('modal-tab--active', tab.getAttribute('data-format') === 'http');
    });
    fetchReplayContent();
}

function closeReplayModal() {
    var modal = document.getElementById('replay-modal');
    if (modal) modal.style.display = 'none';
    replayState.captureId = null;
}

function switchReplayTab(el) {
    var format = el.getAttribute('data-format');
    if (!format || format === replayState.format) return;
    replayState.format = format;
    document.querySelectorAll('.modal-tab').forEach(function (tab) {
        tab.classList.toggle('modal-tab--active', tab.getAttribute('data-format') === format);
    });
    fetchReplayContent();
}

function fetchReplayContent() {
    var contentEl = document.getElementById('replay-content');
    if (!contentEl) return;
    var requestId = ++replayState.requestId;
    contentEl.textContent = 'Loading...';
    var url = '/admin?replay=' + encodeURIComponent(replayState.captureId) + '&format=' + encodeURIComponent(replayState.format);
    fetch(url)
        .then(function (r) {
            return r.json();
        })
        .then(function (data) {
            if (requestId !== replayState.requestId) return;
            contentEl.textContent = data.content || '(empty)';
        })
        .catch(function () {
            if (requestId !== replayState.requestId) return;
            contentEl.textContent = 'Error loading replay content.';
        });
}

function copyReplayContent() {
    var contentEl = document.getElementById('replay-content');
    if (!contentEl) return;
    var text = contentEl.textContent;
    if (!text || text === 'Loading...' || text === 'Error loading replay content.') return;

    function showCopied() {
        var btn = document.querySelector('.copy-btn');
        if (btn) {
            btn.textContent = 'Copied!';
            setTimeout(function () {
                btn.textContent = 'Copy to clipboard';
            }, 1500);
        }
    }

    function fallback() {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            showCopied();
        } catch (e) {
            contentEl.textContent = 'Copy failed — select the text manually.';
        } finally {
            document.body.removeChild(ta);
        }
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(showCopied).catch(fallback);
    } else {
        fallback();
    }
}
