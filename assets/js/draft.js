/**
 * 发布留言：多图排序 + 草稿暂存 + 草稿导入导出
 *
 * 存储设计：
 * - localStorage 保存文字内容、草稿键、图片槽位顺序（不含二进制，避免容量问题）
 * - IndexedDB 按槽位ID保存图片 File 二进制
 * - 导出草稿为自包含 JSON 文件（图片以 base64 内嵌，按当前顺序排列），
 *   导入后图片顺序与导出时一致
 */
(function () {
    'use strict';

    var MAX_IMAGES = 9;
    var MAX_SIZE = 5 * 1024 * 1024;
    var ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    var DRAFT_KEY = 'community_board_draft_v1';
    var IDB_NAME = 'community_board';
    var IDB_STORE = 'draft_images';

    var form = document.getElementById('submitForm');
    if (!form) return;

    var els = {
        nickname: document.getElementById('nickname'),
        phone: document.getElementById('phone'),
        title: document.getElementById('title'),
        content: document.getElementById('content'),
        charCount: document.getElementById('charCount'),
        typeInputs: form.querySelectorAll('input[name="type"]'),
        draftKey: document.getElementById('draftKey'),
        visitorId: document.getElementById('visitorId'),
        multiUpload: document.getElementById('multiUpload'),
        uploadAdd: document.getElementById('uploadAdd'),
        imageInput: document.getElementById('imageInput'),
        submitBtn: document.getElementById('submitBtn'),
        toolbar: document.getElementById('draftToolbar'),
        draftStatus: document.getElementById('draftStatus'),
        exportBtn: document.getElementById('exportDraftBtn'),
        importBtn: document.getElementById('importDraftBtn'),
        importFile: document.getElementById('importDraftFile'),
        clearBtn: document.getElementById('clearDraftBtn'),
        imageTip: document.getElementById('imageFormTip')
    };

    /** 槽位：{ id, name, size, type, status: 'ok'|'invalid', error, file(File|null) } */
    var slots = [];
    var draftMeta = null;     // localStorage 中的草稿元数据
    var restoreDone = false;  // 首次恢复完成前不触发自动保存
    var saveTimer = null;

    /* ============ 工具 ============ */

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function debounce(fn, wait) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + 'B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + 'KB';
        return (bytes / 1024 / 1024).toFixed(1) + 'MB';
    }

    function getType() {
        var checked = form.querySelector('input[name="type"]:checked');
        return checked ? checked.value : 'help';
    }

    function setType(v) {
        els.typeInputs.forEach(function (input) {
            input.checked = input.value === v;
        });
    }

    function dataURLtoFile(dataUrl, filename, type) {
        var arr = dataUrl.split(',');
        var mime = arr[0].match(/:(.*?);/)[1] || type || 'image/jpeg';
        var bstr = atob(arr[1]);
        var u8 = new Uint8Array(bstr.length);
        for (var i = 0; i < bstr.length; i++) u8[i] = bstr.charCodeAt(i);
        return new File([u8], filename || ('image_' + Date.now() + '.jpg'), { type: mime });
    }

    function fileToDataURL(file) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function (e) { resolve(e.target.result); };
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    /* ============ IndexedDB 图片存取 ============ */

    function openIDB() {
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open(IDB_NAME, 1);
            req.onupgradeneeded = function (e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains(IDB_STORE)) {
                    db.createObjectStore(IDB_STORE, { keyPath: 'id' });
                }
            };
            req.onsuccess = function (e) { resolve(e.target.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function idbPut(record) {
        return openIDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(IDB_STORE, 'readwrite');
                tx.objectStore(IDB_STORE).put(record);
                tx.oncomplete = function () { db.close(); resolve(); };
                tx.onerror = function () { db.close(); reject(tx.error); };
            });
        });
    }

    function idbGet(id) {
        return openIDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(IDB_STORE, 'readonly');
                var req = tx.objectStore(IDB_STORE).get(id);
                req.onsuccess = function () { db.close(); resolve(req.result || null); };
                req.onerror = function () { db.close(); reject(req.error); };
            });
        });
    }

    function idbDelete(id) {
        return openIDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(IDB_STORE, 'readwrite');
                tx.objectStore(IDB_STORE).delete(id);
                tx.oncomplete = function () { db.close(); resolve(); };
                tx.onerror = function () { db.close(); reject(tx.error); };
            });
        });
    }

    function idbClear() {
        return openIDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(IDB_STORE, 'readwrite');
                tx.objectStore(IDB_STORE).clear();
                tx.oncomplete = function () { db.close(); resolve(); };
                tx.onerror = function () { db.close(); reject(tx.error); };
            });
        });
    }

    /* ============ 图片校验 ============ */

    function validateImage(file) {
        if (ALLOWED_TYPES.indexOf(file.type) === -1) {
            return '仅支持 JPG、PNG、GIF、WebP 格式';
        }
        if (file.size > MAX_SIZE) {
            return '图片大小不能超过 5MB（当前 ' + formatSize(file.size) + '）';
        }
        return null;
    }

    /* ============ 槽位渲染与排序 ============ */

    var dragSrcId = null;

    function renderSlots() {
        // 移除旧槽位，保留添加入口
        var old = els.multiUpload.querySelectorAll('.image-slot');
        old.forEach(function (n) { n.remove(); });

        slots.forEach(function (slot, index) {
            var item = document.createElement('div');
            item.className = 'image-slot' + (slot.status === 'invalid' ? ' is-invalid' : '');
            item.draggable = true;
            item.dataset.id = slot.id;

            var badge = document.createElement('span');
            badge.className = 'slot-index';
            badge.textContent = index + 1;
            item.appendChild(badge);

            if (slot.status === 'invalid') {
                var broken = document.createElement('div');
                broken.className = 'slot-broken';
                broken.innerHTML = '<div class="broken-icon">⚠️</div>' +
                    '<p class="broken-name" title="' + escapeHtml(slot.name) + '">' + escapeHtml(slot.name) + '</p>' +
                    '<p class="broken-error">' + escapeHtml(slot.error || '图片不符合要求') + '</p>';
                item.appendChild(broken);
            } else {
                var img = document.createElement('img');
                img.alt = slot.name;
                if (slot.objectUrl) {
                    img.src = slot.objectUrl;
                } else if (slot.file) {
                    img.src = URL.createObjectURL(slot.file);
                    slot.objectUrl = img.src;
                }
                img.addEventListener('click', function () {
                    if (img.src) window.open(img.src);
                });
                item.appendChild(img);
            }

            var controls = document.createElement('div');
            controls.className = 'slot-controls';
            controls.innerHTML =
                '<button type="button" class="slot-btn" data-act="left" title="前移">◀</button>' +
                '<button type="button" class="slot-btn" data-act="reselect" title="重新选择">🔄</button>' +
                '<button type="button" class="slot-btn slot-btn-danger" data-act="remove" title="移除">✕</button>' +
                '<button type="button" class="slot-btn" data-act="right" title="后移">▶</button>';
            controls.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-act]');
                if (!btn) return;
                handleSlotAction(btn.dataset.act, slot.id);
            });
            item.appendChild(controls);

            // 拖拽排序
            item.addEventListener('dragstart', function (e) {
                dragSrcId = slot.id;
                item.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', slot.id); } catch (err) {}
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('dragging');
                els.multiUpload.querySelectorAll('.image-slot').forEach(function (n) {
                    n.classList.remove('drag-over');
                });
            });
            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (dragSrcId !== slot.id) item.classList.add('drag-over');
            });
            item.addEventListener('dragleave', function () {
                item.classList.remove('drag-over');
            });
            item.addEventListener('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                item.classList.remove('drag-over');
                var srcId = dragSrcId;
                if (!srcId || srcId === slot.id) return;
                moveSlotBefore(srcId, slot.id);
            });

            els.multiUpload.insertBefore(item, els.uploadAdd);
        });

        els.uploadAdd.style.display = slots.length >= MAX_IMAGES ? 'none' : 'flex';
        updateImageTip();
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function moveSlotBefore(srcId, targetId) {
        var srcIdx = slots.findIndex(function (s) { return s.id === srcId; });
        var targetIdx = slots.findIndex(function (s) { return s.id === targetId; });
        if (srcIdx < 0 || targetIdx < 0) return;
        var moved = slots.splice(srcIdx, 1)[0];
        targetIdx = slots.findIndex(function (s) { return s.id === targetId; });
        slots.splice(targetIdx, 0, moved);
        renderSlots();
        scheduleSave();
    }

    function updateImageTip() {
        var invalid = slots.filter(function (s) { return s.status === 'invalid'; });
        if (invalid.length) {
            els.imageTip.style.display = 'block';
            els.imageTip.className = 'form-image-tip has-error';
            els.imageTip.textContent = '有 ' + invalid.length + ' 张图片不符合要求，可点 🔄 单独替换或 ✕ 移除；文字内容不受影响。';
        } else {
            els.imageTip.style.display = 'none';
        }
    }

    function handleSlotAction(act, id) {
        var idx = slots.findIndex(function (s) { return s.id === id; });
        if (idx < 0) return;

        if (act === 'left' && idx > 0) {
            var t1 = slots[idx - 1];
            slots[idx - 1] = slots[idx];
            slots[idx] = t1;
            renderSlots();
            scheduleSave();
        } else if (act === 'right' && idx < slots.length - 1) {
            var t2 = slots[idx + 1];
            slots[idx + 1] = slots[idx];
            slots[idx] = t2;
            renderSlots();
            scheduleSave();
        } else if (act === 'remove') {
            var removed = slots[idx];
            if (removed.objectUrl) URL.revokeObjectURL(removed.objectUrl);
            slots.splice(idx, 1);
            idbDelete(id).catch(function () {});
            renderSlots();
            scheduleSave();
        } else if (act === 'reselect') {
            pickFilesForSlot(id);
        }
    }

    /* ============ 添加 / 替换图片 ============ */

    function addFiles(fileList) {
        var files = Array.prototype.slice.call(fileList);
        files.forEach(function (file) {
            if (slots.length >= MAX_IMAGES) return;
            var error = validateImage(file);
            var slot = {
                id: uuid(),
                name: file.name,
                size: file.size,
                type: file.type,
                status: error ? 'invalid' : 'ok',
                error: error,
                file: error ? null : file
            };
            // 不合规的图片也占一个槽位，方便单独替换/移除；浏览器拿不到二进制时仍保留文件名信息
            if (!error) {
                slots.push(slot);
                idbPut({ id: slot.id, file: file, name: file.name, type: file.type, savedAt: Date.now() }).catch(function () {});
            } else {
                slots.push(slot);
            }
        });
        renderSlots();
        scheduleSave();
    }

    function replaceSlotFile(id, file) {
        var idx = slots.findIndex(function (s) { return s.id === id; });
        if (idx < 0) return;
        var old = slots[idx];
        var error = validateImage(file);

        if (old.objectUrl) URL.revokeObjectURL(old.objectUrl);
        if (!error) {
            idbPut({ id: id, file: file, name: file.name, type: file.type, savedAt: Date.now() }).catch(function () {});
        } else if (old.status === 'ok') {
            idbDelete(id).catch(function () {});
        }

        slots[idx] = {
            id: id,
            name: file.name,
            size: file.size,
            type: file.type,
            status: error ? 'invalid' : 'ok',
            error: error,
            file: error ? null : file
        };
        renderSlots();
        scheduleSave();
    }

    function pickFilesForSlot(existingId) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/gif,image/webp';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) {
                if (existingId) replaceSlotFile(existingId, input.files[0]);
                else addFiles(input.files);
            }
            document.body.removeChild(input);
        });
        input.click();
    }

    els.uploadAdd.addEventListener('click', function () {
        if (slots.length >= MAX_IMAGES) return;
        els.imageInput.click();
    });
    els.imageInput.addEventListener('change', function () {
        if (els.imageInput.files && els.imageInput.files.length) addFiles(els.imageInput.files);
        els.imageInput.value = '';
    });

    /* ============ 草稿保存 / 恢复 ============ */

    function collectMeta() {
        return {
            version: 1,
            draftKey: els.draftKey.value || uuid(),
            savedAt: Date.now(),
            form: {
                nickname: els.nickname.value,
                phone: els.phone.value,
                type: getType(),
                title: els.title.value,
                content: els.content.value
            },
            slots: slots.map(function (s) {
                return {
                    id: s.id,
                    name: s.name,
                    size: s.size,
                    type: s.type,
                    status: s.status,
                    error: s.error || null
                };
            })
        };
    }

    function hasDraftContent(meta) {
        if (!meta) return false;
        var f = meta.form || {};
        return !!(f.nickname || f.phone || f.title || f.content || (meta.slots && meta.slots.length));
    }

    function persistNow() {
        if (!restoreDone) return;
        var meta = collectMeta();
        els.draftKey.value = meta.draftKey;
        try {
            if (hasDraftContent(meta)) {
                localStorage.setItem(DRAFT_KEY, JSON.stringify(meta));
                els.toolbar.style.display = 'flex';
                els.draftStatus.textContent = '📌 草稿已自动暂存 ' + new Date().toLocaleTimeString();
            } else {
                localStorage.removeItem(DRAFT_KEY);
                els.toolbar.style.display = 'none';
            }
        } catch (err) {
            console.warn('草稿保存失败:', err);
        }
    }

    var scheduleSave = debounce(persistNow, 500);

    function bindAutoSave() {
        ['input', 'change'].forEach(function (evt) {
            form.addEventListener(evt, function (e) {
                if (e.target && e.target.id === 'imageInput') return;
                scheduleSave();
            }, true);
        });
        window.addEventListener('beforeunload', function () {
            persistNow();
        });
    }

    function restoreDraft(meta) {
        var f = meta.form || {};
        els.nickname.value = f.nickname || '';
        els.phone.value = f.phone || '';
        els.title.value = f.title || '';
        els.content.value = f.content || '';
        setType(f.type || 'help');
        els.charCount.textContent = (f.content || '').length;
        els.draftKey.value = meta.draftKey || uuid();
        els.toolbar.style.display = 'flex';
        els.draftStatus.textContent = '📌 已恢复上次暂存的草稿（' +
            new Date(meta.savedAt || Date.now()).toLocaleString() + '）';

        var savedSlots = meta.slots || [];
        var jobs = savedSlots.map(function (s) {
            if (s.status === 'invalid') {
                return {
                    id: s.id, name: s.name, size: s.size, type: s.type,
                    status: 'invalid', error: s.error || '图片不符合要求', file: null
                };
            }
            return idbGet(s.id).then(function (rec) {
                if (rec && rec.file) {
                    // 部分浏览器结构化克隆后 File 原型链可能丢失，统一重建
                    var file = rec.file instanceof File
                        ? rec.file
                        : new File([rec.file], rec.name || s.name || 'image.jpg', { type: rec.type || s.type || 'image/jpeg' });
                    return { id: s.id, name: rec.name || s.name, size: file.size, type: file.type, status: 'ok', error: null, file: file };
                }
                // IDB 中二进制丢失（如浏览器清理）：保留槽位提示用户重新选择
                return { id: s.id, name: s.name || '图片', size: s.size || 0, type: s.type || '', status: 'invalid', error: '本地图片已失效，请重新选择', file: null };
            }).catch(function () {
                return { id: s.id, name: s.name || '图片', size: 0, type: '', status: 'invalid', error: '本地图片已失效，请重新选择', file: null };
            });
        });

        return Promise.all(jobs).then(function (restored) {
            slots = restored;
            renderSlots();
        });
    }

    function clearDraft(silent) {
        slots.forEach(function (s) { if (s.objectUrl) URL.revokeObjectURL(s.objectUrl); });
        slots = [];
        renderSlots();
        localStorage.removeItem(DRAFT_KEY);
        idbClear().catch(function () {});
        els.draftKey.value = uuid();
        els.toolbar.style.display = 'none';
        if (!silent) showToast && showToast('草稿已清除', 'info');
    }

    /* ============ 草稿导出 / 导入 ============ */

    els.exportBtn.addEventListener('click', function () {
        if (!hasDraftContent(collectMeta())) {
            showToast && showToast('暂无草稿内容可导出', 'warning');
            return;
        }
        var exportData = {
            app: 'community_board_draft',
            version: 1,
            exportedAt: new Date().toISOString(),
            form: {
                nickname: els.nickname.value,
                phone: els.phone.value,
                type: getType(),
                title: els.title.value,
                content: els.content.value
            },
            images: []
        };

        var validSlots = slots.filter(function (s) { return s.status === 'ok' && s.file; });
        if (!validSlots.length) {
            downloadDraft(exportData);
            return;
        }
        els.exportBtn.disabled = true;
        els.exportBtn.textContent = '打包中...';
        Promise.all(validSlots.map(function (s) {
            return fileToDataURL(s.file).then(function (dataUrl) {
                exportData.images.push({
                    name: s.name,
                    type: s.type,
                    size: s.size,
                    dataBase64: dataUrl
                });
            });
        })).then(function () {
            downloadDraft(exportData);
        }).catch(function () {
            showToast && showToast('导出失败，请重试', 'error');
        }).then(function () {
            els.exportBtn.disabled = false;
            els.exportBtn.textContent = '📦 导出草稿文件';
        });
    });

    function downloadDraft(data) {
        var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        var ts = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
        a.download = '留言草稿_' + ts + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
        showToast && showToast('草稿已导出为文件（共 ' + data.images.length + ' 张图片，按当前顺序）', 'success');
    }

    els.importBtn.addEventListener('click', function () {
        els.importFile.click();
    });

    els.importFile.addEventListener('change', function () {
        var file = els.importFile.files && els.importFile.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function () {
            try {
                var data = JSON.parse(reader.result);
                if (!data || data.app !== 'community_board_draft' || !data.form) {
                    throw new Error('文件格式不正确');
                }
                importDraft(data);
            } catch (err) {
                showToast && showToast('导入失败：' + err.message, 'error');
            } finally {
                els.importFile.value = '';
            }
        };
        reader.readAsText(file);
    });

    function importDraft(data) {
        // 清掉当前草稿槽位与二进制
        slots.forEach(function (s) { if (s.objectUrl) URL.revokeObjectURL(s.objectUrl); });
        slots = [];
        var idbJobs = [];

        (data.images || []).forEach(function (img) {
            if (img.dataBase64) {
                var f = dataURLtoFile(img.dataBase64, img.name, img.type);
                var error = validateImage(f);
                var slot = {
                    id: uuid(),
                    name: img.name || f.name,
                    size: f.size,
                    type: f.type,
                    status: error ? 'invalid' : 'ok',
                    error: error,
                    file: error ? null : f
                };
                slots.push(slot);
                if (!error) {
                    idbJobs.push(idbPut({ id: slot.id, file: f, name: slot.name, type: f.type, savedAt: Date.now() }));
                }
            } else {
                // 仅清单无二进制的草稿：占位，等待按文件名补图
                slots.push({
                    id: uuid(), name: img.name || '待恢复图片', size: img.size || 0,
                    type: img.type || '', status: 'invalid', error: '草稿文件未包含图片数据，请点击 🔄 选择「' + (img.name || '对应文件') + '」', file: null
                });
            }
        });

        els.nickname.value = data.form.nickname || '';
        els.phone.value = data.form.phone || '';
        els.title.value = data.form.title || '';
        els.content.value = data.form.content || '';
        setType(data.form.type || 'help');
        els.charCount.textContent = els.content.value.length;
        // 导入的草稿视为一次全新的待提交内容，分配新的草稿键，避免与历史提交撞键
        els.draftKey.value = uuid();

        Promise.all(idbJobs).catch(function () {}).then(function () {
            renderSlots();
            persistNow();
            showToast && showToast('草稿已导入，图片按文件中的顺序恢复（' + slots.length + ' 张）', 'success');
        });
    }

    els.clearBtn.addEventListener('click', function () {
        if (!confirm('确定清除本地暂存的草稿吗？此操作不可恢复。')) return;
        form.reset();
        setType('help');
        els.charCount.textContent = '0';
        clearDraft();
    });

    /* ============ 提交 ============ */

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var invalid = slots.filter(function (s) { return s.status === 'invalid'; });
        if (invalid.length) {
            showToast && showToast('有 ' + invalid.length + ' 张图片不符合要求，请替换或移除后再提交（文字已保留）', 'warning');
            return;
        }

        els.submitBtn.disabled = true;
        els.submitBtn.textContent = '提交中...';

        var fd = new FormData();
        fd.append('nickname', els.nickname.value);
        fd.append('phone', els.phone.value);
        fd.append('type', getType());
        fd.append('title', els.title.value);
        fd.append('content', els.content.value);
        fd.append('draft_key', els.draftKey.value);
        fd.append('visitor_id', els.visitorId.value || '');
        // 按当前排序追加图片，槽位序号即展示顺序
        slots.forEach(function (s, i) {
            if (s.file) fd.append('images[' + i + ']', s.file, s.name);
        });

        fetch('api/submit.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.code === 0) {
                    // 提交成功：清除草稿，跳回发布页继续编辑下一条
                    localStorage.removeItem(DRAFT_KEY);
                    idbClear().catch(function () {});
                    var next = res.data && res.data.message_id ? res.data.message_id : '';
                    alert(res.data && res.data.duplicated ? res.msg : '留言提交成功，等待审核！');
                    window.location.href = 'submit.php?submitted=' + encodeURIComponent(next);
                } else {
                    if (res.data && res.data.image_errors) {
                        markServerImageErrors(res.data.image_errors);
                    }
                    alert(res.msg || '提交失败');
                    els.submitBtn.disabled = false;
                    els.submitBtn.textContent = '提交留言';
                }
            })
            .catch(function () {
                alert('网络错误，请重试（已填写内容和图片均已暂存）');
                els.submitBtn.disabled = false;
                els.submitBtn.textContent = '提交留言';
            });
    });

    // 服务端逐图校验结果：按槽位序号标记，可单独替换
    function markServerImageErrors(imageErrors) {
        Object.keys(imageErrors).forEach(function (idxStr) {
            var idx = parseInt(idxStr, 10);
            var slot = slots[idx];
            if (!slot) return;
            if (slot.objectUrl) URL.revokeObjectURL(slot.objectUrl);
            slots[idx] = {
                id: slot.id,
                name: slot.name,
                size: slot.size,
                type: slot.type,
                status: 'invalid',
                error: imageErrors[idxStr],
                file: null
            };
            idbDelete(slot.id).catch(function () {});
        });
        renderSlots();
        scheduleSave();
    }

    /* ============ 初始化 ============ */

    function init() {
        els.draftKey.value = uuid();
        els.visitorId.value = getCookie('visitor_id') || '';

        els.content.addEventListener('input', function () {
            els.charCount.textContent = this.value.length;
        });

        bindAutoSave();

        // 提交成功跳回本页时，清除地址栏参数，展示全新表单
        var params = new URLSearchParams(window.location.search);
        if (params.has('submitted')) {
            history.replaceState(null, '', 'submit.php');
        }

        var raw = null;
        try { raw = localStorage.getItem(DRAFT_KEY); } catch (err) {}

        if (raw) {
            try {
                draftMeta = JSON.parse(raw);
                return restoreDraft(draftMeta).then(function () {
                    restoreDone = true;
                });
            } catch (err) {
                console.warn('草稿恢复失败:', err);
            }
        }
        restoreDone = true;
    }

    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
    }

    // bfcache 返回页面时也保持最新草稿状态
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            els.submitBtn.disabled = false;
            els.submitBtn.textContent = '提交留言';
        }
    });

    init();
})();
