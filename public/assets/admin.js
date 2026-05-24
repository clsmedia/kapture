var activeGroup = null;

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
        var textMatch = !q || text.indexOf(q) !== -1;
        var groupMatch = !activeGroup || row.getAttribute('data-group') === activeGroup;
        var match = textMatch && groupMatch;
        row.style.display = match ? '' : 'none';
        if (detail && detail.classList.contains('details-row')) {
            detail.style.display = (match && detail.style.display !== 'none') ? 'table-row' : 'none';
        }
        if (match) visible++;
    });
    document.getElementById('count').textContent = visible + ' entries';
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

function createEntryHtml(entry) {
    var id = entry.captureId;
    var parts = splitUri(entry.uri);
    var groupCounts = getGroupCounts();
    if (parts.group) groupCounts[parts.group] = (groupCounts[parts.group] || 0) + 1;
    var showGroup = parts.group !== '' && (groupCounts[parts.group] || 0) > 1;

    function esc(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var groupAttr = showGroup ? ' data-group="' + esc(parts.group) + '"' : '';

    var html = '<tr class="row"' + groupAttr + ' data-capture-id="' + esc(id) + '" data-uri="' + esc(entry.uri) + '" onclick="toggle(\'detail-' + id + '\')">'
        + '<td class="ts">' + esc(entry.capturedAtHuman) + '</td>'
        + '<td class="method-cell"><span class="method method-' + entry.method + '">' + entry.method + '</span></td>'
        + '<td class="uid">' + esc(id) + '</td>'
        + '<td class="uri">';
    if (showGroup) {
        html += '/<span class="uri-group" data-group="' + esc(parts.group) + '" onclick="event.stopPropagation();filterByGroup(this)">' + esc(parts.group) + '</span><span class="uri-path">' + esc(parts.rest) + '</span>';
    } else {
        html += esc(entry.uri);
    }
    html += '</td>'
        + '<td class="ip">' + esc(entry.ip) + '<button class="expand-btn">&#9660;</button></td>'
        + '</tr>'
        + '<tr id="detail-' + id + '" class="details-row" style="display:none">'
        + '<td colspan="5"><div class="details" style="display:block">';
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
