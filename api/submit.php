<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$nickname = trim($_POST['nickname'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$type = $_POST['type'] ?? 'help';
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$draftKey = trim($_POST['draft_key'] ?? '');
$visitorId = trim($_POST['visitor_id'] ?? '');
if ($visitorId !== '' && !preg_match('/^[a-f0-9]{32}$/i', $visitorId)) $visitorId = '';

// 文本校验
if (empty($nickname)) jsonResponse(1, '请输入昵称');
if (mb_strlen($nickname) > 50) jsonResponse(1, '昵称不能超过50个字符');
if (mb_strlen($phone) > 20) jsonResponse(1, '联系电话不能超过20个字符');
if (empty($title)) jsonResponse(1, '请输入标题');
if (mb_strlen($title) > 100) jsonResponse(1, '标题不能超过100个字符');
if (empty($content)) jsonResponse(1, '请输入内容');
if (mb_strlen($content) > 2000) jsonResponse(1, '内容不能超过2000个字符');
if (!in_array($type, ['help', 'suggest', 'lost'])) jsonResponse(1, '无效的留言类型');
if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $draftKey)) jsonResponse(1, '草稿标识无效，请刷新页面重试');

$maxImages = 9;
$maxSize = 5 * 1024 * 1024;
$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

/**
 * 收集本次提交的图片槽位，保持前端排序：
 * 新前端使用 images[0]、images[1]...；旧前端使用单图字段 image
 * 返回 [slotIndex => $_FILES 结构数组]（按序号升序）
 */
function collectImageSlots() {
    $slots = [];
    if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
        foreach ($_FILES['images']['name'] as $index => $name) {
            if ($_FILES['images']['error'][$index] === UPLOAD_ERR_NO_FILE) continue;
            $slots[(int)$index] = [
                'name'  => $_FILES['images']['name'][$index],
                'type'  => $_FILES['images']['type'][$index],
                'tmp_name' => $_FILES['images']['tmp_name'][$index],
                'error' => $_FILES['images']['error'][$index],
                'size'  => $_FILES['images']['size'][$index],
            ];
        }
    } elseif (!empty($_FILES['image']['name'])) {
        // 兼容旧版单图提交
        if ($_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $slots[0] = $_FILES['image'];
        }
    }
    ksort($slots);
    return $slots;
}

$slots = collectImageSlots();
if (count($slots) > $maxImages) {
    jsonResponse(1, "最多只能上传 {$maxImages} 张图片");
}

// 逐张校验：某张不合规时只标记该槽位，文字内容不动，前端可单独替换该图
$imageErrors = [];
foreach ($slots as $index => $file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $imageErrors[$index] = '图片上传失败，请重新选择该图片';
        continue;
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $imageErrors[$index] = '图片上传失败，请重新选择该图片';
        continue;
    }
    if ($file['size'] > $maxSize) {
        $imageErrors[$index] = '图片大小不能超过5MB';
        continue;
    }
    if (!function_exists('finfo_open')) {
        jsonResponse(500, '服务器未开启 fileinfo 扩展，无法校验图片');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowedMimes[$mime])) {
        $imageErrors[$index] = '仅支持 JPG、PNG、GIF、WebP 格式';
        continue;
    }
    $slots[$index]['real_mime'] = $mime;
}

if ($imageErrors) {
    jsonResponse(1, '部分图片不符合要求，请调整后重新提交', [
        'image_errors' => $imageErrors,
    ]);
}

$db = getDB();

// 幂等：同一草稿（draft_key）重复提交只生成一条留言
$stmt = $db->prepare("SELECT id, status FROM messages WHERE draft_key = ?");
$stmt->execute([$draftKey]);
$existing = $stmt->fetch();
if ($existing) {
    jsonResponse(0, '该草稿已提交，请勿重复提交', [
        'duplicated' => true,
        'message_id' => (int)$existing['id'],
        'status' => (int)$existing['status'],
    ]);
}

// 保存图片
$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$savedPaths = [];
foreach ($slots as $file) {
    $ext = $allowedMimes[$file['real_mime']];
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        // 回滚已移动的图片
        foreach ($savedPaths as $p) {
            $f = __DIR__ . '/../' . $p;
            if (file_exists($f)) @unlink($f);
        }
        jsonResponse(1, '图片上传失败，请重试');
    }
    $savedPaths[] = 'uploads/' . $filename;
}

// 入库（留言 + 有序图片）
try {
    $db->beginTransaction();

    $firstImage = $savedPaths[0] ?? null;
    $stmt = $db->prepare("INSERT INTO messages (nickname, phone, type, title, content, image, visitor_id, draft_key, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
    $stmt->execute([$nickname, $phone ?: null, $type, $title, $content, $firstImage, $visitorId ?: null, $draftKey]);
    $messageId = (int)$db->lastInsertId();

    if ($savedPaths) {
        $imgStmt = $db->prepare("INSERT INTO message_images (message_id, image, sort_order) VALUES (?, ?, ?)");
        foreach ($savedPaths as $order => $path) {
            $imgStmt->execute([$messageId, $path, $order]);
        }
    }

    $db->commit();

    jsonResponse(0, '留言提交成功，等待审核', [
        'message_id' => $messageId,
    ]);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();

    // 并发情况下唯一键兜底：同一草稿只保留一条
    if ($e->getCode() === '23000') {
        $stmt = $db->prepare("SELECT id, status FROM messages WHERE draft_key = ?");
        $stmt->execute([$draftKey]);
        $existing = $stmt->fetch();
        if ($existing) {
            foreach ($savedPaths as $p) {
                $f = __DIR__ . '/../' . $p;
                if (file_exists($f)) @unlink($f);
            }
            jsonResponse(0, '该草稿已提交，请勿重复提交', [
                'duplicated' => true,
                'message_id' => (int)$existing['id'],
                'status' => (int)$existing['status'],
            ]);
        }
    }
    jsonResponse(500, '服务器错误，请稍后重试');
}
