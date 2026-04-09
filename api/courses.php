<?php
// api/courses.php
require_once __DIR__ . '/helpers.php';
cors();

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDB();

// ── LIST ALL COURSES ─────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $stmt = $db->query("
        SELECT c.*,
               COUNT(DISTINCT e.user_id)                          AS enrolled,
               COUNT(DISTINCT CASE WHEN e.completed=1 THEN e.user_id END) AS completed_count
        FROM courses c
        LEFT JOIN enrollments e ON e.course_id = c.id
        GROUP BY c.id ORDER BY c.id");
    ok($stmt->fetchAll());
}

// ── MY ENROLLMENTS ───────────────────────────────────────────
elseif ($method === 'GET' && $action === 'mine') {
    $stmt = $db->prepare("
        SELECT c.*, e.progress, e.completed, e.completed_date, e.enrolled_at
        FROM courses c
        LEFT JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
        WHERE c.active = 1
        ORDER BY c.id");
    $stmt->execute([$user['id']]);
    ok($stmt->fetchAll());
}

// ── ENROLL ───────────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'enroll') {
    $courseId = (int)(body()['course_id'] ?? 0);
    if (!$courseId) fail('Course ID required');
    $chk = $db->prepare("SELECT id FROM enrollments WHERE user_id=? AND course_id=?");
    $chk->execute([$user['id'], $courseId]);
    if ($chk->fetch()) fail('Already enrolled');
    $db->prepare("INSERT INTO enrollments (user_id,course_id,progress,completed) VALUES (?,?,0,0)")
       ->execute([$user['id'],$courseId]);
    ok(null, 'Enrolled successfully');
}

// ── UPDATE PROGRESS ──────────────────────────────────────────
elseif ($method === 'POST' && $action === 'progress') {
    $b        = body();
    $courseId = (int)($b['course_id'] ?? 0);
    $add      = (int)($b['add']       ?? 20); // percentage to add
    $chk = $db->prepare("SELECT * FROM enrollments WHERE user_id=? AND course_id=?");
    $chk->execute([$user['id'],$courseId]);
    $enroll = $chk->fetch();
    if (!$enroll) fail('Not enrolled in this course');
    $newProg  = min(100, $enroll['progress'] + $add);
    $done     = $newProg >= 100 ? 1 : 0;
    $doneDate = $done ? date('Y-m-d') : null;
    $db->prepare("UPDATE enrollments SET progress=?,completed=?,completed_date=? WHERE user_id=? AND course_id=?")
       ->execute([$newProg,$done,$doneDate,$user['id'],$courseId]);
    ok(['progress'=>$newProg,'completed'=>(bool)$done,'completed_date'=>$doneDate],
        $done ? 'Course completed! 🏆' : "Progress: {$newProg}%");
}

// ── ADD COURSE (admin) ───────────────────────────────────────
elseif ($method === 'POST' && $action === 'add') {
    requireRole($user, 'admin');
    $b = body();
    $title = trim($b['title'] ?? '');
    if (!$title) fail('Title required');
    $code = nextCode('CRS','courses','course_code');
    $db->prepare("INSERT INTO courses (course_code,title,emoji,description,modules,duration,level,cert_title,active)
                  VALUES (?,?,?,?,?,?,?,?,0)")
       ->execute([$code,$title,$b['emoji']??'📚',$b['description']??'',(int)($b['modules']??8),
                  $b['duration']??'',$b['level']??'Beginner',$b['cert_title']??'']);
    ok(['course_code'=>$code], "Course '$title' added");
}

// ── TOGGLE COURSE ────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'toggle') {
    requireRole($user, 'admin');
    $id = (int)(body()['id'] ?? 0);
    $db->prepare("UPDATE courses SET active = NOT active WHERE id=?")->execute([$id]);
    ok(null, 'Course status updated');
}

else fail('Unknown action');
