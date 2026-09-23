<?php

declare(strict_types=1);

use MailSimply\Http;
use MailSimply\Lang;
use MailSimply\Session;

$config = require dirname(__DIR__).'/bootstrap.php';

mail_simply_headers();
header('Content-Type: text/html; charset=utf-8');

$session = new Session($config);
$grant = $session->grant();
$lang = Http::lang($config, $grant['address'] ?? null);
$t = static fn (string $text, array $replace = []): string => htmlspecialchars($lang->get($text, $replace), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$title = $config->string('title');
$version = trim((string) @file_get_contents(dirname(__DIR__).'/VERSION')) ?: 'dev';
$asset = static fn (string $path): string => $path.'?v='.rawurlencode($version);
$panelUrl = $config->string('panel_url');
$supportUrl = $config->string('support_url');

$login = null;

if ($grant === null) {
    $login = $_SESSION['login'] ?? ['error' => null, 'address' => null];
    unset($_SESSION['login']);
    $_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));
    $prefill = is_string($_GET['user'] ?? null) ? mb_substr($_GET['user'], 0, 254) : ($login['address'] ?? '');
}
?><!doctype html>
<html lang="<?= $e($lang->code) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?></title>
    <link rel="icon" href="<?= $e($asset('assets/icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($asset('assets/app.css')) ?>">
<?php if ($grant !== null) { ?>
    <meta name="csrf-token" content="<?= $e($session->csrf()) ?>">
    <script type="application/json" id="mail-simply-lang"><?= json_encode(['code' => $lang->code, 'lines' => $lang->all(), 'languages' => Lang::AVAILABLE], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <script src="<?= $e($asset('assets/app.js')) ?>" defer></script>
    <script src="<?= $e($asset('assets/vendor/alpine.min.js')) ?>" defer></script>
<?php } ?>
</head>
<body>
<?php if ($grant === null) { ?>
    <main class="signed-out">
        <div class="card">
            <div class="brand-large">
                <img src="<?= $e($asset('assets/icon.svg')) ?>" alt="" width="36" height="36">
                <h1><?= $e($title) ?></h1>
            </div>
<?php if ($config->bool('login.enabled')) { ?>
            <form method="post" action="login.php" class="login">
                <input type="hidden" name="csrf" value="<?= $e($_SESSION['login_csrf']) ?>">
<?php if (($login['error'] ?? null) !== null) { ?>
                <p class="alert" role="alert"><?= $t($login['error']) ?></p>
<?php } ?>
                <label>
                    <span><?= $t('Email address') ?></span>
                    <input type="text" name="address" value="<?= $e($prefill) ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required <?= $prefill === '' ? 'autofocus' : '' ?>>
                </label>
                <label>
                    <span><?= $t('Password') ?></span>
                    <input type="password" name="password" autocomplete="current-password" required <?= $prefill !== '' ? 'autofocus' : '' ?>>
                </label>
                <button type="submit" class="button primary wide"><?= $t('Sign in') ?></button>
            </form>
<?php } else { ?>
            <p><?= $t('You are not signed in, or your session has ended.') ?></p>
            <p class="muted"><?= $t('Open webmail again from your control panel to start a new session.') ?></p>
<?php } ?>
<?php if ($panelUrl !== '') { ?>
            <p class="muted small"><a href="<?= $e($panelUrl) ?>" rel="noopener"><?= $t('Go to the control panel') ?></a></p>
<?php } ?>
        </div>
    </main>
<?php } else { ?>
<div class="app" x-data="mailSimply" x-cloak @keydown.window="shortcut($event)" :class="{ 'show-reader': view === 'reader', 'show-folders': foldersOpen }">
    <header class="topbar">
        <button type="button" class="icon-button menu-toggle" @click="foldersOpen = !foldersOpen" :aria-label="t('Folders')">
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
        </button>
        <div class="brand">
            <img src="<?= $e($asset('assets/icon.svg')) ?>" alt="" width="22" height="22">
            <strong><?= $e($title) ?></strong>
        </div>
        <form class="search" role="search" @submit.prevent="search()">
            <input type="search" x-ref="search" x-model="query" :placeholder="t('Search mail')" :aria-label="t('Search mail')" autocomplete="off" spellcheck="false">
            <label class="search-body" :title="t('Also search the message text. Slower in large folders.')">
                <input type="checkbox" x-model="searchBody" @change="query && search()">
                <span x-text="t('Text')"></span>
            </label>
        </form>
        <div class="account menu" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" class="account-button" @click="open = !open" :aria-expanded="open">
                <span class="avatar" x-text="initials(settings.name || address)"></span>
                <span class="account-address" x-text="address"></span>
            </button>
            <div class="menu-items right" x-show="open" x-transition.opacity @click="open = false">
                <div class="menu-heading">
                    <strong x-text="settings.name || address"></strong>
                    <span class="muted small" x-text="address"></span>
                </div>
                <button type="button" @click="openSettings()" x-text="t('Settings')"></button>
<?php if ($supportUrl !== '') { ?>
                <a href="<?= $e($supportUrl) ?>" target="_blank" rel="noopener" x-text="t('Help')"></a>
<?php } ?>
<?php if ($panelUrl !== '') { ?>
                <a href="<?= $e($panelUrl) ?>" rel="noopener" x-text="t('Control panel')"></a>
<?php } ?>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf" :value="csrf">
                    <button type="submit" x-text="t('Sign out')"></button>
                </form>
            </div>
        </div>
    </header>

    <div class="layout">
        <aside class="sidebar" @click.self="foldersOpen = false">
            <nav class="folders-panel">
                <button type="button" class="button primary compose-button" @click="compose()">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 16h3l8.5-8.5-3-3L4 13v3ZM11.5 5.5l3 3"/></svg>
                    <span x-text="t('Compose')"></span>
                </button>

                <ul class="folders" :aria-label="t('Folders')">
                    <template x-for="item in visibleFolders()" :key="item.id">
                        <li :class="{ active: folder === item.id, 'drop-target': dropTarget === item.id }"
                            @dragover.prevent="item.selectable && (dropTarget = item.id)"
                            @dragleave="dropTarget = null"
                            @drop.prevent="dropOn(item, $event)">
                            <button type="button" class="folder" :style="`--depth: ${item.depth}`" @click="openFolder(item.id)" :disabled="!item.selectable" :title="item.path">
                                <span class="twisty" x-show="hasChildren(item)" @click.stop="toggleCollapsed(item.id)" x-text="collapsed[item.id] ? '▸' : '▾'"></span>
                                <span class="folder-icon" x-html="folderIcon(item.role)"></span>
                                <span class="folder-name" x-text="folderName(item)"></span>
                                <span class="count" x-show="item.unread > 0 && item.role !== 'sent' && item.role !== 'trash'" x-text="item.unread"></span>
                                <span class="count muted" x-show="item.role === 'drafts' && item.total > 0" x-text="item.total"></span>
                            </button>
                        </li>
                    </template>
                </ul>

                <div class="folder-tools">
                    <button type="button" class="link small" @click="openFolderDialog('create')" x-text="t('New folder')"></button>
                    <button type="button" class="link small" x-show="currentFolder() && !currentFolder().role" @click="openFolderDialog('rename')" x-text="t('Rename')"></button>
                    <button type="button" class="link small danger" x-show="currentFolder() && !currentFolder().role" @click="deleteFolder()" x-text="t('Delete')"></button>
                </div>

                <div class="quota" x-show="quota && quota.limit > 0">
                    <div class="quota-bar" :class="{ warn: quotaRatio() > 0.85 }"><span :style="`width: ${Math.min(100, quotaRatio() * 100)}%`"></span></div>
                    <span class="small muted" x-text="t(':used of :limit used', { used: bytes(quota?.used || 0), limit: bytes(quota?.limit || 0) })"></span>
                </div>
            </nav>
        </aside>

        <section class="list-pane" :aria-busy="listLoading ? 'true' : 'false'">
            <div class="list-head">
                <div class="list-title">
                    <h2 x-text="currentFolder() ? folderName(currentFolder()) : ''"></h2>
                    <span class="muted small" x-text="listStatus()"></span>
                </div>
                <div class="list-filters">
                    <button type="button" class="chip" :class="{ on: filterUnread }" @click="filterUnread = !filterUnread; reloadList(true)" x-text="t('Unread')"></button>
                    <button type="button" class="chip" :class="{ on: filterFlagged }" @click="filterFlagged = !filterFlagged; reloadList(true)" x-text="t('Flagged')"></button>
                    <span class="spacer"></span>
                    <button type="button" class="icon-button" @click="reloadList(false)" :title="t('Refresh')" :aria-label="t('Refresh')" :class="{ spinning: listLoading }">
                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M16 10a6 6 0 1 1-2-4.5M16 3v3.5h-3.5"/></svg>
                    </button>
                </div>
                <div class="bulk" x-show="messages.length">
                    <label class="check">
                        <input type="checkbox" :checked="allSelected()" :indeterminate="someSelected()" @change="toggleAll($event.target.checked)" :aria-label="t('Select all')">
                    </label>
                    <template x-if="selected.length">
                        <div class="bulk-actions">
                            <span class="small" x-text="t(':count selected', { count: selected.length })"></span>
                            <button type="button" class="button small" @click="flagSelected('seen', true)" x-text="t('Mark read')"></button>
                            <button type="button" class="button small" @click="flagSelected('seen', false)" x-text="t('Mark unread')"></button>
                            <button type="button" class="button small" @click="flagSelected('flagged', !selectedAllFlagged())" x-text="selectedAllFlagged() ? t('Unflag') : t('Flag')"></button>
                            <div class="menu" x-data="{ open: false }" @click.outside="open = false">
                                <button type="button" class="button small" @click="open = !open" x-text="t('Move to')"></button>
                                <div class="menu-items scroll" x-show="open" @click="open = false">
                                    <template x-for="target in moveTargets()" :key="target.id">
                                        <button type="button" :style="`padding-left: ${12 + target.depth * 12}px`" @click="moveSelected(target.id)" x-text="folderName(target)"></button>
                                    </template>
                                </div>
                            </div>
                            <button type="button" class="button small danger" @click="deleteSelected()" x-text="isTrashLike() ? t('Delete forever') : t('Delete')"></button>
                        </div>
                    </template>
                    <template x-if="!selected.length">
                        <div class="bulk-actions">
                            <button type="button" class="link small" x-show="currentFolder() && currentFolder().unread > 0" @click="markAllRead()" x-text="t('Mark all read')"></button>
                            <button type="button" class="link small danger" x-show="isTrashLike() && messages.length" @click="emptyFolder()" x-text="currentFolder()?.role === 'junk' ? t('Empty junk') : t('Empty trash')"></button>
                        </div>
                    </template>
                </div>
            </div>

            <ul class="messages" :aria-label="t('Messages')">
                <template x-for="row in messages" :key="row.uid">
                    <li :class="{ unread: !row.flags.seen, active: uid === row.uid, selected: selected.includes(row.uid) }"
                        draggable="true" @dragstart="dragStart($event, row)">
                        <label class="check" @click.stop>
                            <input type="checkbox" :checked="selected.includes(row.uid)" @change="toggleSelect(row.uid, $event)" :aria-label="t('Select')">
                        </label>
                        <button type="button" class="message-row" @click="openMessage(row.uid)">
                            <span class="row-top">
                                <span class="correspondent" x-text="correspondent(row)"></span>
                                <span class="row-icons">
                                    <span x-show="row.attachments" class="icon" :title="t('Has attachments')">
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M14 9.5 9 14.5a3 3 0 0 1-4.3-4.3l5.8-5.7a2 2 0 0 1 2.8 2.8L7.6 13"/></svg>
                                    </span>
                                    <span x-show="row.flags.answered" class="icon" :title="t('Answered')">
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M8 5 3 10l5 5M3 10h9a5 5 0 0 1 5 5"/></svg>
                                    </span>
                                    <span x-show="row.flags.forwarded" class="icon" :title="t('Forwarded')">
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12 5 5 5-5 5M17 10H8a5 5 0 0 0-5 5"/></svg>
                                    </span>
                                </span>
                                <time class="date" :datetime="row.date" x-text="shortDate(row.date)" :title="longDate(row.date)"></time>
                            </span>
                            <span class="subject" x-text="row.subject || t('(no subject)')"></span>
                            <span class="preview" x-show="row.preview" x-text="row.preview"></span>
                        </button>
                        <button type="button" class="star" :class="{ on: row.flags.flagged }" @click.stop="toggleFlag(row)" :aria-label="row.flags.flagged ? t('Unflag') : t('Flag')" :aria-pressed="row.flags.flagged">
                            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m10 2.8 2.2 4.6 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5L2.8 8.1l5-.7L10 2.8Z"/></svg>
                        </button>
                    </li>
                </template>
            </ul>

            <div class="list-footer">
                <p class="empty" x-show="!listLoading && !messages.length && !listError" x-text="query || filterUnread || filterFlagged ? t('No messages match.') : t('This folder is empty.')"></p>
                <p class="empty error" x-show="listError" x-text="listError"></p>
                <span class="spinner" x-show="listLoading && !messages.length" :aria-label="t('Loading')"></span>
                <div class="pager" x-show="pages > 1">
                    <button type="button" class="button small" @click="goToPage(page - 1)" :disabled="page <= 1 || listLoading" x-text="t('Newer')"></button>
                    <span class="small muted" x-text="t('Page :page of :pages', { page, pages })"></span>
                    <button type="button" class="button small" @click="goToPage(page + 1)" :disabled="page >= pages || listLoading" x-text="t('Older')"></button>
                </div>
            </div>
        </section>

        <section class="reader-pane" aria-live="polite">
            <template x-if="!message && !messageLoading">
                <div class="reader-empty">
                    <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M6 12h36v24H6zM6 12l18 14 18-14"/></svg>
                    <p class="muted" x-text="messages.length ? t('Select a message to read it.') : ''"></p>
                </div>
            </template>
            <template x-if="messageLoading && !message">
                <div class="reader-empty"><span class="spinner"></span></div>
            </template>
            <template x-if="message">
                <article class="reader">
                    <div class="reader-toolbar">
                        <button type="button" class="icon-button back" @click="closeMessage()" :aria-label="t('Back')">
                            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12 4 6 10l6 6"/></svg>
                        </button>
                        <template x-if="isDraftFolder()">
                            <button type="button" class="button small primary" @click="compose('draft', message)" x-text="t('Edit draft')"></button>
                        </template>
                        <template x-if="!isDraftFolder()">
                            <div class="toolbar-group">
                                <button type="button" class="button small" @click="compose('reply', message)" :title="t('Reply') + ' (r)'" x-text="t('Reply')"></button>
                                <button type="button" class="button small" @click="compose('replyAll', message)" :title="t('Reply all') + ' (a)'" x-text="t('Reply all')"></button>
                                <button type="button" class="button small" @click="compose('forward', message)" :title="t('Forward') + ' (f)'" x-text="t('Forward')"></button>
                            </div>
                        </template>
                        <span class="spacer"></span>
                        <button type="button" class="icon-button" @click="toggleFlag(message)" :class="{ on: message.flags.flagged }" :title="message.flags.flagged ? t('Unflag') : t('Flag')" :aria-pressed="message.flags.flagged">
                            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m10 2.8 2.2 4.6 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5L2.8 8.1l5-.7L10 2.8Z"/></svg>
                        </button>
                        <button type="button" class="icon-button" x-show="currentFolder()?.role !== 'junk'" @click="moveMessage('junk')" :title="t('Move to junk')" :aria-label="t('Move to junk')">
                            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3 3 16h14L10 3ZM10 8v4M10 14v.5"/></svg>
                        </button>
                        <button type="button" class="icon-button" @click="deleteMessage()" :title="t('Delete') + ' (Del)'" :aria-label="t('Delete')">
                            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10"/></svg>
                        </button>
                        <div class="menu" x-data="{ open: false }" @click.outside="open = false">
                            <button type="button" class="icon-button" @click="open = !open" :aria-label="t('More')" :aria-expanded="open">
                                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 10h.01M10 10h.01M15 10h.01"/></svg>
                            </button>
                            <div class="menu-items right" x-show="open" x-transition.opacity @click="open = false">
                                <button type="button" @click="markUnread()" x-text="t('Mark unread')"></button>
                                <template x-for="target in moveTargets()" :key="target.id">
                                    <button type="button" class="indent" @click="moveMessage(null, target.id)" x-text="t('Move to :folder', { folder: folderName(target) })"></button>
                                </template>
                                <a :href="attachmentUrl('raw')" x-text="t('Download message (.eml)')"></a>
                                <button type="button" @click="togglePlain()" x-text="plainView ? t('Show formatted') : t('Show plain text')"></button>
                            </div>
                        </div>
                    </div>

                    <header class="reader-head">
                        <h1 x-text="message.subject || t('(no subject)')"></h1>
                        <div class="sender">
                            <span class="avatar large" x-text="initials(message.from[0]?.name || message.from[0]?.email || '?')"></span>
                            <div class="sender-lines">
                                <div class="sender-top">
                                    <strong x-text="message.from[0]?.name || message.from[0]?.email || t('Unknown sender')"></strong>
                                    <button type="button" class="address link" x-show="message.from[0]?.name" @click="composeTo(message.from[0])" x-text="'<' + (message.from[0]?.email || '') + '>'"></button>
                                    <span class="spacer"></span>
                                    <time class="muted small" :datetime="message.date" x-text="longDate(message.date)"></time>
                                </div>
                                <div class="recipients small muted">
                                    <template x-for="row in recipientRows()" :key="row.label">
                                        <div><span x-text="row.label"></span> <span x-text="row.value"></span></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <p class="unsubscribe small" x-show="message.unsubscribe">
                            <a :href="message.unsubscribe" target="_blank" rel="noopener noreferrer" x-text="t('Unsubscribe')"></a>
                        </p>
                    </header>

                    <div class="notice" x-show="blocked > 0 && !remoteAllowed">
                        <span x-text="t('Images from the web were blocked to protect your privacy.')"></span>
                        <button type="button" class="link" @click="allowRemote()" x-text="t('Show images')"></button>
                        <button type="button" class="link" x-show="message.from[0]?.email" @click="trustSender()" x-text="t('Always show from this sender')"></button>
                    </div>

                    <div class="attachments" x-show="message.attachments.length">
                        <template x-for="file in message.attachments" :key="file.part">
                            <a class="attachment" :href="attachmentUrl(file.part)" :title="file.name">
                                <template x-if="file.image">
                                    <img :src="attachmentUrl(file.part, true)" alt="" loading="lazy">
                                </template>
                                <template x-if="!file.image">
                                    <span class="file-icon" x-text="extension(file.name)"></span>
                                </template>
                                <span class="file-name" x-text="file.name"></span>
                                <span class="file-size muted" x-text="bytes(file.size)"></span>
                            </a>
                        </template>
                    </div>

                    <div class="body-frame">
                        <iframe x-ref="frame" :src="frameUrl()" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" :title="t('Message')" @load="frameLoaded($event.target)"></iframe>
                    </div>
                </article>
            </template>
        </section>
    </div>

    <!-- Compose -->
    <div class="composer-backdrop" x-show="composer" x-transition.opacity></div>
    <template x-if="composer">
        <section class="composer" role="dialog" aria-modal="true" :aria-label="t('New message')"
                 @dragover.prevent="composer.dragging = true" @dragleave.self="composer.dragging = false" @drop.prevent="dropFiles($event)"
                 :class="{ dragging: composer.dragging }" @keydown.escape.stop="closeComposer()">
            <header class="composer-head">
                <h2 x-text="composer.subject || t('New message')"></h2>
                <span class="small muted" x-text="composer.status"></span>
                <span class="spacer"></span>
                <button type="button" class="icon-button" @click="closeComposer()" :aria-label="t('Close')">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg>
                </button>
            </header>

            <div class="composer-fields">
                <div class="field from">
                    <span class="field-label" x-text="t('From')"></span>
                    <span class="from-value" x-text="(settings.name ? settings.name + ' <' + address + '>' : address)"></span>
                </div>
                <template x-for="kind in ['to', 'cc', 'bcc']" :key="kind">
                    <div class="field recipients-field" x-show="kind === 'to' || composer.show[kind]">
                        <label class="field-label" :for="'field-' + kind" x-text="t(kind === 'to' ? 'To' : (kind === 'cc' ? 'Cc' : 'Bcc'))"></label>
                        <div class="chips" @click="document.getElementById('field-' + kind)?.focus()">
                            <template x-for="(entry, index) in composer[kind]" :key="kind + index + entry.email">
                                <span class="chip-address" :class="{ invalid: !validEmail(entry.email) }" :title="entry.email">
                                    <span x-text="entry.name || entry.email"></span>
                                    <button type="button" @click.stop="removeRecipient(kind, index)" :aria-label="t('Remove')">×</button>
                                </span>
                            </template>
                            <input type="text" :id="'field-' + kind" x-model="composer.typing[kind]"
                                   @input="suggest(kind)" @keydown="recipientKey($event, kind)" @blur="setTimeout(() => commitRecipient(kind), 150)"
                                   @paste="setTimeout(() => commitRecipient(kind), 0)"
                                   autocomplete="off" spellcheck="false" :aria-label="t(kind === 'to' ? 'To' : (kind === 'cc' ? 'Cc' : 'Bcc'))">
                        </div>
                        <template x-if="kind === 'to'">
                            <div class="field-extra">
                                <button type="button" class="link small" x-show="!composer.show.cc" @click="composer.show.cc = true" x-text="t('Cc')"></button>
                                <button type="button" class="link small" x-show="!composer.show.bcc" @click="composer.show.bcc = true" x-text="t('Bcc')"></button>
                            </div>
                        </template>
                        <ul class="suggestions" x-show="composer.suggestFor === kind && composer.suggestions.length">
                            <template x-for="(item, index) in composer.suggestions" :key="item.email">
                                <li :class="{ active: index === composer.suggestIndex }">
                                    <button type="button" @mousedown.prevent="pickSuggestion(kind, item)">
                                        <span x-text="item.name || item.email"></span>
                                        <span class="muted small" x-show="item.name" x-text="item.email"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
                <div class="field">
                    <label class="field-label" for="field-subject" x-text="t('Subject')"></label>
                    <input type="text" id="field-subject" class="subject-input" x-model="composer.subject" @input="touch()" maxlength="500">
                </div>
            </div>

            <div class="editor-toolbar" x-show="composer.html">
                <button type="button" @mousedown.prevent @click="format('bold')" :title="t('Bold')"><b>B</b></button>
                <button type="button" @mousedown.prevent @click="format('italic')" :title="t('Italic')"><i>I</i></button>
                <button type="button" @mousedown.prevent @click="format('underline')" :title="t('Underline')"><u>U</u></button>
                <button type="button" @mousedown.prevent @click="format('strikeThrough')" :title="t('Strikethrough')"><s>S</s></button>
                <span class="sep"></span>
                <button type="button" @mousedown.prevent @click="format('insertUnorderedList')" :title="t('Bulleted list')">•</button>
                <button type="button" @mousedown.prevent @click="format('insertOrderedList')" :title="t('Numbered list')">1.</button>
                <button type="button" @mousedown.prevent @click="format('formatBlock', 'blockquote')" :title="t('Quote')">❝</button>
                <button type="button" @mousedown.prevent @click="insertLink()" :title="t('Link')">🔗</button>
                <button type="button" @mousedown.prevent @click="format('removeFormat')" :title="t('Clear formatting')">⌫</button>
                <span class="spacer"></span>
                <button type="button" class="link small" @click="togglePlainCompose()" x-text="t('Plain text')"></button>
            </div>
            <div class="editor-toolbar" x-show="!composer.html">
                <span class="spacer"></span>
                <button type="button" class="link small" @click="togglePlainCompose()" x-text="t('Rich text')"></button>
            </div>

            <div class="editor-wrap">
                <div class="editor" x-ref="editor" x-show="composer.html" contenteditable="true" @input="touch()" @paste="pasteInto($event)" :aria-label="t('Message')" role="textbox" aria-multiline="true"></div>
                <textarea class="editor plain" x-ref="plainEditor" x-show="!composer.html" x-model="composer.text" @input="touch()" :aria-label="t('Message')"></textarea>
            </div>

            <div class="composer-attachments" x-show="composer.attachments.length">
                <template x-for="(file, index) in composer.attachments" :key="file.key">
                    <span class="attachment-chip" :class="{ failed: file.error }">
                        <span class="file-name" x-text="file.name"></span>
                        <span class="muted small" x-text="file.error || (file.progress < 100 ? file.progress + '%' : bytes(file.size))"></span>
                        <button type="button" @click="removeAttachment(index)" :aria-label="t('Remove')">×</button>
                    </span>
                </template>
            </div>

            <footer class="composer-foot">
                <button type="button" class="button primary" @click="send()" :disabled="composer.sending || uploading()" x-text="composer.sending ? t('Sending…') : t('Send')"></button>
                <label class="button ghost attach">
                    <input type="file" multiple @change="addFiles($event.target.files); $event.target.value = ''">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M14 9.5 9 14.5a3 3 0 0 1-4.3-4.3l5.8-5.7a2 2 0 0 1 2.8 2.8L7.6 13"/></svg>
                    <span x-text="t('Attach')"></span>
                </label>
                <span class="spacer"></span>
                <button type="button" class="button ghost" @click="saveDraft(false)" :disabled="composer.saving || composer.sending" x-text="t('Save draft')"></button>
                <button type="button" class="button ghost danger" @click="discardComposer()" :disabled="composer.sending" x-text="t('Discard')"></button>
            </footer>
            <div class="drop-hint" x-show="composer.dragging"><span x-text="t('Drop files to attach them')"></span></div>
        </section>
    </template>

    <!-- Settings -->
    <template x-if="settingsForm">
        <div class="modal-backdrop" @click.self="settingsForm = null" @keydown.escape.window="settingsForm = null">
            <form class="modal" @submit.prevent="saveSettings()" role="dialog" aria-modal="true" :aria-label="t('Settings')">
                <header><h2 x-text="t('Settings')"></h2></header>
                <label class="form-row">
                    <span x-text="t('Your name')"></span>
                    <input type="text" x-model="settingsForm.name" maxlength="120" :placeholder="address">
                    <small class="muted" x-text="t('Shown to the people you write to.')"></small>
                </label>
                <div class="form-row">
                    <span x-text="t('Signature')"></span>
                    <div class="editor small-editor" x-ref="signatureEditor" contenteditable="true" x-init="$el.innerHTML = settingsForm.signature"></div>
                    <small class="muted" x-text="t('Added to the messages you write.')"></small>
                </div>
                <label class="form-row">
                    <span x-text="t('Write messages as')"></span>
                    <select x-model="settingsForm.format">
                        <option value="html" x-text="t('Rich text')"></option>
                        <option value="plain" x-text="t('Plain text')"></option>
                    </select>
                </label>
                <label class="form-row">
                    <span x-text="t('Language')"></span>
                    <select x-model="settingsForm.language">
                        <option value="" x-text="t('Browser default')"></option>
                        <template x-for="(label, code) in languages" :key="code">
                            <option :value="code" x-text="label" :selected="settingsForm.language === code"></option>
                        </template>
                    </select>
                </label>
                <div class="form-row" x-show="settingsForm.trusted.length">
                    <span x-text="t('Images always shown from')"></span>
                    <ul class="trusted">
                        <template x-for="(entry, index) in settingsForm.trusted" :key="entry">
                            <li><span x-text="entry"></span> <button type="button" class="link small danger" @click="settingsForm.trusted.splice(index, 1)" x-text="t('Remove')"></button></li>
                        </template>
                    </ul>
                </div>
                <footer>
                    <button type="button" class="button ghost" @click="settingsForm = null" x-text="t('Cancel')"></button>
                    <button type="submit" class="button primary" :disabled="settingsForm.saving" x-text="t('Save')"></button>
                </footer>
            </form>
        </div>
    </template>

    <!-- Folder dialog -->
    <template x-if="folderDialog">
        <div class="modal-backdrop" @click.self="folderDialog = null" @keydown.escape.window="folderDialog = null">
            <form class="modal narrow" @submit.prevent="submitFolderDialog()" role="dialog" aria-modal="true">
                <header><h2 x-text="folderDialog.mode === 'create' ? t('New folder') : t('Rename folder')"></h2></header>
                <label class="form-row">
                    <span x-text="t('Name')"></span>
                    <input type="text" x-model="folderDialog.name" maxlength="100" required x-init="$nextTick(() => $el.focus())">
                </label>
                <label class="form-row" x-show="folderDialog.mode === 'create'">
                    <span x-text="t('Inside')"></span>
                    <select x-model="folderDialog.parent">
                        <option value="" x-text="t('(top level)')"></option>
                        <template x-for="item in folders.filter(f => f.selectable)" :key="item.id">
                            <option :value="item.id" x-text="'  '.repeat(item.depth) + folderName(item)"></option>
                        </template>
                    </select>
                </label>
                <p class="alert" x-show="folderDialog.error" x-text="folderDialog.error"></p>
                <footer>
                    <button type="button" class="button ghost" @click="folderDialog = null" x-text="t('Cancel')"></button>
                    <button type="submit" class="button primary" :disabled="folderDialog.saving" x-text="folderDialog.mode === 'create' ? t('Create') : t('Rename')"></button>
                </footer>
            </form>
        </div>
    </template>

    <div class="toasts" aria-live="assertive">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="toast" :class="toast.kind">
                <span x-text="toast.text"></span>
                <button type="button" class="link" x-show="toast.action" @click="toast.action.run(); dismiss(toast.id)" x-text="toast.action?.label"></button>
                <button type="button" class="toast-close" @click="dismiss(toast.id)" :aria-label="t('Close')">×</button>
            </div>
        </template>
    </div>
</div>
<?php } ?>
</body>
</html>
