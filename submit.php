<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '发布留言 - 社区便民留言板';
$currentPage = 'submit';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

include __DIR__ . '/includes/header.php';
?>

<section class="submit-section">
    <div class="container">
        <div class="submit-card">
            <h2 class="submit-title">📝 发布留言</h2>

            <!-- 草稿状态条 -->
            <div class="draft-bar" id="draftBar" style="display:none;">
                <span class="draft-status" id="draftStatus">📌 草稿已自动保存</span>
                <span class="draft-actions">
                    <button type="button" class="btn btn-xs btn-secondary" id="exportDraftBtn">📦 导出草稿</button>
                    <button type="button" class="btn btn-xs btn-secondary" id="importDraftBtn">📂 导入草稿</button>
                    <button type="button" class="btn btn-xs btn-danger" id="clearDraftBtn">🗑 清空草稿</button>
                </span>
                <input type="file" id="draftImport" accept=".json,application/json" hidden>
            </div>

            <form id="submitForm" class="submit-form" enctype="multipart/form-data">
                <!-- 草稿提交令牌：同一草稿重复提交只生成一条待审留言 -->
                <input type="hidden" id="submitToken" name="submit_token">

                <div class="form-group">
                    <label for="nickname">昵称 <span class="required">*</span></label>
                    <input type="text" id="nickname" name="nickname" placeholder="请输入您的昵称" maxlength="50" required>
                </div>

                <div class="form-group">
                    <label for="phone">联系电话</label>
                    <input type="tel" id="phone" name="phone" placeholder="选填，方便联系" maxlength="20">
                </div>

                <div class="form-group">
                    <label>留言类型 <span class="required">*</span></label>
                    <div class="type-selector">
                        <label class="type-option">
                            <input type="radio" name="type" value="help" checked>
                            <span class="type-btn type-help">🆘 居民求助</span>
                        </label>
                        <label class="type-option">
                            <input type="radio" name="type" value="suggest">
                            <span class="type-btn type-suggest">💡 意见建议</span>
                        </label>
                        <label class="type-option">
                            <input type="radio" name="type" value="lost">
                            <span class="type-btn type-lost">🔍 失物招领</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="title">标题 <span class="required">*</span></label>
                    <input type="text" id="title" name="title" placeholder="请简要描述您的留言主题" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label for="content">详细内容 <span class="required">*</span></label>
                    <textarea id="content" name="content" rows="6" placeholder="请详细描述您的留言内容..." maxlength="2000" required></textarea>
                    <span class="char-count"><span id="charCount">0</span>/2000</span>
                </div>

                <div class="form-group">
                    <label>上传图片 <span class="text-muted">（最多 6 张，可拖动或 ◀▶ 调整顺序）</span></label>
                    <div class="upload-area">
                        <input type="file" id="imagePicker" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
                        <div class="image-grid" id="imageGrid"></div>
                    </div>
                    <span class="upload-hint">支持 JPG、PNG、GIF、WebP，单张最大 5MB；内容会自动存为草稿，离开页面再回来可恢复</span>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">提交留言</button>
                    <a href="index.php" class="btn btn-secondary btn-lg">返回首页</a>
                </div>
            </form>
        </div>
    </div>
</section>

<script>
(function() {
    'use strict';

    var MAX_IMAGES = 6;
    var MAX_SIZE = 5 * 1024 * 1024;
    var ALLOWED_MIME = { 'image/jpeg': 1, 'image/png': 1, 'image/gif': 1, 'image/webp': 1 };
    var ALLOWED_EXT = { jpg: 1, jpeg: 1, png: 1, gif: 1, webp: 1 };
    var DRAFT_KEY = 'community_board_draft_v1';
    var DB_NAME = 'community_board_draft';
    var DB_STORE = 'blobs';
    var BLOB_PREFIX = 'img:';

    var form = document.getElementById('submitForm');
    var grid = document.getElementById('imageGrid');
    var picker = document.getElementById('imagePicker');
    var btnSubmit = document.getElementById('submitBtn');
    var draftBar = document.getElementById('draftBar');
    var draftStatus = document.getElementById('draftStatus');
    var draftImport = document.getElementById('draftImport');

    // 选择图片后的处理模式：追加 或 替换(恢复)指定位置
    var pickerMode = { mode: 'add', index: -1 };

    var state = {
        token: '',
        fields: { nickname: '', phone: '', type: 'help', title: '', content: '' },
        // { id, name, file, url, valid, error }
        images: []
    };

    /* ---------------- 工具 ---------------- */

    function genToken() {
        if (window.crypto && crypto.getRandomValues) {
            var bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            var hex = '';
            for (var i = 0; i < bytes.length; i++) hex += ('0' + bytes[i].toString(16)).slice(-2);
            return hex;
        }
        return (Date.now().toString(16) + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2)).slice(0, 32);
    }

    function genId() {
        return Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function nowTime() {
        var d = new Date();
        function p(n) { return ('0' + n).slice(-2); }
        return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    function validateImage(file) {
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (!ALLOWED_MIME[file.type] && !ALLOWED_EXT[ext]) {
            return '格式不支持（仅 JPG/PNG/GIF/WebP）';
        }
        if (file.size > MAX_SIZE) {
            return '大小超过 5MB';
        }
        return null;
    }

    /* ---------------- IndexedDB（图片二进制） ---------------- */

    function openDB() {
        return new Promise(function(resolve, reject) {
            var req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = function(e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains(DB_STORE)) {
                    db.createObjectStore(DB_STORE, { keyPath: 'key' });
                }
            };
            req.onsuccess = function() { resolve(req.result); };
            req.onerror = function() { reject(req.error); };
        });
    }

    function idbAll() {
        return openDB().then(function(db) {
            return new Promise(function(resolve, reject) {
                var tx = db.transaction(DB_STORE, 'readonly');
                var req = tx.objectStore(DB_STORE).getAll();
                req.onsuccess = function() {
                    var out = {};
                    (req.result || []).forEach(function(r) { out[r.key] = r.blob; });
                    resolve(out);
                };
                req.onerror = function() { reject(req.error); };
            });
        });
    }

    function idbSet(key, blob) {
        return openDB().then(function(db) {
            return new Promise(function(resolve, reject) {
                var tx = db.transaction(DB_STORE, 'readwrite');
                tx.objectStore(DB_STORE).put({ key: key, blob: blob });
                tx.oncomplete = function() { resolve(); };
                tx.onerror = function() { reject(tx.error); };
            });
        });
    }

    function idbDelete(keys) {
        if (!keys.length) return Promise.resolve();
        return openDB().then(function(db) {
            return new Promise(function(resolve, reject) {
                var tx = db.transaction(DB_STORE, 'readwrite');
                var store = tx.objectStore(DB_STORE);
                keys.forEach(function(k) { store.delete(k); });
                tx.oncomplete = function() { resolve(); };
                tx.onerror = function() { reject(tx.error); };
            });
        });
    }

    function idbClearImages() {
        return idbAll().then(function(all) {
            return idbDelete(Object.keys(all).filter(function(k) { return k.indexOf(BLOB_PREFIX) === 0; }));
        });
    }

    /* ---------------- 草稿保存 / 恢复 / 清除 ---------------- */

    function collectFields() {
        var checked = form.querySelector('input[name="type"]:checked');
        state.fields = {
            nickname: document.getElementById('nickname').value,
            phone: document.getElementById('phone').value,
            type: checked ? checked.value : 'help',
            title: document.getElementById('title').value,
            content: document.getElementById('content').value
        };
    }

    function hasAnyContent() {
        collectFields();
        var f = state.fields;
        return !!(f.nickname.trim() || f.phone.trim() || f.title.trim() || f.content.trim() || state.images.length);
    }

    function buildMeta() {
        collectFields();
        return {
            app: 'community_board',
            version: 1,
            token: state.token,
            savedAt: new Date().toISOString(),
            fields: state.fields,
            images: state.images.filter(function(im) { return im.valid; }).map(function(im) {
                return { id: im.id, name: im.name, type: im.file.type, size: im.file.size };
            })
        };
    }

    // 同步写 localStorage，异步写 IndexedDB（含页面关闭前调用）
    function saveDraft(showTip) {
        if (!state.token) state.token = genToken();
        document.getElementById('submitToken').value = state.token;

        var meta = buildMeta();
        var valid = state.images.filter(function(im) { return im.valid; });

        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify(meta));
        } catch (e) { /* 容量不足等情况忽略，不影响填写 */ }

        var validIds = {};
        var tasks = valid.map(function(im) {
            var key = BLOB_PREFIX + im.id;
            validIds[key] = true;
            return idbSet(key, im.file).catch(function() {});
        });

        Promise.all(tasks).then(function() {
            return idbAll().then(function(all) {
                var stale = Object.keys(all).filter(function(k) {
                    return k.indexOf(BLOB_PREFIX) === 0 && !validIds[k];
                });
                return idbDelete(stale);
            });
        }).catch(function() {}).then(function() {
            updateDraftBar(meta);
            if (showTip) draftStatus.textContent = '📌 草稿已保存 ' + nowTime();
        });
    }

    var saveTimer = null;
    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function() { saveDraft(true); }, 400);
    }

    function updateDraftBar(meta) {
        var f = meta.fields;
        var count = meta.images.length;
        var meaningful = f.nickname.trim() || f.phone.trim() || f.title.trim() || f.content.trim() || count;
        draftBar.style.display = meaningful ? 'flex' : 'none';
        if (meaningful) {
            draftStatus.textContent = '📌 草稿已自动保存 ' + nowTime() + ' · ' + count + ' 张图片';
        }
    }

    function applyFieldsToForm() {
        document.getElementById('nickname').value = state.fields.nickname || '';
        document.getElementById('phone').value = state.fields.phone || '';
        document.getElementById('title').value = state.fields.title || '';
        document.getElementById('content').value = state.fields.content || '';
        var radio = form.querySelector('input[name="type"][value="' + (state.fields.type || 'help') + '"]');
        if (radio) radio.checked = true;
        document.getElementById('charCount').textContent = (state.fields.content || '').length;
    }

    function restoreFromMeta(meta) {
        state.token = meta.token || genToken();
        state.fields = meta.fields || state.fields;
        applyFieldsToForm();
        document.getElementById('submitToken').value = state.token;
        state.images = [];

        var refs = meta.images || [];
        if (!refs.length) {
            renderImages();
            updateDraftBar(meta);
            return;
        }

        idbAll().then(function(all) {
            refs.forEach(function(ref) {
                var blob = all[BLOB_PREFIX + ref.id];
                if (!blob) return;
                var file = new File([blob], ref.name || ('image_' + ref.id), { type: ref.type || blob.type || 'image/jpeg' });
                var err = validateImage(file);
                state.images.push({
                    id: ref.id,
                    name: file.name,
                    file: file,
                    url: URL.createObjectURL(file),
                    valid: !err,
                    error: err
                });
            });
            renderImages();
            updateDraftBar(meta);
        }).catch(function() {
            renderImages();
            updateDraftBar(meta);
        });
    }

    function clearDraft(resetToken) {
        localStorage.removeItem(DRAFT_KEY);
        state.images.forEach(function(im) { if (im.url) URL.revokeObjectURL(im.url); });
        state.images = [];
        state.fields = { nickname: '', phone: '', type: 'help', title: '', content: '' };
        if (resetToken) state.token = genToken();
        form.reset();
        var defaultRadio = form.querySelector('input[name="type"][value="help"]');
        if (defaultRadio) defaultRadio.checked = true;
        document.getElementById('charCount').textContent = '0';
        // reset 会清空隐藏域，需在 reset 之后重新写入新令牌
        document.getElementById('submitToken').value = state.token;
        idbClearImages().catch(function() {}).then(function() { renderImages(); });
        draftBar.style.display = 'none';
    }

    function initDraft() {
        var raw = null;
        try { raw = localStorage.getItem(DRAFT_KEY); } catch (e) {}

        if (raw) {
            try {
                restoreFromMeta(JSON.parse(raw));
                return;
            } catch (e) { /* 草稿损坏则重新开始 */ }
        }

        state.token = genToken();
        document.getElementById('submitToken').value = state.token;
        renderImages();
    }

    /* ---------------- 图片列表渲染与操作 ---------------- */

    function makeEntry(file) {
        var err = validateImage(file);
        return {
            id: genId(),
            name: file.name,
            file: file,
            url: URL.createObjectURL(file),
            valid: !err,
            error: err
        };
    }

    function renderImages() {
        grid.innerHTML = '';

        state.images.forEach(function(im, idx) {
            var tile = document.createElement('div');
            tile.className = 'image-tile' + (im.valid ? '' : ' invalid');
            tile.draggable = true;
            tile.dataset.index = idx;

            var html = '';
            if (im.valid) {
                html += '<img src="' + im.url + '" alt="第' + (idx + 1) + '张">';
                html += '<span class="image-order">' + (idx + 1) + '</span>';
                html += '<div class="image-controls">';
                html += '<button type="button" class="img-btn" data-act="left" title="前移">◀</button>';
                html += '<button type="button" class="img-btn" data-act="right" title="后移">▶</button>';
                html += '<button type="button" class="img-btn img-btn-danger" data-act="remove" title="移除">✕</button>';
                html += '</div>';
            } else {
                html += '<div class="image-error-box">';
                html += '<div class="image-error-icon">⚠️</div>';
                html += '<div class="image-error-name">' + escapeHtml(im.name) + '</div>';
                html += '<div class="image-error-msg">' + escapeHtml(im.error || '图片不符合要求') + '</div>';
                html += '<button type="button" class="btn btn-xs btn-warning" data-act="reselect">单独重选</button>';
                html += '<button type="button" class="btn btn-xs btn-danger" data-act="remove">移除</button>';
                html += '</div>';
            }
            tile.innerHTML = html;
            grid.appendChild(tile);
        });

        if (state.images.length < MAX_IMAGES) {
            var add = document.createElement('button');
            add.type = 'button';
            add.className = 'image-add';
            add.innerHTML = '<span class="image-add-icon">📷</span><span>添加图片</span><span class="image-add-hint">还可加 ' + (MAX_IMAGES - state.images.length) + ' 张</span>';
            add.addEventListener('click', function() {
                pickerMode = { mode: 'add', index: -1 };
                picker.value = '';
                picker.click();
            });
            grid.appendChild(add);
        }
    }

    function moveImage(from, to) {
        if (from < 0 || from >= state.images.length || to < 0 || to >= state.images.length) return;
        var item = state.images.splice(from, 1)[0];
        state.images.splice(to, 0, item);
        renderImages();
        scheduleSave();
    }

    function removeImage(idx) {
        var im = state.images[idx];
        if (im && im.url) URL.revokeObjectURL(im.url);
        state.images.splice(idx, 1);
        renderImages();
        scheduleSave();
    }

    grid.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        var tile = e.target.closest('.image-tile');
        if (!tile) return;
        var idx = parseInt(tile.dataset.index, 10);
        var act = btn.dataset.act;

        if (act === 'left') moveImage(idx, idx - 1);
        else if (act === 'right') moveImage(idx, idx + 1);
        else if (act === 'remove') removeImage(idx);
        else if (act === 'reselect') {
            pickerMode = { mode: 'replace', index: idx };
            picker.value = '';
            picker.click();
        }
    });

    // 拖拽排序
    var dragIndex = null;
    grid.addEventListener('dragstart', function(e) {
        var tile = e.target.closest('.image-tile');
        if (!tile) return;
        dragIndex = parseInt(tile.dataset.index, 10);
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', String(dragIndex)); } catch (err) {}
    });
    grid.addEventListener('dragover', function(e) {
        var tile = e.target.closest('.image-tile');
        if (!tile) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        grid.querySelectorAll('.image-tile.dragover').forEach(function(t) { t.classList.remove('dragover'); });
        tile.classList.add('dragover');
    });
    grid.addEventListener('dragleave', function(e) {
        var tile = e.target.closest('.image-tile');
        if (tile) tile.classList.remove('dragover');
    });
    grid.addEventListener('drop', function(e) {
        var tile = e.target.closest('.image-tile');
        grid.querySelectorAll('.image-tile.dragover').forEach(function(t) { t.classList.remove('dragover'); });
        if (!tile || dragIndex === null) return;
        e.preventDefault();
        var target = parseInt(tile.dataset.index, 10);
        if (target !== dragIndex) moveImage(dragIndex, target);
        dragIndex = null;
    });
    grid.addEventListener('dragend', function() {
        grid.querySelectorAll('.image-tile.dragover').forEach(function(t) { t.classList.remove('dragover'); });
        dragIndex = null;
    });

    // 选择图片（追加 / 单张替换恢复）
    picker.addEventListener('change', function() {
        var files = Array.prototype.slice.call(picker.files || []);
        if (!files.length) return;

        if (pickerMode.mode === 'replace') {
            var idx = pickerMode.index;
            var old = state.images[idx];
            var entry = makeEntry(files[0]);
            if (old && old.url) URL.revokeObjectURL(old.url);
            if (old) {
                state.images[idx] = entry;
            } else {
                state.images.push(entry);
            }
        } else {
            for (var i = 0; i < files.length; i++) {
                if (state.images.length >= MAX_IMAGES) {
                    alert('最多只能上传 ' + MAX_IMAGES + ' 张图片，后面的图片未添加');
                    break;
                }
                state.images.push(makeEntry(files[i]));
            }
        }

        renderImages();
        scheduleSave();
        picker.value = '';
        pickerMode = { mode: 'add', index: -1 };
    });

    /* ---------------- 草稿导出 / 导入 ---------------- */

    function fileToDataUrl(file) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() { resolve(reader.result); };
            reader.onerror = function() { reject(reader.error); };
            reader.readAsDataURL(file);
        });
    }

    function dataUrlToFile(dataUrl, filename) {
        var arr = dataUrl.split(','), head = arr[0] || '', body = arr[1] || '';
        var mime = (head.match(/data:(.*?);base64/) || [])[1] || 'image/jpeg';
        var bin = atob(body);
        var u8 = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
        return new File([u8], filename, { type: mime });
    }

    function downloadFile(filename, content, mime) {
        var blob = new Blob([content], { type: mime || 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
    }

    function exportDraft() {
        if (!hasAnyContent()) {
            alert('草稿内容为空，无需导出');
            return;
        }
        saveDraft(false);

        var valid = state.images.filter(function(im) { return im.valid; });
        Promise.all(valid.map(function(im) {
            return fileToDataUrl(im.file).then(function(dataUrl) {
                return { name: im.name, type: im.file.type, size: im.file.size, dataUrl: dataUrl };
            });
        })).then(function(imageData) {
            collectFields();
            var pack = {
                app: 'community_board',
                kind: 'message_draft',
                version: 1,
                exportedAt: new Date().toISOString(),
                token: state.token,
                fields: state.fields,
                images: imageData // 严格按当前顺序
            };
            var d = new Date();
            function p(n) { return ('0' + n).slice(-2); }
            var stamp = '' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '_' + p(d.getHours()) + p(d.getMinutes());
            downloadFile('留言草稿_' + stamp + '.json', JSON.stringify(pack, null, 2));
            draftStatus.textContent = '📦 草稿已导出 ' + nowTime();
        }).catch(function() {
            alert('导出失败，请重试');
        });
    }

    function importDraft(file) {
        var reader = new FileReader();
        reader.onload = function() {
            var pack;
            try {
                pack = JSON.parse(reader.result);
            } catch (e) {
                alert('草稿文件解析失败，请选择正确的草稿文件');
                return;
            }
            if (!pack || pack.kind !== 'message_draft' || !pack.fields) {
                alert('文件格式不正确，不是有效的留言草稿');
                return;
            }
            if (hasAnyContent() && !confirm('导入将覆盖当前草稿，确定继续吗？')) return;

            // 释放旧资源
            state.images.forEach(function(im) { if (im.url) URL.revokeObjectURL(im.url); });
            state.images = [];
            state.token = pack.token || genToken();
            state.fields = {
                nickname: pack.fields.nickname || '',
                phone: pack.fields.phone || '',
                type: pack.fields.type || 'help',
                title: pack.fields.title || '',
                content: pack.fields.content || ''
            };
            applyFieldsToForm();
            document.getElementById('submitToken').value = state.token;

            var refs = Array.isArray(pack.images) ? pack.images.slice(0, MAX_IMAGES) : [];
            refs.forEach(function(ref, i) {
                try {
                    var f = dataUrlToFile(ref.dataUrl, ref.name || ('image_' + i + '.jpg'));
                    var err = validateImage(f);
                    state.images.push({
                        id: genId(),
                        name: f.name,
                        file: f,
                        url: URL.createObjectURL(f),
                        valid: !err,
                        error: err
                    });
                } catch (e) {
                    state.images.push({
                        id: genId(),
                        name: ref.name || ('图片' + (i + 1)),
                        file: null,
                        url: '',
                        valid: false,
                        error: '图片数据已损坏，请单独重选'
                    });
                }
            });

            renderImages();
            saveDraft(true);
            alert('草稿已导入，图片按原顺序恢复，共 ' + refs.length + ' 张');
        };
        reader.onerror = function() { alert('文件读取失败'); };
        reader.readAsText(file);
    }

    document.getElementById('exportDraftBtn').addEventListener('click', exportDraft);
    document.getElementById('importDraftBtn').addEventListener('click', function() { draftImport.click(); });
    document.getElementById('clearDraftBtn').addEventListener('click', function() {
        if (confirm('确定清空当前草稿吗？此操作不可恢复。')) {
            clearDraft(true);
        }
    });
    draftImport.addEventListener('change', function() {
        if (draftImport.files && draftImport.files[0]) importDraft(draftImport.files[0]);
        draftImport.value = '';
    });

    /* ---------------- 表单交互与提交 ---------------- */

    document.getElementById('content').addEventListener('input', function() {
        document.getElementById('charCount').textContent = this.value.length;
    });

    // 字段变化自动保存草稿
    ['nickname', 'phone', 'title', 'content'].forEach(function(id) {
        document.getElementById(id).addEventListener('input', scheduleSave);
    });
    Array.prototype.forEach.call(form.querySelectorAll('input[name="type"]'), function(radio) {
        radio.addEventListener('change', scheduleSave);
    });

    // 离开页面前尽量落盘
    function flushDraft() {
        if (hasAnyContent()) {
            try {
                var meta = buildMeta();
                localStorage.setItem(DRAFT_KEY, JSON.stringify(meta));
            } catch (e) {}
        }
    }
    window.addEventListener('beforeunload', flushDraft);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') flushDraft();
    });

    // 成功提交后通过 bfcache 返回时，丢弃被清空的旧草稿并重新加载，保证“返回后可继续编辑”
    window.addEventListener('pageshow', function(e) {
        btnSubmit.disabled = false;
        btnSubmit.textContent = '提交留言';
        if (e.persisted && sessionStorage.getItem('draft_submitted') === '1') {
            sessionStorage.removeItem('draft_submitted');
            location.reload();
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        var invalidIdx = state.images.findIndex(function(im) { return !im.valid; });
        if (invalidIdx !== -1) {
            alert('第 ' + (invalidIdx + 1) + ' 张图片不符合要求，请先“单独重选”或移除后再提交（已填写的文字不会丢失）');
            var tile = grid.children[invalidIdx];
            if (tile) tile.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        collectFields();
        saveDraft(false);

        btnSubmit.disabled = true;
        btnSubmit.textContent = '提交中...';

        var fd = new FormData();
        fd.append('nickname', state.fields.nickname);
        fd.append('phone', state.fields.phone);
        fd.append('type', state.fields.type);
        fd.append('title', state.fields.title);
        fd.append('content', state.fields.content);
        fd.append('submit_token', state.token);
        // 严格按当前顺序加入，后端按此顺序保存
        state.images.forEach(function(im) {
            if (im.file) fd.append('images[]', im.file, im.name);
        });

        fetch('api/submit.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.code === 0) {
                    // 提交成功（含重复提交的幂等返回）：本地草稿完成使命，予以清除
                    sessionStorage.setItem('draft_submitted', '1');
                    clearDraft(true);
                    alert(data.msg || '留言提交成功，等待审核！');
                    window.location.href = 'index.php';
                } else {
                    alert(data.msg || '提交失败');
                    // 服务端判定某张图片不合规：原位标记，支持单独重选，文字保留
                    if (data.data && typeof data.data.image_index === 'number') {
                        var i = data.data.image_index;
                        if (state.images[i]) {
                            state.images[i].valid = false;
                            state.images[i].error = '图片不符合要求，请单独重选';
                            renderImages();
                            scheduleSave();
                        }
                    }
                    btnSubmit.disabled = false;
                    btnSubmit.textContent = '提交留言';
                }
            })
            .catch(function() {
                alert('网络错误，请重试（草稿已保存，不会丢失）');
                btnSubmit.disabled = false;
                btnSubmit.textContent = '提交留言';
            });
    });

    initDraft();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
