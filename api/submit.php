<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

const MESSAGE_MAX_IMAGES = 6;
const MESSAGE_IMAGE_MAX_SIZE = 5 * 1024 * 1024;

$nickname = trim($_POST['nickname'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$type = $_POST['type'] ?? 'help';
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$submitToken = trim($_POST['submit_token'] ?? '');

// 验证
if (empty($nickname)) jsonResponse(1, '请输入昵称');
if (mb_strlen($nickname) > 50) jsonResponse(1, '昵称不能超过50个字符');
if (mb_strlen($phone) > 20) jsonResponse(1, '联系电话不能超过20个字符');
if (empty($title)) jsonResponse(1, '请输入标题');
if (mb_strlen($title) > 100) jsonResponse(1, '标题不能超过100个字符');
if (empty($content)) jsonResponse(1, '请输入内容');
if (mb_strlen($content) > 2000) jsonResponse(1, '内容不能超过2000个字符');
if (!in_array($type, ['help', 'suggest', 'lost'])) jsonResponse(1, '无效的留言类型');
// 草稿令牌：保证同一草稿重复提交只生成一条留言
if (!preg_match('/^[a-f0-9]{16,64}$/', $submitToken)) jsonResponse(1, '无效的草稿令牌，请刷新页面重试');

$db = getDB();

// 幂等：同一草稿（submit_token）已提交过则直接返回原留言，不再入库
$stmt = $db->prepare("SELECT id, status FROM messages WHERE submit_token = ?");
$stmt->execute([$submitToken]);
$existing = $stmt->fetch();
if ($existing) {
    jsonResponse(0, '该草稿已提交，请勿重复提交', [
        'message_id' => (int)$existing['id'],
        'duplicated' => true,
    ]);
}

/**
 * 收集本次上传的图片：新表单字段 images[]（多张、按顺序），
 * 同时兼容旧表单字段 image（单张）。
 * 返回以表单顺序排列的文件描述数组。
 */
function collectUploadedImages() {
    $files = [];

    if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
        $count = count($_FILES['images']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['images']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $files[] = [
                'index' => $i,
                'name' => $_FILES['images']['name'][$i],
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'error' => $_FILES['images']['error'][$i],
                'size' => $_FILES['images']['size'][$i],
            ];
        }
    } elseif (!empty($_FILES['image']['name']) && !is_array($_FILES['image']['name'])) {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $files[] = [
                'index' => 0,
                'name' => $_FILES['image']['name'],
                'tmp_name' => $_FILES['image']['tmp_name'],
                'error' => $_FILES['image']['error'],
                'size' => $_FILES['image']['size'],
            ];
        }
    }

    return $files;
}

$allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$allowedExt = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];

$uploaded = collectUploadedImages();

if (count($uploaded) > MESSAGE_MAX_IMAGES) {
    jsonResponse(1, '最多只能上传' . MESSAGE_MAX_IMAGES . '张图片');
}

// 逐张校验：任一张不合规都不创建留言，返回出错位置，便于前端原位单独恢复
$pending = [];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$uploadDir = __DIR__ . '/../uploads/';

foreach ($uploaded as $f) {
    $pos = $f['index'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(1, '第' . ($pos + 1) . '张图片上传失败，请单独重新选择', ['image_index' => $pos]);
    }
    if ($f['size'] > MESSAGE_IMAGE_MAX_SIZE) {
        jsonResponse(1, '第' . ($pos + 1) . '张图片大小不能超过5MB，请单独重新选择', ['image_index' => $pos]);
    }

    $mime = finfo_file($finfo, $f['tmp_name']);
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

    if (!isset($allowedMime[$mime])) {
        jsonResponse(1, '第' . ($pos + 1) . '张图片仅支持 JPG、PNG、GIF、WebP 格式，请单独重新选择', ['image_index' => $pos]);
    }
    if (!isset($allowedExt[$ext])) {
        jsonResponse(1, '第' . ($pos + 1) . '张图片格式不被支持，请单独重新选择', ['image_index' => $pos]);
    }

    $pending[] = ['file' => $f, 'ext' => $allowedExt[$ext]];
}
finfo_close($finfo);

// 入库并按上传顺序保存图片
$movedFiles = [];
$db->beginTransaction();
try {
    $stmt = $db->prepare("INSERT INTO messages (nickname, phone, type, title, content, image, submit_token, status) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
    $stmt->execute([$nickname, $phone ?: null, $type, $title, $content, null, $submitToken]);
    $messageId = (int)$db->lastInsertId();

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $imageRows = [];
    foreach ($pending as $order => $p) {
        // 同一秒内多张图片用序号+随机串保证文件名唯一
        $filename = date('Ymd_His') . '_' . $order . '_' . bin2hex(random_bytes(4)) . '.' . $p['ext'];
        $targetPath = $uploadDir . $filename;

        if (!move_uploaded_file($p['file']['tmp_name'], $targetPath)) {
            throw new RuntimeException('第' . ($order + 1) . '张图片上传失败');
        }

        $relPath = 'uploads/' . $filename;
        $movedFiles[] = $targetPath;
        $imageRows[] = [$messageId, $relPath, $order];
    }

    if (!empty($imageRows)) {
        $imgStmt = $db->prepare("INSERT INTO message_images (message_id, image, sort_order) VALUES (?, ?, ?)");
        foreach ($imageRows as $row) {
            $imgStmt->execute($row);
        }
        // 第一张回填到旧字段，保持旧单图逻辑可用
        $db->prepare("UPDATE messages SET image = ? WHERE id = ?")->execute([$imageRows[0][1], $messageId]);
    }

    $db->commit();
    jsonResponse(0, '留言提交成功，等待审核', ['message_id' => $messageId, 'duplicated' => false]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($movedFiles as $f) {
        if (is_file($f)) @unlink($f);
    }
    // 并发下唯一索引冲突：等价于同一草稿重复提交
    if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) == 1062) {
        $stmt = $db->prepare("SELECT id FROM messages WHERE submit_token = ?");
        $stmt->execute([$submitToken]);
        $dupId = (int)$stmt->fetchColumn();
        jsonResponse(0, '该草稿已提交，请勿重复提交', ['message_id' => $dupId, 'duplicated' => true]);
    }
    if ($e instanceof RuntimeException) {
        jsonResponse(1, $e->getMessage());
    }
    jsonResponse(500, '服务器错误，请稍后重试');
}
