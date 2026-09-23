/*
 * Mail Simply interface. Plain Alpine.js, no build step.
 *
 * Message bodies never touch this document: they render in a sandboxed frame
 * (frame.php) that runs no script. This file only ever puts message data on
 * the page as text.
 */
document.addEventListener('alpine:init', () => {
    const LANG = JSON.parse(document.getElementById('mail-simply-lang')?.textContent || '{}');
    const LINES = LANG.lines || {};
    const COLLAPSED_KEY = 'mail-simply:collapsed';
    const POLL_INTERVAL = 45000;
    const AUTOSAVE_INTERVAL = 30000;
    const EMAIL = /^[^\s@<>(),;:"\\[\]]+@[^\s@<>(),;:"\\[\]]+\.[^\s@<>(),;:"\\[\]]+$/;

    const translate = (text, replace = {}) => {
        let line = LINES[text] ?? text;

        for (const [name, value] of Object.entries(replace)) {
            line = line.split(':' + name).join(String(value));
        }

        return line;
    };

    const storage = {
        read(key, fallback) {
            try {
                const value = JSON.parse(window.localStorage.getItem(key) || 'null');
                return value ?? fallback;
            } catch {
                return fallback;
            }
        },
        write(key, value) {
            try {
                window.localStorage.setItem(key, JSON.stringify(value));
            } catch {
                // Private mode, full storage: only a convenience is lost.
            }
        },
    };

    const escapeHtml = (text) => String(text)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    const textToHtml = (text) => String(text).split(/\n{2,}/)
        .map((paragraph) => '<p>' + escapeHtml(paragraph).replace(/\n/g, '<br>') + '</p>')
        .join('');

    /**
     * Parse typed recipients: "Name <a@b.c>", "a@b.c", several separated by
     * commas, semicolons or line breaks. Commas inside quotes stay.
     */
    const parseAddresses = (text) => {
        const entries = [];
        let current = '';
        let quoted = false;
        let angle = false;

        for (const char of String(text)) {
            if (char === '"') quoted = !quoted;
            if (char === '<') angle = true;
            if (char === '>') angle = false;

            if (!quoted && !angle && (char === ',' || char === ';' || char === '\n')) {
                entries.push(current);
                current = '';
                continue;
            }

            current += char;
        }

        entries.push(current);

        return entries.map((entry) => entry.trim()).filter(Boolean).map((entry) => {
            const match = entry.match(/^(.*?)<([^<>]+)>\s*$/);

            if (match) {
                return { name: match[1].trim().replace(/^"(.*)"$/, '$1'), email: match[2].trim() };
            }

            return { name: '', email: entry.replace(/^mailto:/i, '') };
        });
    };

    const FOLDER_ICONS = {
        inbox: '<svg viewBox="0 0 20 20"><path d="M3 11h4l1 2h4l1-2h4M3 11l2-6h10l2 6v4H3z"/></svg>',
        drafts: '<svg viewBox="0 0 20 20"><path d="M4 16h3l8.5-8.5-3-3L4 13v3Z"/></svg>',
        sent: '<svg viewBox="0 0 20 20"><path d="m3 10 14-6-5 14-2.5-5.5L3 10Z"/></svg>',
        junk: '<svg viewBox="0 0 20 20"><path d="M10 3 3 16h14L10 3ZM10 8v4M10 14v.5"/></svg>',
        trash: '<svg viewBox="0 0 20 20"><path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10"/></svg>',
        archive: '<svg viewBox="0 0 20 20"><path d="M3 4h14v4H3zM4 8v8h12V8M8 11h4"/></svg>',
        folder: '<svg viewBox="0 0 20 20"><path d="M3 5h5l2 2h7v9H3z"/></svg>',
    };

    const ROLE_NAMES = { inbox: 'Inbox', drafts: 'Drafts', sent: 'Sent', junk: 'Junk', trash: 'Trash', archive: 'Archive' };

    let toastId = 0;

    Alpine.data('mailSimply', () => ({
        csrf: document.querySelector('meta[name="csrf-token"]').content,
        lang: LANG.code || 'en',
        languages: LANG.languages || {},
        address: '',
        settings: { name: '', signature: '', html: true, language: null, trusted: [] },
        limits: { pageSize: 50, attachments: 26214400, recipients: 100 },
        quota: null,
        folders: [],
        collapsed: storage.read(COLLAPSED_KEY, {}),
        folder: null,
        foldersOpen: false,
        dropTarget: null,

        messages: [],
        page: 1,
        pages: 1,
        total: 0,
        selected: [],
        lastSelected: null,
        query: '',
        activeQuery: '',
        searchBody: false,
        filterUnread: false,
        filterFlagged: false,
        listLoading: false,
        listError: '',
        listToken: 0,

        view: 'list',
        uid: null,
        message: null,
        messageLoading: false,
        messageToken: 0,
        blocked: 0,
        remoteAllowed: false,
        plainView: false,
        frameObserver: null,

        composer: null,
        autosaveTimer: null,
        suggestTimer: null,
        settingsForm: null,
        folderDialog: null,
        toasts: [],

        async init() {
            const params = new URLSearchParams(window.location.search);

            try {
                const session = await this.api('session');
                this.address = session.address;
                this.settings = session.settings;
                this.limits = session.limits;
                this.quota = session.quota;
                this.folders = session.folders;
            } catch (error) {
                this.toast(error.message, 'error');
                return;
            }

            const requested = params.get('folder');
            const inbox = this.folders.find((item) => item.role === 'inbox') || this.folders.find((item) => item.selectable);
            const initial = requested && this.folders.some((item) => item.id === requested) ? requested : inbox?.id;

            this.query = this.activeQuery = params.get('q') || '';
            this.page = Math.max(1, parseInt(params.get('page') || '1', 10) || 1);

            if (initial) {
                this.folder = initial;
                await this.loadList();
            }

            const uid = parseInt(params.get('uid') || '', 10);

            if (uid > 0) {
                this.openMessage(uid);
            }

            if (params.get('compose') !== null) {
                this.composeMailto('mailto:' + (params.get('to') || ''));
            }

            this.updateTitle();
            window.setInterval(() => this.poll(), POLL_INTERVAL);
            window.addEventListener('popstate', () => window.location.reload());
            window.addEventListener('beforeunload', (event) => {
                if (this.composer?.dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
        },

        t: translate,

        // ---- Server ----

        async api(action, params = {}, body = null) {
            const query = new URLSearchParams({ action, ...params });
            const response = await fetch('api.php?' + query.toString(), {
                method: body === null ? 'GET' : 'POST',
                credentials: 'same-origin',
                headers: body === null
                    ? { Accept: 'application/json' }
                    : { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                body: body === null ? undefined : JSON.stringify(body),
            });

            let payload = null;

            try {
                payload = await response.json();
            } catch {
                payload = null;
            }

            if (response.status === 401) {
                this.sessionEnded(payload?.error);
            }

            if (!response.ok || payload === null || 'error' in payload) {
                throw new Error(payload?.error || this.t('Something went wrong. Try again.'));
            }

            return payload.data;
        },

        sessionEnded(message) {
            if (this.composer?.dirty) {
                // Keep what was written on screen; reloading would lose it.
                this.toast(message || this.t('Your session has ended. Sign in again.'), 'error', null, 0);
                return;
            }

            window.setTimeout(() => window.location.reload(), 1500);
        },

        syncUrl() {
            const params = new URLSearchParams();

            if (this.folder) params.set('folder', this.folder);
            if (this.uid) params.set('uid', String(this.uid));
            if (this.page > 1) params.set('page', String(this.page));
            if (this.activeQuery) params.set('q', this.activeQuery);

            const url = window.location.pathname + (params.toString() ? '?' + params.toString() : '');

            if (url !== window.location.pathname + window.location.search) {
                window.history.replaceState(null, '', url);
            }
        },

        // ---- Folders ----

        currentFolder() {
            return this.folders.find((item) => item.id === this.folder) || null;
        },

        folderName(item) {
            return item.role && ROLE_NAMES[item.role] && item.depth === 0 ? this.t(ROLE_NAMES[item.role]) : item.name;
        },

        folderIcon(role) {
            return FOLDER_ICONS[role] || FOLDER_ICONS.folder;
        },

        hasChildren(item) {
            return this.folders.some((candidate) => candidate.parent === item.id);
        },

        visibleFolders() {
            const hidden = new Set();

            return this.folders.filter((item) => {
                if (item.parent && (hidden.has(item.parent) || this.collapsed[item.parent])) {
                    hidden.add(item.id);
                    return false;
                }

                return true;
            });
        },

        toggleCollapsed(id) {
            this.collapsed = { ...this.collapsed, [id]: !this.collapsed[id] };
            storage.write(COLLAPSED_KEY, this.collapsed);
        },

        moveTargets() {
            return this.folders.filter((item) => item.selectable && item.id !== this.folder);
        },

        isTrashLike() {
            return ['trash', 'junk'].includes(this.currentFolder()?.role);
        },

        isDraftFolder() {
            return this.currentFolder()?.role === 'drafts';
        },

        async openFolder(id) {
            this.foldersOpen = false;

            if (id === this.folder && !this.activeQuery) {
                this.closeMessage();
                return this.reloadList(true);
            }

            this.folder = id;
            this.query = this.activeQuery = '';
            this.filterUnread = this.filterFlagged = false;
            this.closeMessage();
            await this.reloadList(true);
        },

        async refreshFolders() {
            try {
                const data = await this.api('folders');
                this.folders = data.folders;
                this.quota = data.quota;
                this.updateTitle();
            } catch (error) {
                this.toast(error.message, 'error');
            }
        },

        quotaRatio() {
            return this.quota && this.quota.limit > 0 ? this.quota.used / this.quota.limit : 0;
        },

        openFolderDialog(mode) {
            const current = this.currentFolder();

            this.folderDialog = {
                mode,
                name: mode === 'rename' ? current?.name || '' : '',
                parent: mode === 'create' && current && !current.role ? current.id : '',
                error: '',
                saving: false,
            };
        },

        async submitFolderDialog() {
            const dialog = this.folderDialog;
            dialog.saving = true;
            dialog.error = '';

            try {
                if (dialog.mode === 'create') {
                    const result = await this.api('folder-create', {}, { name: dialog.name, parent: dialog.parent || null });
                    await this.refreshFolders();
                    this.folderDialog = null;
                    await this.openFolder(result.id);
                } else {
                    const result = await this.api('folder-rename', {}, { folder: this.folder, name: dialog.name });
                    this.folder = result.id;
                    await this.refreshFolders();
                    this.folderDialog = null;
                    this.syncUrl();
                }
            } catch (error) {
                dialog.error = error.message;
                dialog.saving = false;
            }
        },

        async deleteFolder() {
            const current = this.currentFolder();

            if (!current || !window.confirm(this.t('Delete the folder ":name" and every message in it?', { name: current.name }))) {
                return;
            }

            try {
                await this.api('folder-delete', {}, { folder: current.id });
                await this.refreshFolders();
                const inbox = this.folders.find((item) => item.role === 'inbox');
                await this.openFolder(inbox?.id || this.folders[0]?.id);
            } catch (error) {
                this.toast(error.message, 'error');
            }
        },

        // ---- Message list ----

        search() {
            this.activeQuery = this.query.trim();
            this.closeMessage();
            this.reloadList(true);
        },

        async reloadList(resetPage) {
            if (resetPage) {
                this.page = 1;
            }

            this.selected = [];
            await this.loadList();
        },

        async goToPage(page) {
            this.page = Math.max(1, Math.min(this.pages, page));
            this.selected = [];
            await this.loadList();
            document.querySelector('.messages')?.scrollTo({ top: 0 });
        },

        async loadList(silent = false) {
            if (!this.folder) {
                return;
            }

            const token = ++this.listToken;

            if (!silent) {
                this.listLoading = true;
                this.listError = '';
            }

            try {
                const params = { folder: this.folder, page: String(this.page) };

                if (this.activeQuery) params.q = this.activeQuery;
                if (this.activeQuery && this.searchBody) params.body = '1';
                if (this.filterUnread) params.unread = '1';
                if (this.filterFlagged) params.flagged = '1';

                const data = await this.api('messages', params);

                if (token !== this.listToken) {
                    return;
                }

                this.messages = data.messages;
                this.page = data.page;
                this.pages = data.pages;
                this.total = data.total;
                this.selected = this.selected.filter((uid) => data.messages.some((message) => message.uid === uid));
                this.listError = '';
            } catch (error) {
                if (token === this.listToken && !silent) {
                    this.messages = [];
                    this.listError = error.message;
                }
            } finally {
                if (token === this.listToken) {
                    this.listLoading = false;
                    this.syncUrl();
                }
            }
        },

        listStatus() {
            if (this.activeQuery) {
                return this.t(':count found', { count: this.total });
            }

            const folder = this.currentFolder();

            if (!folder || folder.total === null) {
                return '';
            }

            return folder.unread > 0
                ? this.t(':total messages, :unread unread', { total: folder.total, unread: folder.unread })
                : this.t(':total messages', { total: folder.total });
        },

        correspondent(message) {
            const role = this.currentFolder()?.role;
            const people = role === 'sent' || role === 'drafts' ? message.to : message.from;
            const names = people.map((person) => person.name || person.email);

            if (!names.length) {
                return role === 'drafts' ? this.t('(no recipient)') : this.t('Unknown sender');
            }

            return (role === 'sent' || role === 'drafts' ? this.t('To:') + ' ' : '') + names.join(', ');
        },

        toggleSelect(uid, event) {
            const index = this.messages.findIndex((message) => message.uid === uid);

            if (event?.shiftKey && this.lastSelected !== null) {
                const from = this.messages.findIndex((message) => message.uid === this.lastSelected);
                const [start, end] = from < index ? [from, index] : [index, from];
                const range = this.messages.slice(start, end + 1).map((message) => message.uid);
                this.selected = [...new Set([...this.selected, ...range])];
            } else if (this.selected.includes(uid)) {
                this.selected = this.selected.filter((candidate) => candidate !== uid);
            } else {
                this.selected = [...this.selected, uid];
            }

            this.lastSelected = uid;
        },

        toggleAll(on) {
            this.selected = on ? this.messages.map((message) => message.uid) : [];
        },

        allSelected() {
            return this.messages.length > 0 && this.selected.length === this.messages.length;
        },

        someSelected() {
            return this.selected.length > 0 && this.selected.length < this.messages.length;
        },

        selectedAllFlagged() {
            return this.selected.length > 0 && this.selected.every((uid) => this.messages.find((message) => message.uid === uid)?.flags.flagged);
        },

        async flagSelected(flag, on) {
            await this.setFlag([...this.selected], flag, on);
        },

        async setFlag(uids, flag, on) {
            const previous = new Map();

            for (const message of this.messages) {
                if (uids.includes(message.uid)) {
                    previous.set(message.uid, message.flags[flag]);
                    message.flags[flag] = on;
                }
            }

            if (this.message && uids.includes(this.message.uid)) {
                this.message.flags[flag] = on;
            }

            if (flag === 'seen') {
                this.adjustUnread(this.folder, [...previous.values()].filter((was) => was !== on).length * (on ? -1 : 1));
            }

            try {
                await this.api('flag', {}, { folder: this.folder, uids, flag, on });
            } catch (error) {
                for (const message of this.messages) {
                    if (previous.has(message.uid)) {
                        message.flags[flag] = previous.get(message.uid);
                    }
                }

                this.toast(error.message, 'error');
                this.refreshFolders();
            }
        },

        toggleFlag(message) {
            this.setFlag([message.uid], 'flagged', !message.flags.flagged);
        },

        adjustUnread(folderId, delta) {
            const folder = this.folders.find((item) => item.id === folderId);

            if (folder && folder.unread !== null) {
                folder.unread = Math.max(0, folder.unread + delta);
                this.updateTitle();
            }
        },

        async markAllRead() {
            try {
                await this.api('mark-all-read', {}, { folder: this.folder });
                this.messages.forEach((message) => { message.flags.seen = true; });
                const folder = this.currentFolder();

                if (folder) {
                    folder.unread = 0;
                }

                this.updateTitle();
            } catch (error) {
                this.toast(error.message, 'error');
            }
        },

        async moveSelected(target) {
            await this.moveUids([...this.selected], target);
        },

        async deleteSelected() {
            await this.deleteUids([...this.selected]);
        },

        async moveUids(uids, target) {
            if (!uids.length || target === this.folder) {
                return;
            }

            const nextUid = this.nextAfter(uids);

            try {
                await this.api('move', {}, { folder: this.folder, uids, target });
                const name = this.folderName(this.folders.find((item) => item.id === target) || { name: target });
                this.toast(this.t(uids.length === 1 ? 'Moved to :folder.' : ':count messages moved to :folder.', { folder: name, count: uids.length }));
                this.removeFromList(uids, nextUid);
            } catch (error) {
                this.toast(error.message, 'error');
            }
        },

        async deleteUids(uids) {
            if (!uids.length) {
                return;
            }

            if (this.isTrashLike() && !window.confirm(this.t(uids.length === 1 ? 'Delete this message forever?' : 'Delete :count messages forever?', { count: uids.length }))) {
                return;
            }

            const nextUid = this.nextAfter(uids);

            try {
                const result = await this.api('delete', {}, { folder: this.folder, uids });
                this.toast(result.permanent
                    ? this.t(uids.length === 1 ? 'Message deleted.' : ':count messages deleted.', { count: uids.length })
                    : this.t(uids.length === 1 ? 'Moved to the trash.' : ':count messages moved to the trash.', { count: uids.length }));
                this.removeFromList(uids, nextUid);
            } catch (error) {
                this.toast(error.message, 'error');
            }
        },

        /**
         * The message to open once the given ones are gone: the next older
         * one, or the newer one when they were at the end.
         */
        nextAfter(uids) {
            if (!this.message || !uids.includes(this.message.uid)) {
                return null;
            }

            const index = this.messages.findIndex((message) => message.uid === this.message.uid);
            const rest = this.messages.filter((message) => !uids.includes(message.uid));
            const after = this.messages.slice(index + 1).find((message) => !uids.includes(message.uid));

            return after?.uid ?? rest[rest.length - 1]?.uid ?? null;
        },

        removeFromList(uids, nextUid) {
            this.messages = this.messages.filter((message) => !uids.includes(message.uid));
            this.selected = this.selected.filter((uid) => !uids.includes(uid));

            if (this.message && uids.includes(this.message.uid)) {
                if (nextUid && window.matchMedia('(min-width: 900px)').matches) {
                    this.openMessage(nextUid);
                } else {
                    this.closeMessage();
                }
            }

            this.refreshFolders();

            if (!this.messages.length && this.page > 1) {
                this.goToPage(this.page - 1);
            } else if (this.messages.length < 5 && this.pages > this.page) {
                this.loadList(true);
            }
        },

        async emptyFolder() {
            const folder = this.currentFolder();

            if (!folder || !window.confirm(this.t('Delete every message in :folder forever?', { folder: this.folderName(folder) }))) {
                return;
            }

            try {
                await this.api('empty', {}, { folder: folder.id });
                this.closeMessage();
                this.messages = [];
                await this.refreshFolders();
                await this.reloadList(true);
            } catch (error) {
                this.toast(error.message, 'error');
            }
        },

        dragStart(event, message) {
            const uids = this.selected.includes(message.uid) ? [...this.selected] : [message.uid];
            event.dataTransfer.setData('application/x-mail-simply', JSON.stringify({ folder: this.folder, uids }));
            event.dataTransfer.effectAllowed = 'move';
        },

        dropOn(item, event) {
            this.dropTarget = null;
            let data = null;

            try {
                data = JSON.parse(event.dataTransfer.getData('application/x-mail-simply') || 'null');
            } catch {
                data = null;
            }

            if (data && item.selectable && data.folder === this.folder) {
                this.moveUids(data.uids, item.id);
            }
        },

        async poll() {
            if (document.hidden) {
                return;
            }

            try {
                const { counts } = await this.api('poll');
                let changed = false;

                for (const folder of this.folders) {
                    const count = counts[folder.id];

                    if (!count) {
                        continue;
                    }

                    if (folder.id === this.folder && (count.total !== folder.total || count.uidnext !== folder.uidnext)) {
                        changed = true;
                    }

                    folder.total = count.total;
                    folder.unread = count.unread;
                    folder.uidnext = count.uidnext;
                }

                this.updateTitle();

                if (changed && this.page === 1 && !this.listLoading) {
                    this.loadList(true);
                }
            } catch {
                // A missed poll is caught up by the next one.
            }
        },

        updateTitle() {
            const inbox = this.folders.find((item) => item.role === 'inbox');
            const unread = inbox?.unread || 0;
            const base = document.title.replace(/^\(\d+\) /, '');
            document.title = (unread > 0 ? '(' + unread + ') ' : '') + base;
        },

        // ---- Reading ----

        async openMessage(uid) {
            const token = ++this.messageToken;
            this.uid = uid;
            this.view = 'reader';
            this.messageLoading = true;
            this.blocked = 0;
            this.plainView = false;
            this.syncUrl();

            try {
                const message = await this.api('message', { folder: this.folder, uid: String(uid) });

                if (token !== this.messageToken) {
                    return;
                }

                this.remoteAllowed = message.trusted;
                this.message = message;

                const listed = this.messages.find((item) => item.uid === uid);

                if (listed && !listed.flags.seen) {
                    listed.flags.seen = true;
                    this.adjustUnread(this.folder, -1);
                }

                this.$nextTick(() => document.querySelector('.reader-pane')?.scrollTo({ top: 0 }));
            } catch (error) {
                if (token === this.messageToken) {
                    this.toast(error.message, 'error');
                    this.closeMessage();
                }
            } finally {
                if (token === this.messageToken) {
                    this.messageLoading = false;
                }
            }
        },

        closeMessage() {
            this.messageToken++;
            this.uid = null;
            this.message = null;
            this.messageLoading = false;
            this.view = 'list';
            this.frameObserver?.disconnect();
            this.syncUrl();
        },

        frameUrl() {
            if (!this.message) {
                return 'about:blank';
            }

            const params = new URLSearchParams({ folder: this.message.folder, uid: String(this.message.uid) });

            if (this.remoteAllowed) params.set('remote', '1');
            if (this.plainView) params.set('plain', '1');

            return 'frame.php?' + params.toString();
        },

        /**
         * Size the frame to its content, and take over mailto: links so they
         * open here instead of in whatever the system has for mail.
         */
        frameLoaded(frame) {
            let doc = null;

            try {
                doc = frame.contentDocument;
            } catch {
                doc = null;
            }

            if (!doc || !doc.body) {
                return;
            }

            const meta = doc.querySelector('meta[name="mail-simply-blocked"]');
            this.blocked = meta ? parseInt(meta.content, 10) || 0 : 0;

            const resize = () => {
                frame.style.height = Math.max(160, doc.documentElement.scrollHeight) + 'px';
            };

            resize();
            this.frameObserver?.disconnect();

            if (window.ResizeObserver) {
                this.frameObserver = new ResizeObserver(resize);
                this.frameObserver.observe(doc.body);
            }

            doc.addEventListener('load', resize, true);
            doc.addEventListener('click', (event) => {
                const link = event.target.closest ? event.target.closest('a[href]') : null;

                if (link && /^mailto:/i.test(link.getAttribute('href'))) {
                    event.preventDefault();
                    this.composeMailto(link.getAttribute('href'));
                }
            });
        },

        allowRemote() {
            this.remoteAllowed = true;
        },

        async trustSender() {
            const sender = this.message?.from[0]?.email;

            if (!sender) {
                return;
            }

            try {
                const result = await this.api('trust', {}, { sender });
                this.settings.trusted = result.trusted;
                this.remoteAllowed = true;
            } catch (error) {
                this.toast(error.message, 'error');
            }
        },

        togglePlain() {
            this.plainView = !this.plainView;
        },

        markUnread() {
            if (this.message) {
                this.setFlag([this.message.uid], 'seen', false);
                this.closeMessage();
            }
        },

        moveMessage(role, target = null) {
            const destination = target ?? this.folders.find((item) => item.role === role)?.id;

            if (!destination) {
                this.toast(this.t('This mailbox has no junk folder.'), 'error');
                return;
            }

            this.moveUids([this.message.uid], destination);
        },

        deleteMessage() {
            if (this.message) {
                this.deleteUids([this.message.uid]);
            }
        },

        attachmentUrl(part, inline = false) {
            if (!this.message) {
                return '#';
            }

            const params = new URLSearchParams({ folder: this.message.folder, uid: String(this.message.uid), part });

            if (inline) {
                params.set('inline', '1');
            }

            return 'attachment.php?' + params.toString();
        },

        recipientRows() {
            if (!this.message) {
                return [];
            }

            const format = (list) => list.map((person) => person.name ? person.name + ' <' + person.email + '>' : person.email).join(', ');
            const rows = [];

            if (this.message.to.length) rows.push({ label: this.t('To:'), value: format(this.message.to) });
            if (this.message.cc.length) rows.push({ label: this.t('Cc:'), value: format(this.message.cc) });
            if (this.message.bcc.length) rows.push({ label: this.t('Bcc:'), value: format(this.message.bcc) });

            const from = this.message.from[0]?.email;

            if (this.message.replyTo.length && this.message.replyTo[0].email !== from) {
                rows.push({ label: this.t('Reply to:'), value: format(this.message.replyTo) });
            }

            return rows;
        },

        // ---- Writing ----

        blankComposer(mode) {
            return {
                mode,
                to: [],
                cc: [],
                bcc: [],
                typing: { to: '', cc: '', bcc: '' },
                show: { cc: false, bcc: false },
                subject: '',
                html: this.settings.html,
                text: '',
                attachments: [],
                inReplyTo: null,
                references: null,
                source: null,
                draft: null,
                dirty: false,
                sending: false,
                saving: false,
                status: '',
                suggestions: [],
                suggestFor: null,
                suggestIndex: 0,
                dragging: false,
            };
        },

        signatureHtml() {
            return this.settings.signature ? '<div class="signature">' + this.settings.signature + '</div>' : '';
        },

        signatureText() {
            if (!this.settings.signature) {
                return '';
            }

            const holder = document.createElement('div');
            holder.innerHTML = this.settings.signature;

            return holder.innerText.trim();
        },

        async compose(mode = 'new', message = null, preset = {}) {
            if (this.composer && this.composer.dirty && !window.confirm(this.t('Discard the message you are writing?'))) {
                return;
            }

            this.stopAutosave();
            this.composer = Object.assign(this.blankComposer(mode), preset);

            // The reactive proxy, not the plain object: changes made through
            // anything else would never reach the page.
            const composer = this.composer;
            composer.show.cc = composer.cc.length > 0;
            composer.show.bcc = composer.bcc.length > 0;

            let body = '<p><br></p>' + this.signatureHtml();
            let text = '\n\n' + (this.settings.signature ? '-- \n' + this.signatureText() + '\n' : '');

            if (message) {
                composer.status = this.t('Loading…');

                try {
                    const prefill = await this.api('compose', {
                        mode,
                        folder: message.folder,
                        uid: String(message.uid),
                        tz: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
                    });

                    if (this.composer !== composer) {
                        return;
                    }

                    Object.assign(composer, {
                        to: prefill.to,
                        cc: prefill.cc,
                        bcc: prefill.bcc,
                        subject: prefill.subject,
                        inReplyTo: prefill.inReplyTo,
                        references: prefill.references,
                        source: prefill.source,
                        draft: prefill.draft,
                        attachments: prefill.attachments.map((file) => ({ ...file, key: file.id, progress: 100, error: null })),
                    });

                    composer.show.cc = composer.cc.length > 0;
                    composer.show.bcc = composer.bcc.length > 0;

                    if (mode === 'draft') {
                        composer.html = prefill.html !== null;
                        body = prefill.html ?? '';
                        text = prefill.text ?? '';
                    } else {
                        body = '<p><br></p>' + (this.settings.signature ? this.signatureHtml() : '') + '<br>' + prefill.quoteHtml;
                        text = '\n\n' + (this.settings.signature ? '-- \n' + this.signatureText() + '\n\n' : '') + prefill.quoteText;
                    }
                } catch (error) {
                    this.toast(error.message, 'error');
                    this.composer = null;
                    return;
                }

                composer.status = '';
            }

            composer.text = text;
            this.$nextTick(() => {
                if (this.$refs.editor) {
                    this.$refs.editor.innerHTML = body;
                }

                const focusTarget = composer.to.length === 0 ? document.getElementById('field-to') : (composer.html ? this.$refs.editor : this.$refs.plainEditor);
                focusTarget?.focus();

                if (focusTarget && focusTarget === this.$refs.editor) {
                    const range = document.createRange();
                    range.setStart(this.$refs.editor, 0);
                    range.collapse(true);
                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                } else if (focusTarget === this.$refs.plainEditor) {
                    this.$refs.plainEditor.setSelectionRange(0, 0);
                    this.$refs.plainEditor.scrollTop = 0;
                }
            });

            this.autosaveTimer = window.setInterval(() => this.autosave(), AUTOSAVE_INTERVAL);
        },

        composeTo(person) {
            this.compose('new', null, { to: [{ name: person.name || '', email: person.email }] });
        },

        composeMailto(href) {
            let url = null;

            try {
                url = new URL(href);
            } catch {
                return;
            }

            const to = parseAddresses(decodeURIComponent(url.pathname || ''));
            const preset = { to };

            for (const [key, value] of url.searchParams) {
                const name = key.toLowerCase();

                if (name === 'subject') preset.subject = value;
                if (name === 'cc') preset.cc = parseAddresses(value);
                if (name === 'bcc') preset.bcc = parseAddresses(value);
                if (name === 'to') preset.to = [...to, ...parseAddresses(value)];
            }

            this.compose('new', null, preset);
        },

        touch() {
            if (this.composer) {
                this.composer.dirty = true;
            }
        },

        validEmail(email) {
            return EMAIL.test(email);
        },

        commitRecipient(kind) {
            const composer = this.composer;

            if (!composer) {
                return;
            }

            const typed = composer.typing[kind];

            if (typed.trim() !== '') {
                composer[kind] = [...composer[kind], ...parseAddresses(typed)];
                composer.typing[kind] = '';
                this.touch();
            }

            if (composer.suggestFor === kind) {
                composer.suggestions = [];
                composer.suggestFor = null;
            }
        },

        removeRecipient(kind, index) {
            this.composer[kind].splice(index, 1);
            this.touch();
        },

        recipientKey(event, kind) {
            const composer = this.composer;
            const open = composer.suggestFor === kind && composer.suggestions.length > 0;

            if (open && event.key === 'ArrowDown') {
                event.preventDefault();
                composer.suggestIndex = (composer.suggestIndex + 1) % composer.suggestions.length;
                return;
            }

            if (open && event.key === 'ArrowUp') {
                event.preventDefault();
                composer.suggestIndex = (composer.suggestIndex - 1 + composer.suggestions.length) % composer.suggestions.length;
                return;
            }

            if (open && (event.key === 'Enter' || event.key === 'Tab')) {
                event.preventDefault();
                this.pickSuggestion(kind, composer.suggestions[composer.suggestIndex]);
                return;
            }

            if (event.key === 'Escape' && open) {
                event.stopPropagation();
                composer.suggestions = [];
                return;
            }

            if ((event.key === 'Enter' || event.key === ',' || event.key === ';') && composer.typing[kind].trim() !== '') {
                event.preventDefault();
                this.commitRecipient(kind);
                return;
            }

            if (event.key === 'Backspace' && composer.typing[kind] === '' && composer[kind].length) {
                composer[kind].pop();
                this.touch();
            }
        },

        suggest(kind) {
            const composer = this.composer;
            const query = composer.typing[kind].trim();
            window.clearTimeout(this.suggestTimer);

            if (query.length < 1) {
                composer.suggestions = [];
                return;
            }

            this.suggestTimer = window.setTimeout(async () => {
                try {
                    const results = await this.api('contacts', { q: query });
                    const taken = new Set([...composer.to, ...composer.cc, ...composer.bcc].map((entry) => entry.email.toLowerCase()));

                    if (this.composer === composer && composer.typing[kind].trim() === query) {
                        composer.suggestions = results.filter((entry) => !taken.has(entry.email.toLowerCase()));
                        composer.suggestFor = kind;
                        composer.suggestIndex = 0;
                    }
                } catch {
                    // Completion is a convenience.
                }
            }, 150);
        },

        pickSuggestion(kind, item) {
            this.composer[kind] = [...this.composer[kind], { name: item.name, email: item.email }];
            this.composer.typing[kind] = '';
            this.composer.suggestions = [];
            this.composer.suggestFor = null;
            this.touch();
            document.getElementById('field-' + kind)?.focus();
        },

        format(command, value = null) {
            document.execCommand(command, false, value);
            this.$refs.editor?.focus();
            this.touch();
        },

        insertLink() {
            const url = window.prompt(this.t('Link address'), 'https://');

            if (url && /^(https?:|mailto:)/i.test(url.trim())) {
                this.format('createLink', url.trim());
            }
        },

        /**
         * Images pasted from the clipboard go into the text as data: URLs;
         * sending turns them into parts of the message.
         */
        pasteInto(event) {
            const files = [...(event.clipboardData?.files || [])].filter((file) => file.type.startsWith('image/'));

            if (!files.length) {
                return;
            }

            event.preventDefault();

            for (const file of files) {
                if (file.size > 2 * 1024 * 1024) {
                    this.addFiles([file]);
                    continue;
                }

                const reader = new FileReader();
                reader.onload = () => {
                    document.execCommand('insertImage', false, reader.result);
                    this.touch();
                };
                reader.readAsDataURL(file);
            }
        },

        togglePlainCompose() {
            const composer = this.composer;

            if (composer.html) {
                if (this.$refs.editor.innerText.trim() !== '' && !window.confirm(this.t('Switching to plain text removes formatting and images. Continue?'))) {
                    return;
                }

                composer.text = this.$refs.editor.innerText.replace(/\n{3,}/g, '\n\n');
                composer.html = false;
            } else {
                composer.html = true;
                this.$nextTick(() => {
                    this.$refs.editor.innerHTML = textToHtml(composer.text);
                });
            }

            this.touch();
        },

        addFiles(fileList) {
            const composer = this.composer;
            const files = [...fileList];

            for (const file of files) {
                const used = composer.attachments.reduce((sum, item) => sum + (item.error ? 0 : item.size), 0);

                if (used + file.size > this.limits.attachments) {
                    this.toast(this.t('The attachments are larger than the :size allowed.', { size: this.bytes(this.limits.attachments) }), 'error');
                    continue;
                }

                const entry = { key: 'k' + Math.random().toString(36).slice(2), id: null, name: file.name, size: file.size, type: file.type, progress: 0, error: null };
                composer.attachments.push(entry);
                this.upload(file, composer.attachments[composer.attachments.length - 1]);
            }

            this.touch();
        },

        upload(file, entry) {
            const form = new FormData();
            form.append('file', file);

            const request = new XMLHttpRequest();
            request.open('POST', 'upload.php');
            request.setRequestHeader('X-CSRF-Token', this.csrf);
            request.upload.onprogress = (event) => {
                if (event.lengthComputable) {
                    entry.progress = Math.min(99, Math.round(event.loaded / event.total * 100));
                }
            };
            request.onload = () => {
                let payload = null;

                try {
                    payload = JSON.parse(request.responseText);
                } catch {
                    payload = null;
                }

                if (request.status === 200 && payload?.data) {
                    entry.id = payload.data.id;
                    entry.size = payload.data.size;
                    entry.progress = 100;
                } else {
                    entry.error = payload?.error || this.t('Upload failed');
                    entry.progress = 100;
                }
            };
            request.onerror = () => {
                entry.error = this.t('Upload failed');
                entry.progress = 100;
            };
            request.send(form);
        },

        dropFiles(event) {
            this.composer.dragging = false;

            if (event.dataTransfer?.files?.length) {
                this.addFiles(event.dataTransfer.files);
            }
        },

        removeAttachment(index) {
            const [removed] = this.composer.attachments.splice(index, 1);

            if (removed?.id?.startsWith('u:')) {
                this.api('discard-upload', {}, { id: removed.id.slice(2) }).catch(() => {});
            }

            this.touch();
        },

        uploading() {
            return this.composer?.attachments.some((file) => !file.error && file.progress < 100);
        },

        payload() {
            const composer = this.composer;

            return {
                to: composer.to,
                cc: composer.cc,
                bcc: composer.bcc,
                subject: composer.subject,
                html: composer.html ? this.$refs.editor.innerHTML : null,
                text: composer.html ? null : composer.text,
                attachments: composer.attachments.filter((file) => file.id && !file.error).map((file) => file.id),
                inReplyTo: composer.inReplyTo,
                references: composer.references,
                source: composer.source,
                draft: composer.draft,
            };
        },

        hasContent() {
            const composer = this.composer;
            const body = composer.html ? (this.$refs.editor?.innerText || '') : composer.text;

            return composer.to.length + composer.cc.length + composer.bcc.length > 0
                || composer.subject.trim() !== ''
                || composer.attachments.length > 0
                || body.replace(/--\s[\s\S]*$/, '').trim() !== '';
        },

        async send() {
            const composer = this.composer;

            for (const kind of ['to', 'cc', 'bcc']) {
                this.commitRecipient(kind);
            }

            const all = [...composer.to, ...composer.cc, ...composer.bcc];

            if (!all.length) {
                this.toast(this.t('Add at least one recipient.'), 'error');
                document.getElementById('field-to')?.focus();
                return;
            }

            const invalid = all.find((entry) => !this.validEmail(entry.email));

            if (invalid) {
                this.toast(this.t('":address" is not a valid address.', { address: invalid.email }), 'error');
                return;
            }

            if (composer.subject.trim() === '' && !window.confirm(this.t('Send this message without a subject?'))) {
                return;
            }

            const body = composer.html ? this.$refs.editor.innerText : composer.text;
            const mentionsAttachment = /\b(attach(ed|ment)|enclosed|csatol|mellékel)/i.test(body.split(/\n>|\n-- \n/)[0]);

            if (mentionsAttachment && !composer.attachments.length && !window.confirm(this.t('You mention an attachment, but nothing is attached. Send anyway?'))) {
                return;
            }

            composer.sending = true;
            composer.status = this.t('Sending…');
            this.stopAutosave();

            try {
                const result = await this.api('send', {}, this.payload());
                this.composer = null;
                this.toast(result.savedToSent ? this.t('Message sent.') : this.t('Message sent, but no copy could be saved in Sent.'), result.savedToSent ? 'success' : 'error');

                if (this.message && composer.source?.uid === this.message.uid) {
                    this.message.flags[composer.source.mode === 'forward' ? 'forwarded' : 'answered'] = true;
                }

                const listed = this.messages.find((item) => item.uid === composer.source?.uid);

                if (listed) {
                    listed.flags[composer.source.mode === 'forward' ? 'forwarded' : 'answered'] = true;
                }

                await this.refreshFolders();

                if (['sent', 'drafts'].includes(this.currentFolder()?.role)) {
                    this.loadList(true);
                }
            } catch (error) {
                if (this.composer === composer) {
                    composer.sending = false;
                    composer.status = '';
                    this.autosaveTimer = window.setInterval(() => this.autosave(), AUTOSAVE_INTERVAL);
                }

                this.toast(error.message, 'error', null, 10000);
            }
        },

        async saveDraft(quiet) {
            const composer = this.composer;

            if (!composer || composer.saving || composer.sending) {
                return false;
            }

            if (this.uploading()) {
                if (!quiet) {
                    this.toast(this.t('Wait for the attachments to finish uploading.'), 'error');
                }

                return false;
            }

            composer.saving = true;
            composer.status = this.t('Saving…');

            try {
                const draft = await this.api('draft', {}, this.payload());
                composer.draft = draft;
                composer.dirty = false;
                composer.status = this.t('Draft saved at :time', { time: new Date().toLocaleTimeString(this.lang, { hour: '2-digit', minute: '2-digit' }) });

                if (this.isDraftFolder()) {
                    this.loadList(true);
                }

                this.refreshFolders();

                return true;
            } catch (error) {
                composer.status = '';

                if (!quiet) {
                    this.toast(error.message, 'error');
                }

                return false;
            } finally {
                composer.saving = false;
            }
        },

        autosave() {
            if (this.composer?.dirty && !this.composer.sending && this.hasContent()) {
                this.saveDraft(true);
            }
        },

        stopAutosave() {
            window.clearInterval(this.autosaveTimer);
            this.autosaveTimer = null;
        },

        /**
         * Closing keeps what was written, as a draft; only Discard throws it
         * away.
         */
        async closeComposer() {
            const composer = this.composer;

            if (!composer) {
                return;
            }

            if (composer.dirty && this.hasContent()) {
                const saved = await this.saveDraft(false);

                if (!saved) {
                    if (!window.confirm(this.t('The draft could not be saved. Close anyway and lose it?'))) {
                        return;
                    }
                } else {
                    this.toast(this.t('Saved to Drafts.'));
                }
            }

            this.stopAutosave();
            this.composer = null;
        },

        async discardComposer() {
            const composer = this.composer;

            if (!composer) {
                return;
            }

            if ((composer.dirty || composer.draft) && this.hasContent() && !window.confirm(this.t('Discard this message?'))) {
                return;
            }

            this.stopAutosave();
            this.composer = null;

            for (const file of composer.attachments) {
                if (file.id?.startsWith('u:')) {
                    this.api('discard-upload', {}, { id: file.id.slice(2) }).catch(() => {});
                }
            }

            if (composer.draft) {
                try {
                    await this.api('discard-draft', {}, { draft: composer.draft });

                    if (this.isDraftFolder()) {
                        if (this.message?.uid === composer.draft.uid) {
                            this.closeMessage();
                        }

                        this.loadList(true);
                    }

                    this.refreshFolders();
                } catch (error) {
                    this.toast(error.message, 'error');
                }
            }
        },

        // ---- Settings ----

        openSettings() {
            this.settingsForm = {
                name: this.settings.name || '',
                signature: this.settings.signature || '',
                format: this.settings.html ? 'html' : 'plain',
                language: this.settings.language || '',
                trusted: [...this.settings.trusted],
                saving: false,
            };
        },

        async saveSettings() {
            const form = this.settingsForm;
            form.saving = true;
            const languageChanged = (form.language || null) !== (this.settings.language || null);

            try {
                this.settings = await this.api('settings', {}, {
                    name: form.name,
                    signature: this.$refs.signatureEditor?.innerHTML || '',
                    html: form.format === 'html',
                    language: form.language || null,
                    trusted: form.trusted,
                });

                this.settingsForm = null;

                if (languageChanged) {
                    window.location.reload();
                    return;
                }

                this.toast(this.t('Settings saved.'), 'success');
            } catch (error) {
                form.saving = false;
                this.toast(error.message, 'error');
            }
        },

        // ---- Keyboard ----

        shortcut(event) {
            const target = event.target;
            const typing = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement || target?.isContentEditable;

            if (event.key === 'Escape') {
                if (this.composer || this.settingsForm || this.folderDialog) {
                    return;
                }

                if (typing && target === this.$refs.search) {
                    target.blur();
                    return;
                }

                if (this.message) {
                    this.closeMessage();
                }

                return;
            }

            if (typing || this.composer || this.settingsForm || this.folderDialog || event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            const index = this.messages.findIndex((message) => message.uid === this.uid);
            const actions = {
                c: () => this.compose(),
                r: () => this.message && !this.isDraftFolder() && this.compose('reply', this.message),
                a: () => this.message && !this.isDraftFolder() && this.compose('replyAll', this.message),
                f: () => this.message && !this.isDraftFolder() && this.compose('forward', this.message),
                s: () => this.message && this.toggleFlag(this.message),
                u: () => this.message && this.closeMessage(),
                '/': () => this.$refs.search?.focus(),
                '#': () => this.message && this.deleteMessage(),
                Delete: () => this.message ? this.deleteMessage() : (this.selected.length && this.deleteSelected()),
                j: () => this.messages[index + 1] && this.openMessage(this.messages[index + 1].uid),
                k: () => index > 0 && this.openMessage(this.messages[index - 1].uid),
            };

            const action = actions[event.key];

            if (action) {
                event.preventDefault();
                action();
            }
        },

        // ---- Toasts ----

        toast(text, kind = 'info', action = null, timeout = null) {
            const id = ++toastId;
            this.toasts.push({ id, text, kind, action });
            const delay = timeout ?? (kind === 'error' ? 8000 : 4000);

            if (delay > 0) {
                window.setTimeout(() => this.dismiss(id), delay);
            }
        },

        dismiss(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },

        // ---- Formatting ----

        initials(text) {
            const name = String(text || '').replace(/@.*$/, '').replace(/[^\p{L}\p{N}\s._-]/gu, ' ').trim();
            const parts = name.split(/[\s._-]+/).filter(Boolean);

            return ((parts[0]?.[0] || '?') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
        },

        shortDate(iso) {
            if (!iso) {
                return '';
            }

            const date = new Date(iso);
            const now = new Date();

            if (date.toDateString() === now.toDateString()) {
                return date.toLocaleTimeString(this.lang, { hour: '2-digit', minute: '2-digit' });
            }

            if (date.getFullYear() === now.getFullYear()) {
                return date.toLocaleDateString(this.lang, { month: 'short', day: 'numeric' });
            }

            return date.toLocaleDateString(this.lang, { year: 'numeric', month: 'short', day: 'numeric' });
        },

        longDate(iso) {
            return iso ? new Date(iso).toLocaleString(this.lang, { dateStyle: 'medium', timeStyle: 'short' }) : '';
        },

        bytes(size) {
            if (size >= 1073741824) return (size / 1073741824).toFixed(1) + ' GB';
            if (size >= 1048576) return (size / 1048576).toFixed(1) + ' MB';
            if (size >= 1024) return Math.round(size / 1024) + ' KB';

            return size + ' B';
        },

        extension(name) {
            const match = String(name).match(/\.([a-z0-9]{1,5})$/i);

            return match ? match[1].toUpperCase() : 'FILE';
        },
    }));
});
