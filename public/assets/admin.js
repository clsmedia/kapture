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

(function () {
    var KEY = 'ar', INT = 5, btn = document.getElementById('live-btn'), t = null, c = INT;

    function start() {
        sessionStorage.setItem(KEY, '1');
        btn.classList.add('live-btn--on');
        c = INT;
        tick();
        t = setInterval(function () {
            c--;
            if (c <= 0) {
                location.reload();
                return;
            }
            tick();
        }, 1000);
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
