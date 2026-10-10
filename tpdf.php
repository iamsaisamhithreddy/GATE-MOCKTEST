<?php
include 'config.php';
require_once __DIR__ . '/dompdf/autoload.inc.php';
require_once __DIR__ . '/modules/assessment.php';
ensure_assessment_schema($conn);   // set_time.pass_marks and set_time.max_attempts
use Dompdf\Dompdf;
use Dompdf\Options;


// 1. Fetch subjects for the dropdown
$subjects_res = $conn->query("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC");
$subjectOptions = [];
while ($row = $subjects_res->fetch_assoc()) {
    $subjectOptions[$row['subject_id']] = $row['subject_name'];
}

// 2. Handle POST Data
$subject_id         = intval($_POST['subject_id'] ?? ($_GET['sub'] ?? 0));
$action             = $_POST['action'] ?? '';
$start_datetime_str = $_POST['start_datetime'] ?? '';
$start_datetime     = (!empty($start_datetime_str)) ? new DateTime($start_datetime_str) : null;

$attempt_days = isset($_POST['attempt_days']) ? intval($_POST['attempt_days']) : 3;
$gap_days     = isset($_POST['gap_days']) ? intval($_POST['gap_days']) : 0; 

$message = '';
$sets = [];
$has_existing_schedule = false;

// --- UPDATED: Handle Topic Name AND Individual Time Updates ---
if ($action === 'update_set_details' && $subject_id > 0) {
    if (isset($_POST['sets_data']) && is_array($_POST['sets_data'])) {
        foreach ($_POST['sets_data'] as $set_no => $data) {
            $topic_name = $data['topic_name'];
            $st         = !empty($data['start_time']) ? date('Y-m-d H:i:s', strtotime($data['start_time'])) : null;
            $et         = !empty($data['end_time']) ? date('Y-m-d H:i:s', strtotime($data['end_time'])) : null;
            $at         = !empty($data['attempt_till']) ? date('Y-m-d H:i:s', strtotime($data['attempt_till'])) : null;

            // Update Topic Name
            $stmt1 = $conn->prepare("INSERT INTO set_definitions (subject_id, set_no, topic_name) 
                                    VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE topic_name = VALUES(topic_name)");
            $stmt1->bind_param("iis", $subject_id, $set_no, $topic_name);
            $stmt1->execute();
            $stmt1->close();

            // Update Individual Times
            $stmt2 = $conn->prepare("UPDATE set_time SET start_time=?, end_time=?, attempt_till=? WHERE subject_id=? AND set_no=?");
            $stmt2->bind_param("sssii", $st, $et, $at, $subject_id, $set_no);
            $stmt2->execute();
            $stmt2->close();

            // Passing marks (empty = not set) and attempt limit (0 = unlimited) for the assessment page
            $pm = (isset($data['pass_marks']) && $data['pass_marks'] !== '') ? (float)$data['pass_marks'] : null;
            $ma = max(0, (int)($data['max_attempts'] ?? 0));
            $stmt3 = $conn->prepare("UPDATE set_time SET pass_marks=?, max_attempts=? WHERE subject_id=? AND set_no=?");
            $stmt3->bind_param("diii", $pm, $ma, $subject_id, $set_no);
            $stmt3->execute();
            $stmt3->close();
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=success&sub=" . $subject_id);
        exit;
    }
}

// 3. Load sets for current selection
if ($subject_id > 0) {
    $sql = "SELECT s.set_no, s.duration_minutes, s.start_time, s.end_time, s.attempt_till, s.pass_marks, s.max_attempts, sd.topic_name
            FROM set_time s 
            LEFT JOIN set_definitions sd ON s.subject_id = sd.subject_id AND s.set_no = sd.set_no
            WHERE s.subject_id = {$subject_id} 
            ORDER BY s.set_no ASC";
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        $sets[] = $row;
        if (!empty($row['start_time'])) $has_existing_schedule = true;
    }
}

// 4. Logic: Generate / Recalculate (Bulk)
if (($action === 'generate_new' || $action === 'recalculate_schedule') && $subject_id > 0 && $start_datetime) {
    $current_ptr = clone $start_datetime;
    foreach ($sets as $row) {
        $start = clone $current_ptr;
        $end   = clone $start;
        $end->modify("+{$row['duration_minutes']} minutes");

        $attempt_end = clone $start;
        if ($attempt_days > 0) {
            $attempt_end->modify("+{$attempt_days} days");
        } else {
            $attempt_end = clone $end; 
        }

        $stmt = $conn->prepare("UPDATE set_time SET start_time=?, end_time=?, attempt_till=? WHERE set_no=? AND subject_id=?");
        $stmt->bind_param("sssii", $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $attempt_end->format('Y-m-d H:i:s'), $row['set_no'], $subject_id);
        $stmt->execute();
        $stmt->close();

        if ($gap_days > 0) { $current_ptr->modify("+{$gap_days} days"); }
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=success&sub=" . $subject_id);
    exit;
}

// 5. PDF Logic remains same...
if ($action === 'download_pdf' || $action === 'download_all_pdf') {
    $query = "SELECT s.*, subj.subject_name, sd.topic_name
              FROM set_time s 
              JOIN subjects subj ON s.subject_id = subj.subject_id 
              LEFT JOIN set_definitions sd ON s.subject_id = sd.subject_id AND s.set_no = sd.set_no ";
    
    if ($action === 'download_pdf' && $subject_id > 0) {
        $query .= "WHERE s.subject_id = $subject_id ";
    }
    $query .= "ORDER BY subj.subject_name ASC, s.set_no ASC";
    
    $full_res = $conn->query($query);
    $all_data = [];
    while($r = $full_res->fetch_assoc()) {
        $all_data[$r['subject_name']][] = $r;
    }

    ob_start(); ?>
    <html>
    <head>
        <style>
            body { font-family: sans-serif; font-size: 11px; color: #333; }
            table { width:100%; border-collapse:collapse; margin-top:10px; page-break-inside: auto; }
            th,td { border:1px solid #999; padding:6px; text-align:center; }
            th { background:#2d3436; color:#fff; }
            .subject-block { margin-bottom: 30px; page-break-after: always; }
            .subject-block:last-child { page-break-after: never; }
            h2 { color: #0984e3; border-bottom: 2px solid #0984e3; padding-bottom: 5px; }
        </style>
    </head>
    <body>
        <?php foreach ($all_data as $subj_name => $rows): ?>
        <div class="subject-block">
            <h2><?= htmlspecialchars($subj_name) ?> Schedule</h2>
            <table>
                <tr>
                    <th>Set</th>
                    <th>Topic Name</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Deadline</th>
                </tr>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td>Set <?= $r['set_no'] ?></td>
                    <td><?= htmlspecialchars($r['topic_name'] ?? 'General') ?></td>
                    <td><?= $r['start_time'] ? date('d M, h:i A', strtotime($r['start_time'])) : 'N/A' ?></td>
                    <td><?= $r['end_time'] ? date('d M, h:i A', strtotime($r['end_time'])) : 'N/A' ?></td>
                    <td><?= $r['attempt_till'] ? date('d M, h:i A', strtotime($r['attempt_till'])) : 'N/A' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endforeach; ?>
    </body>
    </html>
    <?php
    $html = ob_get_clean();
    $dompdf = new Dompdf(new Options(['isHtml5ParserEnabled'=>true]));
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4','portrait');
    $dompdf->render();
    $dompdf->stream("Overall_Schedule.pdf", ["Attachment"=>true]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Master Schedule Manager</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; margin: 0; padding: 40px; }
        .card { max-width: 1200px; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); margin: 0 auto; }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #444; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
        .btn { padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.3s; text-decoration: none; display: inline-block; text-align: center; }
        .btn-primary { background: #0984e3; color: white; }
        .btn-success { background: #00b894; color: white; }
        .btn-outline { background: transparent; border: 2px solid #0984e3; color: #0984e3; }
        .btn:hover { opacity: 0.8; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #f0f0f0; }
        th { background: #fafafa; color: #666; text-transform: uppercase; font-size: 11px; }
        .status-pill { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; background: #e1f5fe; color: #01579b; }
        .inline-dt { font-size: 12px; padding: 5px; border: 1px solid #eee; width: 100%; }
    </style>
</head>
<body>

<div class="card">
    <div class="header-flex">
        <h1 style="margin:0; font-size: 24px;">Schedule Manager</h1>
        <form method="POST" style="margin:0;">
            <button type="submit" name="action" value="download_all_pdf" class="btn btn-outline">📥 Download Overall Schedule</button>
        </form>
    </div>

    <?php if (isset($_GET['msg'])) echo "<p style='color:green;'>✅ Changes saved successfully.</p>"; ?>

    <form method="POST">
        <div class="form-grid">
            <div>
                <label>Target Subject</label>
                <select name="subject_id" onchange="this.form.submit()">
                    <option value="0">-- Select Subject --</option>
                    <?php foreach ($subjectOptions as $id => $name): ?>
                        <option value="<?= $id ?>" <?= ($subject_id == $id) ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($subject_id > 0): ?>
            <div>
                <label>Bulk Start Date</label>
                <input type="datetime-local" name="start_datetime" value="<?= $start_datetime_str ?>">
            </div>
            <div>
                <label>Day Gap</label>
                <input type="number" name="gap_days" value="<?= $gap_days ?>" min="0">
            </div>
            <div>
                <label>Window (Days)</label>
                <input type="number" name="attempt_days" value="<?= $attempt_days ?>" min="0">
            </div>
            <?php endif; ?>
        </div>

        <?php if ($subject_id > 0): ?>
            <div style="display: flex; gap: 10px;">
                <button type="submit" name="action" value="generate_new" class="btn btn-success">
                    <?= $has_existing_schedule ? '🔄 Regenerate All' : '🚀 Generate Schedule' ?>
                </button>
                <?php if ($has_existing_schedule): ?>
                    <button type="submit" name="action" value="download_pdf" class="btn btn-primary">📄 Subject PDF</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($subject_id > 0 && !empty($sets)): ?>
        <form method="POST">
            <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
            <table>
                <thead>
                    <tr>
                        <th width="5%">Set</th>
                        <th width="20%">Topic Name</th>
                        <th width="20%">Start Time</th>
                        <th width="20%">End Time</th>
                        <th width="20%">Deadline</th>
                        <th width="10%">Dur.</th>
                        <th width="8%" title="Marks needed to pass. Leave empty if there is no pass mark.">Pass Marks</th>
                        <th width="8%" title="How many times a candidate may take this test. 0 = unlimited.">Max Attempts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sets as $s): ?>
                    <tr>
                        <td><strong><?= $s['set_no'] ?></strong></td>
                        <td>
                            <input type="text" name="sets_data[<?= $s['set_no'] ?>][topic_name]" 
                                   value="<?= htmlspecialchars($s['topic_name'] ?? '') ?>" 
                                   placeholder="Topic..." style="width:100%; border:1px solid #eee; padding:5px;">
                        </td>
                        <td>
                            <input type="datetime-local" name="sets_data[<?= $s['set_no'] ?>][start_time]" 
                                   value="<?= $s['start_time'] ? date('Y-m-d\TH:i', strtotime($s['start_time'])) : '' ?>" class="inline-dt">
                        </td>
                        <td>
                            <input type="datetime-local" name="sets_data[<?= $s['set_no'] ?>][end_time]" 
                                   value="<?= $s['end_time'] ? date('Y-m-d\TH:i', strtotime($s['end_time'])) : '' ?>" class="inline-dt">
                        </td>
                        <td>
                            <input type="datetime-local" name="sets_data[<?= $s['set_no'] ?>][attempt_till]" 
                                   value="<?= $s['attempt_till'] ? date('Y-m-d\TH:i', strtotime($s['attempt_till'])) : '' ?>" class="inline-dt">
                        </td>
                        <td><span class="status-pill"><?= $s['duration_minutes'] ?>m</span></td>
                        <td>
                            <input type="number" step="0.01" min="0" name="sets_data[<?= $s['set_no'] ?>][pass_marks]"
                                   value="<?= $s['pass_marks'] !== null ? htmlspecialchars(rtrim(rtrim($s['pass_marks'], '0'), '.')) : '' ?>" placeholder="--" class="inline-dt">
                        </td>
                        <td>
                            <input type="number" step="1" min="0" name="sets_data[<?= $s['set_no'] ?>][max_attempts]"
                                   value="<?= (int)$s['max_attempts'] ?>" class="inline-dt">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 20px; text-align: right;">
                <button type="submit" name="action" value="update_set_details" class="btn btn-primary">💾 Save All Changes</button>
            </div>
        </form>
    <?php endif; ?>
</div>

</body>
</html>