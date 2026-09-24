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

            <?php if (isset($_GET['submitted'])): ?>
            <div class="submit-success-tip" id="submitSuccessTip">
                ✅ 留言已提交，正在等待审核。你可以继续编辑发布下一条留言。
            </div>
            <?php endif; ?>

            <div class="draft-toolbar" id="draftToolbar" style="display:none;">
                <span class="draft-status" id="draftStatus">📌 草稿已自动暂存</span>
                <div class="draft-actions">
                    <button type="button" class="btn btn-xs btn-secondary" id="exportDraftBtn">📦 导出草稿文件</button>
                    <button type="button" class="btn btn-xs btn-secondary" id="importDraftBtn">📂 导入草稿文件</button>
                    <button type="button" class="btn btn-xs btn-danger" id="clearDraftBtn">🗑 清除草稿</button>
                    <input type="file" id="importDraftFile" accept="application/json,.json" hidden>
                </div>
            </div>

            <form id="submitForm" class="submit-form" enctype="multipart/form-data">
                <input type="hidden" name="draft_key" id="draftKey">
                <input type="hidden" name="visitor_id" id="visitorId">

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
                    <label>上传图片 <span class="text-muted">（最多 9 张，可拖动调整顺序，第一张为封面）</span></label>
                    <div class="multi-upload" id="multiUpload">
                        <!-- 图片槽位由 JS 渲染 -->
                        <div class="upload-add" id="uploadAdd" title="添加图片">
                            <div class="upload-add-inner">
                                <div class="upload-icon">📷</div>
                                <p>添加图片</p>
                                <span class="upload-hint">JPG/PNG/GIF/WebP，单张 ≤5MB</span>
                            </div>
                            <input type="file" id="imageInput" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
                        </div>
                    </div>
                    <p class="form-image-tip" id="imageFormTip" style="display:none;"></p>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">提交留言</button>
                    <a href="index.php" class="btn btn-secondary btn-lg">返回首页</a>
                </div>
            </form>
        </div>
    </div>
</section>

<script src="assets/js/draft.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
