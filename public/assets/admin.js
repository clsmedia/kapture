const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
const LIVE_INTERVAL = 5;
const LIVE_KEY = 'ar';

const PALETTE = [
    '#86b6ff',
    '#8bd4a0',
    '#e8c88a',
    '#c5a5ff',
    '#f0a8a8',
    '#7dd8d8',
    '#e8b88a',
    '#d4a8d8',
];

function crc32(str) {
    let crc = -1;
    for (let i = 0; i < str.length; i++) {
        crc = (crc >>> 8) ^ crcTable[(crc ^ str.charCodeAt(i)) & 0xff];
    }
    return (crc ^ -1) >>> 0;
}

const crcTable = (() => {
    const table = new Int32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }
        table[n] = c;
    }
    return table;
})();

function splitUri(uri) {
    const qIdx = uri.indexOf('?');
    const path = qIdx !== -1 ? uri.substring(0, qIdx) : uri;
    const rawQuery = qIdx !== -1 ? uri.substring(qIdx + 1) : '';
    const trimmed = path.replace(/^\/+/, '');
    if (trimmed === '') return { group: '', rest: path, path: path === '' ? '/' : path, rawQuery };
    const slashIdx = trimmed.indexOf('/');
    if (slashIdx === -1) return { group: trimmed, rest: '', path, rawQuery };
    return { group: trimmed.substring(0, slashIdx), rest: '/' + trimmed.substring(slashIdx + 1), path, rawQuery };
}

function formatBody(body) {
    if (body === '') return '(empty)';
    try {
        return JSON.stringify(JSON.parse(body), null, 2);
    } catch {
        return body;
    }
}

function prettyJson(value) {
    return JSON.stringify(value, null, 2);
}

function todayStr() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

document.addEventListener('alpine:init', () => {
    Alpine.data('kaptureAdmin', () => ({
        methods: METHODS,
        loaded: false,
        entries: [],
        total: 0,
        page: 1,
        perPage: 100,
        archives: [],
        selectedArchive: null,
        csrfToken: '',

        searchText: '',
        activeGroup: null,
        activeQueryGroup: null,
        activeMethod: null,

        openDetails: [],
        selected: {},
        bulkMenuOpen: false,
        copyLabel: 'Copy to clipboard',

        live: false,
        liveCountdown: LIVE_INTERVAL,
        liveTimer: null,

        replay: { open: false, captureId: null, format: 'http', content: '', requestId: 0 },

        sidebarOpen: false,

        async init() {
            const params = new URLSearchParams(window.location.search);
            const file = params.get('file');
            const page = Number.parseInt(params.get('page') ?? '1', 10);
            this.selectedArchive = file !== null && file !== '' ? file : null;
            this.page = Number.isNaN(page) || page < 1 ? 1 : page;

            await this.fetchState();

            if (sessionStorage.getItem(LIVE_KEY) === '1' && this.liveAvailable) {
                this.startLive();
            }
        },

        async fetchState() {
            const params = new URLSearchParams();
            params.set('page', String(this.page));
            if (this.selectedArchive !== null) params.set('file', this.selectedArchive);

            try {
                const response = await fetch('/admin/api/state?' + params.toString());
                if (!response.ok) return;
                const data = await response.json();
                this.entries = data.entries;
                this.total = data.total;
                this.page = data.page;
                this.perPage = data.perPage;
                this.archives = data.archives;
                this.selectedArchive = data.selectedArchive;
                this.csrfToken = data.csrfToken;
                this.loaded = true;
            } catch {
                // Transient network errors keep the current view; the next
                // live poll or manual reload recovers.
            }
        },

        get filteredEntries() {
            const q = this.searchText.toLowerCase();
            return this.entries.filter((entry) => {
                if (q !== '' && !entrySearchText(entry).includes(q)) return false;
                if (this.activeGroup !== null && splitUri(entry.uri).group !== this.activeGroup) return false;
                if (this.activeQueryGroup !== null && !entryQGroups(entry).includes(this.activeQueryGroup)) return false;
                if (this.activeMethod !== null && entry.method !== this.activeMethod) return false;
                return true;
            });
        },

        get selectedIds() {
            return Object.keys(this.selected);
        },

        get visibleIds() {
            return this.filteredEntries.map((entry) => entry.captureId);
        },

        get allVisibleSelected() {
            const visible = this.visibleIds;
            return visible.length > 0 && visible.every((id) => this.selected[id]);
        },

        get someVisibleSelected() {
            const visible = this.visibleIds;
            return visible.some((id) => this.selected[id]);
        },

        get lastPage() {
            return Math.max(1, Math.ceil(this.total / this.perPage));
        },

        get paginationWindow() {
            const start = Math.max(1, this.page - 2);
            const end = Math.min(this.lastPage, this.page + 2);
            const pages = [];
            for (let p = start; p <= end; p++) pages.push(p);
            return pages;
        },

        get liveAvailable() {
            return this.selectedArchive === null || this.selectedArchive === todayStr();
        },

        get rawLinkHref() {
            const base = this.selectedArchive !== null
                ? '/admin?file=' + encodeURIComponent(this.selectedArchive) + '&raw'
                : '/admin?raw';
            return base;
        },

        pageUrl(p) {
            const params = new URLSearchParams();
            params.set('page', String(p));
            if (this.selectedArchive !== null) params.set('file', this.selectedArchive);
            return '/admin?' + params.toString();
        },

        toggleDetail(captureId) {
            this.openDetails = this.openDetails.includes(captureId)
                ? this.openDetails.filter((id) => id !== captureId)
                : [...this.openDetails, captureId];
        },

        syncSelectAllState() {
            const el = document.getElementById('select-all');
            if (!el) return;
            el.checked = this.allVisibleSelected;
            el.indeterminate = this.someVisibleSelected && !this.allVisibleSelected;
        },

        toggleSelect(captureId) {
            if (this.selected[captureId]) {
                delete this.selected[captureId];
            } else {
                this.selected[captureId] = true;
            }
        },

        toggleSelectAll() {
            const target = !this.allVisibleSelected;
            const next = { ...this.selected };
            for (const id of this.visibleIds) {
                if (target) next[id] = true;
                else delete next[id];
            }
            this.selected = next;
        },

        toggleBulkMenu() {
            this.bulkMenuOpen = !this.bulkMenuOpen;
            if (this.bulkMenuOpen) {
                this.$nextTick(() => {
                    const item = document.querySelector('#bulk-menu button');
                    if (item) item.focus();
                });
            }
        },

        closeBulkMenu() {
            this.bulkMenuOpen = false;
        },

        toggleMethod(method) {
            this.activeMethod = this.activeMethod === method ? null : method;
        },

        clearMethodFilter() {
            this.activeMethod = null;
        },

        filterByGroup(group) {
            this.activeGroup = group;
        },

        clearGroupFilter() {
            this.activeGroup = null;
        },

        filterByQueryGroup(pair) {
            this.activeQueryGroup = pair;
        },

        clearQueryGroupFilter() {
            this.activeQueryGroup = null;
        },

        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen;
        },

        closeSidebar() {
            this.sidebarOpen = false;
        },

        logout() {
            window.location.href = '/admin/logout';
        },

        onEscapeKey() {
            if (this.replay.open) {
                this.closeReplay();
                return;
            }
            if (this.bulkMenuOpen) this.closeBulkMenu();
        },

        startLive() {
            sessionStorage.setItem(LIVE_KEY, '1');
            this.live = true;
            this.liveCountdown = LIVE_INTERVAL;
            this.liveTimer = setInterval(() => {
                this.liveCountdown--;
                if (this.liveCountdown <= 0) {
                    this.fetchState();
                    this.liveCountdown = LIVE_INTERVAL;
                }
            }, 1000);
        },

        stopLive() {
            sessionStorage.removeItem(LIVE_KEY);
            this.live = false;
            if (this.liveTimer) {
                clearInterval(this.liveTimer);
                this.liveTimer = null;
            }
            this.liveCountdown = LIVE_INTERVAL;
        },

        toggleLive() {
            this.live ? this.stopLive() : this.startLive();
        },

        async deleteEntry(captureId) {
            if (!window.confirm('Delete this entry?')) return;
            await this.deleteMany([captureId]);
        },

        async deleteSelected() {
            const ids = this.selectedIds;
            if (ids.length === 0) return;
            if (!window.confirm('Delete ' + ids.length + ' entries? This cannot be undone.')) return;
            await this.deleteMany(ids);
        },

        async deleteMany(ids) {
            try {
                const response = await fetch('/admin/api/delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken,
                    },
                    body: JSON.stringify({ ids }),
                });
                if (!response.ok) return;
                const next = { ...this.selected };
                for (const id of ids) delete next[id];
                this.selected = next;
                this.openDetails = this.openDetails.filter((id) => !ids.includes(id));
                await this.fetchState();
            } catch {
                // Keep the current view; the user can retry.
            }
        },

        openReplay(captureId) {
            this.replay = { open: true, captureId, format: 'http', content: 'Loading...', requestId: this.replay.requestId + 1 };
            this.fetchReplayContent();
        },

        closeReplay() {
            this.replay.open = false;
            this.replay.captureId = null;
        },

        switchReplayFormat(format) {
            if (format === this.replay.format) return;
            this.replay.format = format;
            this.fetchReplayContent();
        },

        async fetchReplayContent() {
            const requestId = ++this.replay.requestId;
            this.replay.content = 'Loading...';
            const params = new URLSearchParams();
            params.set('replay', this.replay.captureId ?? '');
            params.set('format', this.replay.format);

            try {
                const response = await fetch('/admin?' + params.toString());
                const data = await response.json();
                if (requestId !== this.replay.requestId) return;
                this.replay.content = data.content || '(empty)';
            } catch {
                if (requestId !== this.replay.requestId) return;
                this.replay.content = 'Error loading replay content.';
            }
        },

        async copyReplayContent() {
            const text = this.replay.content;
            if (text === '' || text === 'Loading...' || text === 'Error loading replay content.') return;

            const showCopied = () => {
                this.copyLabel = 'Copied!';
                setTimeout(() => {
                    this.copyLabel = 'Copy to clipboard';
                }, 1500);
            };

            const fallback = () => {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand('copy');
                    showCopied();
                } catch {
                    this.replay.content = 'Copy failed — select the text manually.';
                } finally {
                    document.body.removeChild(ta);
                }
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                try {
                    await navigator.clipboard.writeText(text);
                    showCopied();
                } catch {
                    fallback();
                }
            } else {
                fallback();
            }
        },

        showGroup(entry) {
            const group = splitUri(entry.uri).group;
            if (group === '') return false;
            return (this.groupCounts[group] ?? 0) > 1;
        },

        get groupCounts() {
            const counts = {};
            for (const e of this.entries) {
                const g = splitUri(e.uri).group;
                if (g !== '') counts[g] = (counts[g] ?? 0) + 1;
            }
            return counts;
        },

        groupOf(entry) {
            return splitUri(entry.uri).group;
        },

        restOf(entry) {
            return splitUri(entry.uri).rest;
        },

        pathOf(entry) {
            return splitUri(entry.uri).path;
        },

        rawQueryOf(entry) {
            return splitUri(entry.uri).rawQuery;
        },

        queryPairs(entry) {
            return Object.entries(entry.query ?? {}).map(([key, value]) => ({
                key,
                pair: key + '=' + value,
            }));
        },

        qgroupsAttr(entry) {
            const pairs = this.queryPairs(entry).map((q) => q.pair);
            return pairs.length > 0 ? '|' + pairs.join('|') + '|' : null;
        },

        keyColor(key) {
            return PALETTE[Math.abs(crc32(key)) % PALETTE.length];
        },

        tsDate(entry) {
            return entry.capturedAt.slice(0, 10);
        },

        tsTime(entry) {
            return entry.capturedAt.slice(11, 19) + ' UTC';
        },

        forwardClass(entry) {
            const sc = entry.forwardStatusCode;
            if (sc !== null && sc >= 400 && sc < 500) return 'forward-label forward-label--warn';
            if (sc !== null && sc >= 500) return 'forward-label forward-label--error';
            return 'forward-label';
        },

        detailBody(entry) {
            return formatBody(entry.body ?? '');
        },

        detailHeaders(entry) {
            return prettyJson(entry.headers ?? {});
        },

        detailQuery(entry) {
            return prettyJson(entry.query ?? {});
        },

        hasHeaders(entry) {
            return Object.keys(entry.headers ?? {}).length > 0;
        },

        hasQuery(entry) {
            return Object.keys(entry.query ?? {}).length > 0;
        },

        forwardDetail(entry) {
            return 'Target: ' + entry.forwardUrl + '\nStatus: ' + entry.forwardStatusCode;
        },

        isOpen(entry) {
            return this.openDetails.includes(entry.captureId);
        },
    }));

    function entrySearchText(entry) {
        return [
            entry.uri,
            entry.captureId,
            entry.method,
            entry.ip,
            entry.body ?? '',
            JSON.stringify(entry.query ?? {}),
            JSON.stringify(entry.headers ?? {}),
        ].join(' ').toLowerCase();
    }

    function entryQGroups(entry) {
        return Object.entries(entry.query ?? {}).map(([key, value]) => key + '=' + value);
    }
});
