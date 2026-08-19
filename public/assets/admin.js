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
    if (e.key === 'Escape') {
        var menu = document.getElementById('bulk-menu');
        if (menu && !menu.hidden) closeBulkMenu();
    }
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

function formatBody(body) {
    if (body === '') return '(empty)';
    try {
        var parsed = JSON.parse(body);
        return JSON.stringify(parsed, null, 4);
    } catch (e) {
        return body;
    }
}

function getGroupCounts() {
    var counts = {};
    document.querySelectorAll('#log-table tbody .row[data-uri]').forEach(function (row) {
        var parts = splitUri(row.getAttribute('data-uri'));
        if (parts.group) counts[parts.group] = (counts[parts.group] || 0) + 1;
    });
    return counts;
}

function getKeyColor(key) {
    var palette = ['#86b6ff', '#8bd4a0', '#e8c88a', '#c5a5ff', '#f0a8a8', '#7dd8d8', '#e8b88a', '#d4a8d8'];
    var h = 0;
    for (var i = 0; i < key.length; i++) {
        h = ((h << 5) - h) + key.charCodeAt(i);
        h = h & h;
    }
    return palette[Math.abs(h) % palette.length];
}

function createEntryHtml(entry) {
    var id = entry.captureId;
    var qIdx = entry.uri.indexOf('?');
    var uriPath = qIdx !== -1 ? entry.uri.substring(0, qIdx) : entry.uri;
    var parts = splitUri(entry.uri);
    var groupCounts = getGroupCounts();
    if (parts.group) groupCounts[parts.group] = (groupCounts[parts.group] || 0) + 1;
    var showGroup = parts.group !== '' && (groupCounts[parts.group] || 0) > 1;

    function esc(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var groupAttr = showGroup ? ' data-group="' + esc(parts.group) + '"' : '';

    var qGroupAttr = '';
    var queryHtml = '';
    if (entry.query && Object.keys(entry.query).length > 0) {
        var pairs = [];
        var spans = [];
        for (var qk in entry.query) {
            if (!entry.query.hasOwnProperty(qk)) continue;
            var qPair = qk + '=' + entry.query[qk];
            pairs.push(qPair);
            spans.push('<span class="uri-qgroup" data-qgroup="' + esc(qPair) + '" style="color:' + getKeyColor(qk) + '" onclick="event.stopPropagation();filterByQueryGroup(this)">' + esc(qPair) + '</span>');
        }
        qGroupAttr = ' data-qgroups="|' + esc(pairs.join('|')) + '|"';
        queryHtml = '?' + spans.join('&');
    }

    var tsParts = entry.capturedAtHuman.split(' ', 2);
    var html = '<tr class="row"' + groupAttr + qGroupAttr + ' data-capture-id="' + esc(id) + '" data-uri="' + esc(entry.uri) + '" data-method="' + esc(entry.method) + '" onclick="toggle(\'detail-' + id + '\')">'
        + '<td class="sel-cell"><input type="checkbox" class="row-check" data-capture-id="' + esc(id) + '" onclick="event.stopPropagation();toggleSelect(this)"></td>'
        + '<td class="ts"><span class="ts-date">' + esc(tsParts[0]) + '</span> <br class="ts-br"><span class="ts-time">' + esc(tsParts[1] || '') + '</span></td>'
        + '<td class="method-cell"><span class="method method-' + entry.method + '">' + entry.method + '</span></td>'
        + '<td class="uid">' + esc(id) + '</td>'
        + '<td class="uri">';
    if (showGroup) {
        html += '/<span class="uri-group" data-group="' + esc(parts.group) + '" onclick="event.stopPropagation();filterByGroup(this)">' + esc(parts.group) + '</span><span class="uri-path">' + esc(parts.rest) + '</span>';
    } else {
        html += esc(uriPath);
    }
    html += queryHtml + '</td>'
        + '<td class="ip">' + esc(entry.ip) + '<button class="expand-btn">&#9660;</button></td>'
        + '</tr>'
        + '<tr id="detail-' + id + '" class="details-row" style="display:none">'
        + '<td colspan="6"><div class="details" style="display:block">';
    if (id) {
        html += '<h3>Capture ID</h3><pre>' + esc(id) + '</pre>';
    }
    if (entry.headers && Object.keys(entry.headers).length > 0) {
        html += '<h3>Headers</h3><pre>' + esc(JSON.stringify(entry.headers, null, 4)) + '</pre>';
    }
    if (entry.query && Object.keys(entry.query).length > 0) {
        html += '<h3>Query</h3><pre>' + esc(JSON.stringify(entry.query, null, 4)) + '</pre>';
    }
    html += '<h3>Body</h3><pre>' + esc(formatBody(entry.body)) + '</pre>'
        + '</div></td></tr>';

    return html;
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
        var url = '/admin?format=json';
        var m = window.location.search.match(/[?&]file=([^&]+)/);
        if (m) url += '&file=' + encodeURIComponent(m[1]);

        fetch(url)
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (!data.entries || data.entries.length === 0) return;
                var tbody = document.querySelector('#log-table tbody');
                if (!tbody) return;

                var added = 0;
                data.entries.forEach(function (entry) {
                    if (!entry.captureId || knownCaptureIds.has(entry.captureId)) return;
                    knownCaptureIds.add(entry.captureId);
                    tbody.insertAdjacentHTML('afterbegin', createEntryHtml(entry));
                    added++;
                });

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
