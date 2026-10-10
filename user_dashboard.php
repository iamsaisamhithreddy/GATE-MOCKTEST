<?php
session_start();
include 'db.php';

// Load dompdf (Ensure this path matches your server structure)
require_once 'dompdf/autoload.inc.php'; 
use Dompdf\Dompdf;
use Dompdf\Options;
require_once __DIR__ . '/modules/coding.php';

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

/* ==========================================================
   HELPER: CONVERT GOOGLE DRIVE LINKS TO EMBEDDABLE IMAGES
   ========================================================== */
function getEmbeddableImageUrl($url) {
    if (empty($url)) return '';
    if (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $file_id = $matches[1];
        return "https://drive.google.com/thumbnail?id=" . $file_id . "&sz=s1000";
    }
    return $url; 
}

/* ==========================================================
   EXPORT TEST RESULT AS STANDALONE HTML (WITH NAT RANGE CHECK)
   ========================================================== */
if (isset($_POST['export_responses'])) {
    $attempt_id = $_POST['attempt_id'];
    
    // 1. Get attempt metadata
    $meta_query = "SELECT u.name, s.subject_name, t.subject_id, t.set_no, t.start_time, t.score, t.total_marks, t.time_taken_seconds, t.attempted_questions 
                   FROM test_attempts t 
                   JOIN users u ON t.user_id = u.user_id 
                   JOIN subjects s ON t.subject_id = s.subject_id 
                   WHERE t.attempt_id = '$attempt_id'";
    $meta = $conn->query($meta_query)->fetch_assoc();

    $subject_id = $meta['subject_id'];
    $set_no = $meta['set_no'];

    // 2. Fetch ALL questions for this Set
    $questions_query = "SELECT * FROM questions WHERE subject_id = '$subject_id' AND set_no = '$set_no' ORDER BY id ASC";
    $questions_result = $conn->query($questions_query);

    // 3. Generate Repeating Watermark (Base64 Encoded SVG for robust rendering)
    $watermark_text = "CS26S31528318"; // You can change this to be dynamic (e.g., $meta['name'] or attempt_id)
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><text x="50%" y="50%" transform="rotate(-30 200 150)" fill="#e2e8f0" font-family="Helvetica, Arial, sans-serif" font-size="28" font-weight="bold" text-anchor="middle">'.$watermark_text.'</text></svg>';
    $watermark_b64 = base64_encode($svg);

    // 4. Build HTML report
    $html = "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Result - {$meta['name']}</title><style>
                body { 
                    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; 
                    margin: 0; 
                    padding: 10px; 
                    font-size: 14px; 
                    color: #333; 
                    background-image: url('data:image/svg+xml;base64,{$watermark_b64}');
                    background-repeat: repeat;
                }
                .report-header { text-align: center; margin-bottom: 25px; padding-bottom: 10px; border-bottom: 1px solid #ddd; background-color: rgba(255, 255, 255, 0.8); }
                .report-header h1 { margin: 0 0 5px 0; font-size: 20px; color: #111; }
                .report-header p { margin: 0; font-size: 14px; color: #555; }
                .section-header { color: #888; font-weight: bold; font-size: 14px; margin-bottom: 5px; margin-top: 15px; }
                .q-box { border: 2px solid #aaa; padding: 15px; margin-bottom: 25px; page-break-inside: avoid; background-color: transparent; }
                .q-table { width: 100%; border-collapse: collapse; }
                .q-num { width: 35px; vertical-align: top; font-weight: bold; font-size: 15px; }
                .q-content { vertical-align: top; }
                .q-content img { max-width: 100%; height: auto; display: block; margin-bottom: 10px; }
                .opt-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                .opt-table td { vertical-align: top; padding-bottom: 15px; }
                .opt-img { max-width: 100%; height: auto; display: block; }
                .meta-table { border: 2px solid #999; border-radius: 8px; padding: 12px; width: 320px; font-size: 13px; margin-top: 20px; margin-bottom: 5px; background-color: transparent; }
                .meta-table td { padding: 4px 5px; }
                .meta-label { text-align: right; color: #333; width: 45%; }
                .meta-value { text-align: left; font-weight: bold; padding-left: 8px; width: 55%; color:#111; }
            </style></head><body>
            <div class='report-header'>
                <h1>Mock Result: {$meta['subject_name']} (Set {$set_no})</h1>
                <p><strong>Candidate:</strong> {$meta['name']} | <strong>Score:</strong> {$meta['score']} / {$meta['total_marks']}</p>
            </div>";

    $q_num = 1;
    while ($q_row = $questions_result->fetch_assoc()) {
        $q_id = $q_row['id'];
        $q_type = isset($q_row['q_type']) ? $q_row['q_type'] : 'MCQ';
        
        // Handle Marks setup (Uses defaults if columns don't exist in your schema)
        $marks_alloc = isset($q_row['marks']) ? floatval($q_row['marks']) : 1.0;
        $neg_alloc = isset($q_row['negative_marks']) ? floatval($q_row['negative_marks']) : round($marks_alloc / 3, 2);
        
        // Map user answers
        $ans_query = "SELECT selected_answer, is_correct FROM attempt_answers WHERE attempt_id = '$attempt_id' AND question_id = '$q_id'";
        $ans_res = $conn->query($ans_query);
        $user_ans = $ans_res->fetch_assoc();

        // Coding questions: problem, code, time spent and a tick / cross per test case
        if ($q_type === 'CODE') {
            $html .= coding_report_html($conn, $q_row, $q_num, $meta['subject_name'], $user_ans['selected_answer'] ?? null);
            $q_num++;
            continue;
        }

        $is_correct = false;

        if (!$user_ans || empty($user_ans['selected_answer']) || $user_ans['selected_answer'] == 'null') {
            $statusText = 'Not Answered';
            $selected = '--';
            $marks_obtained = '0';
        } else {
            $selected = $user_ans['selected_answer'];
            $statusText = 'Answered';
            
            // NAT RANGE CHECK
            if ($q_type === 'NAT') {
                $decoded = json_decode($selected, true);
                $user_val = is_array($decoded) ? $decoded[0] : $selected;
                
                if (is_numeric($user_val)) {
                    $user_num = floatval($user_val);
                    $min = floatval($q_row['range_min']);
                    $max = floatval($q_row['range_max']);
                    if ($user_num >= $min && $user_num <= $max) {
                        $is_correct = true;
                    }
                }
                $selected = htmlspecialchars($user_val);
            } else {
                $is_correct = ($user_ans['is_correct'] == 1);
            }

            if ($is_correct) {
                $marks_obtained = '+' . $marks_alloc;
            } else {
                $marks_obtained = '-' . $neg_alloc;
            }
        }

        // Clean JSON formatting for Display
        $correct_ans_display = htmlspecialchars($q_row['correct_answer_json']);
        if ($q_type === 'NAT') {
            $min = isset($q_row['range_min']) ? $q_row['range_min'] : null;
            $max = isset($q_row['range_max']) ? $q_row['range_max'] : null;
            if ($min !== null && $max !== null) {
                $correct_ans_display = ($min == $max) ? $min : "$min to $max";
            }
        } else {
            $decoded_corr = json_decode($q_row['correct_answer_json'], true);
            if(is_array($decoded_corr)) $correct_ans_display = htmlspecialchars(implode(", ", $decoded_corr));
            
            $decoded_sel = json_decode($selected, true);
            if(is_array($decoded_sel)) $selected = htmlspecialchars(implode(", ", $decoded_sel));
        }

        $html .= "<div class='section-header'>Section : {$meta['subject_name']}</div>
                  <div class='q-box'>
                    <table class='q-table'>
                        <tr>
                            <td class='q-num'>Q.{$q_num}</td>
                            <td class='q-content'>";
        
        $q_image_url = getEmbeddableImageUrl($q_row['q_text_url']);
        if(!empty($q_image_url)) {
            $html .= "<img src='{$q_image_url}' alt='Question Image'>";
        }
        $html .= "</td></tr></table>";

        // Options section mirroring exactly how it's presented in the image
        $options_list = json_decode($q_row['options_json'], true);
        if ($q_type !== 'NAT' && !empty($options_list)) {
            $html .= "<table class='opt-table'>";
            foreach ($options_list as $index => $opt) {
                $opt_letter = isset($opt['text']) ? htmlspecialchars($opt['text']) : chr(65 + $index);
                $prefix = ($index == 0) ? "<span style='font-weight:bold;'>Options </span>" : "";
                
                $html .= "<tr><td style='width: 90px; font-size: 15px;'>{$prefix}{$opt_letter}.</td><td class='q-content'>";
                
                $opt_image_url = getEmbeddableImageUrl($opt['image']);
                if (!empty($opt_image_url)) {
                    $html .= "<img src='{$opt_image_url}' class='opt-img' alt='Option Image'>";
                }
                $html .= "</td></tr>";
            }
            $html .= "</table>";
        }

        $color_mark = $is_correct ? '#16a34a' : ($statusText == 'Answered' ? '#dc2626' : '#111');

        // Floating Info Box Table structure mimicking the image completely
        $html .= "<table class='meta-table' align='right'>
                    <tr><td class='meta-label'>Question Type :</td><td class='meta-value'>{$q_type}</td></tr>
                    <tr><td class='meta-label'>Question ID :</td><td class='meta-value'>{$q_id}</td></tr>
                    <tr><td class='meta-label'>Status :</td><td class='meta-value'>{$statusText}</td></tr>
                    <tr><td class='meta-label'>Chosen Option :</td><td class='meta-value'>{$selected}</td></tr>
                    <tr><td class='meta-label'>Correct Option :</td><td class='meta-value' style='color:#16a34a;'>{$correct_ans_display}</td></tr>
                    <tr><td class='meta-label'>Marks :</td><td class='meta-value' style='color:{$color_mark};'>{$marks_obtained}</td></tr>
                  </table><div style='clear: both;'></div>";

        $html .= "</div>";
        $q_num++;
    }

    $html .= "<center style='margin-top:20px; color:#aaa; font-size:12px; background-color: rgba(255, 255, 255, 0.8); padding: 5px;'>Generated by GATE Portal</center></body></html>";

    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="Mock_Result_'.$meta['name'].'_Attempt_'.$attempt_id.'.html"');
    echo $html;
    exit();
}

/* ==========================
   FETCH USER PROFILE
========================== */
$user_info_query = $conn->query("SELECT name FROM users WHERE user_id='$user_id'");
$user_info = $user_info_query->fetch_assoc();
$display_name = $user_info['name'] ?? 'Student';

/* ==========================
   HANDLE PASSWORD UPDATE
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $old_password = $conn->real_escape_string($_POST['old_password']);
    $new_password = $conn->real_escape_string($_POST['new_password']);

    $user_query = $conn->query("SELECT password FROM users WHERE user_id='$user_id'");
    $user_data = $user_query->fetch_assoc();
    $stored_password = $user_data['password'];

    if ($old_password === $stored_password) {
        $conn->query("UPDATE users SET password='$new_password' WHERE user_id='$user_id'");
        $success_msg = "Password updated successfully!";
    } else {
        $error_msg = "Old password is incorrect!";
    }
}

/* ======================
   FETCH STUDY LOG STATS
====================== */
$study_query = "SELECT COUNT(*) as total_studied FROM daily_study_logs WHERE user_id = '$user_id' AND status = 'studied'";
$study_result = $conn->query($study_query);
$study_data = $study_result->fetch_assoc();
$days_studied = $study_data['total_studied'] ?? 0;

$total_logged_query = "SELECT COUNT(DISTINCT study_date) as total_days FROM daily_study_logs WHERE user_id = '$user_id'";
$total_logged_result = $conn->query($total_logged_query);
$total_logged_data = $total_logged_result->fetch_assoc();
$total_tracked_days = $total_logged_data['total_days'] ?? 0;

$study_percentage = ($total_tracked_days > 0) ? round(($days_studied / $total_tracked_days) * 100) : 0;

$log_details_query = $conn->query("SELECT study_date, status, subject_name FROM daily_study_logs WHERE user_id = '$user_id' ORDER BY study_date DESC");
$study_logs = [];
while($row = $log_details_query->fetch_assoc()){
    $study_logs[] = $row;
}

/* ======================
   FETCH OVERALL STATS
====================== */
$stats_query = "SELECT COUNT(*) AS total_tests, SUM(correct_answers) AS total_correct, SUM(attempted_questions) AS total_attempted, AVG(score) AS avg_score, AVG(time_taken_seconds) AS avg_time FROM test_attempts WHERE user_id = '$user_id'";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
$total_tests = $stats['total_tests'] ?? 0;
$total_correct = $stats['total_correct'] ?? 0;
$total_attempted = $stats['total_attempted'] ?? 0;
$total_wrong = $total_attempted - $total_correct; 
$avg_score = round($stats['avg_score'] ?? 0, 2);
$avg_time_formatted = gmdate("H:i:s", round($stats['avg_time'] ?? 0));
$accuracy = ($total_attempted > 0) ? round(($total_correct / $total_attempted) * 100, 2) : 0;

/* ==============================
   FETCH SUBJECT-WISE ANALYSIS
============================== */
$subject_stats_query = "SELECT s.subject_name, COUNT(t.attempt_id) as total_attempts, AVG(t.score) as avg_subject_score, AVG((t.correct_answers / t.attempted_questions) * 100) as avg_subject_accuracy FROM test_attempts t JOIN subjects s ON t.subject_id = s.subject_id WHERE t.user_id = '$user_id' GROUP BY t.subject_id ORDER BY avg_subject_accuracy DESC";
$subject_stats_result = $conn->query($subject_stats_query);
$chart_labels = []; $chart_accuracy_data = []; $subject_stats_rows = [];
if ($subject_stats_result) {
    while ($row = $subject_stats_result->fetch_assoc()) {
        $subject_stats_rows[] = $row;
        $chart_labels[] = $row['subject_name'];
        $chart_accuracy_data[] = round($row['avg_subject_accuracy'] ?? 0, 1);
    }
}

// Added t.attempt_id to correctly pull the download form
$attempts_query = "SELECT t.attempt_id, s.subject_name, t.subject_id, t.set_no, t.score, t.total_marks, t.attempted_questions, t.correct_answers, t.time_taken_seconds, t.start_time FROM test_attempts t JOIN subjects s ON t.subject_id = s.subject_id WHERE t.user_id = '$user_id' ORDER BY t.attempt_id DESC LIMIT 5";
$attempts_result = $conn->query($attempts_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard</title>
    <script src="<?= CDN_TAILWIND ?>"></script>
    <script src="<?= CDN_CHARTJS ?>"></script>
    <style>
        .modal { transition: opacity 0.25s ease; }
        body.modal-active { overflow: hidden; }
    </style>
</head>
<body class="bg-gray-100 p-4 md:p-8">

<div class="max-w-6xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">👋 Welcome, <?= htmlspecialchars($display_name) ?>!</h1>
            <a href="index.php" class="inline-block mt-2 bg-green-600 text-white px-6 py-2 rounded-lg font-semibold hover:bg-green-700 transition shadow-md">🚀 VIEW EXAM SCHEDULE</a>
        </div>
        <a href="?logout=true" class="bg-red-500 text-white px-5 py-2 rounded-lg font-semibold hover:bg-red-600 transition">🚪 Logout</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white p-5 rounded-xl shadow-sm border-t-4 border-blue-500">
                <p class="text-gray-500 text-xs font-bold uppercase">📝 Total Tests</p>
                <h2 class="text-3xl font-black text-gray-800 mt-1"><?= $total_tests ?></h2>
            </div>
            
            <div onclick="toggleModal()" class="bg-white p-5 rounded-xl shadow-sm border-t-4 border-emerald-500 cursor-pointer hover:bg-emerald-50 transition-colors group relative">
                <div class="absolute top-3 right-3 text-emerald-300 group-hover:text-emerald-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <p class="text-gray-500 text-xs font-bold uppercase">🔥 Study Consistency</p>
                <h2 class="text-3xl font-black text-emerald-600 mt-1"><?= $days_studied ?> <span class="text-gray-400 text-xl">/ <?= $total_tracked_days ?> Days</span></h2>
                <div class="w-full bg-gray-200 rounded-full h-2 mt-3">
                    <div class="bg-emerald-500 h-2 rounded-full" style="width: <?= $study_percentage ?>%"></div>
                </div>
                <p class="text-[10px] text-gray-400 mt-1 font-bold">Click to see what you studied • <?= $study_percentage ?>%</p>
            </div>

            <div class="bg-white p-5 rounded-xl shadow-sm border-t-4 border-purple-500">
                <p class="text-gray-500 text-xs font-bold uppercase">📊 Avg Score</p>
                <h2 class="text-3xl font-black text-purple-600 mt-1"><?= $avg_score ?></h2>
            </div>
            <div class="bg-white p-5 rounded-xl shadow-sm border-t-4 border-gray-700">
                <p class="text-gray-500 text-xs font-bold uppercase">⏱ Avg Time</p>
                <h2 class="text-3xl font-black text-gray-700 mt-1 text-2xl"><?= $avg_time_formatted ?></h2>
            </div>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-sm border flex flex-col items-center justify-center">
            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Overall Accuracy</h3>
            <div class="relative w-32 h-32">
                <canvas id="accuracyChart"></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-xl font-black <?= ($accuracy >= 70) ? 'text-emerald-600' : 'text-rose-600' ?>"><?= round($accuracy) ?>%</span>
                </div>
            </div>
        </div>
    </div>

    <div id="studyModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-60" onclick="toggleModal()"></div>
        <div class="modal-container bg-white w-11/12 md:max-w-lg mx-auto rounded-2xl shadow-2xl z-50 overflow-y-auto max-h-[85vh]">
            <div class="modal-content py-6 text-left px-8">
                <div class="flex justify-between items-center pb-4 border-b">
                    <p class="text-2xl font-black text-gray-800 tracking-tight">📚 Detailed Study Log</p>
                    <div class="modal-close cursor-pointer p-2 hover:bg-gray-100 rounded-full" onclick="toggleModal()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </div>
                </div>
                <div class="mt-6 space-y-4">
                    <?php if(!empty($study_logs)): ?>
                        <?php foreach($study_logs as $log): ?>
                            <div class="flex flex-col p-4 rounded-xl border <?= $log['status'] == 'studied' ? 'bg-emerald-50 border-emerald-100' : 'bg-rose-50 border-rose-100' ?>">
                                <div class="flex justify-between items-start">
                                    <span class="text-sm font-bold text-gray-700"><?= date('l, M d, Y', strtotime($log['study_date'])) ?></span>
                                    <span class="px-2 py-1 rounded text-[10px] font-black uppercase tracking-wider <?= $log['status'] == 'studied' ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white' ?>">
                                        <?= $log['status'] == 'studied' ? 'COMPLETED' : 'MISSED' ?>
                                    </span>
                                </div>
                                <div class="mt-2">
                                    <?php if($log['status'] == 'studied'): ?>
                                        <p class="text-xs text-gray-500 uppercase font-bold tracking-widest">Subjects Covered:</p>
                                        <p class="text-sm font-semibold text-emerald-800"><?= htmlspecialchars($log['subject_name'] ?? 'Not specified') ?></p>
                                    <?php else: ?>
                                        <p class="text-sm italic text-rose-400">No study data recorded for this day.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-12"><p class="text-gray-400 font-medium">No logs found in your account.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white p-5 rounded-xl shadow-sm border mb-8">
        <h2 class="text-md font-bold mb-3">🔐 Account Security</h2>
        <form method="POST" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[150px]"><label class="text-[10px] font-bold text-gray-400 uppercase">Old Password</label><input type="password" name="old_password" required class="w-full border p-2 rounded-lg text-sm bg-gray-50"></div>
            <div class="flex-1 min-w-[150px]"><label class="text-[10px] font-bold text-gray-400 uppercase">New Password</label><input type="password" name="new_password" required class="w-full border p-2 rounded-lg text-sm bg-gray-50"></div>
            <button type="submit" name="change_password" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold text-sm hover:bg-blue-700 transition">Update Password</button>
        </form>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mb-8">
        <div class="lg:col-span-3 bg-white p-6 rounded-xl shadow-sm border">
            <h2 class="text-lg font-bold mb-4 text-gray-800 flex items-center"><span class="mr-2">📈</span> Accuracy by Subject</h2>
            <div style="height: 250px;"><canvas id="subjectBarChart"></canvas></div>
        </div>
        <div class="lg:col-span-2 bg-white p-6 rounded-xl shadow-sm border">
            <h2 class="text-lg font-bold mb-4 text-gray-800 flex items-center"><span class="mr-2">🎯</span> Subject Performance</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead><tr class="text-gray-400 border-b"><th class="pb-2">Subject</th><th class="pb-2 text-center">Attempts</th><th class="pb-2 text-right">Avg. Marks</th></tr></thead>
                    <tbody class="divide-y">
                        <?php foreach ($subject_stats_rows as $sub): ?>
                        <tr class="hover:bg-gray-50"><td class="py-3 font-semibold text-gray-700"><?= htmlspecialchars($sub['subject_name']) ?></td><td class="py-3 text-center"><?= $sub['total_attempts'] ?></td><td class="py-3 text-right font-bold text-indigo-600"><?= round($sub['avg_subject_score'], 2) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="bg-white shadow-sm rounded-xl overflow-hidden border">
        <div class="p-5 border-b text-gray-700"><h2 class="text-lg font-bold italic">📋 Recent Attempts History</h2></div>
        <table class="w-full text-left">
            <thead class="bg-gray-50 text-gray-500 text-[10px] uppercase font-bold"><tr><th class="p-4">Subject</th><th class="p-4">Set</th><th class="p-4 text-center">Score</th><th class="p-4 text-center">Accuracy</th><th class="p-4 text-center">Date</th><th class="p-4 text-center">Action</th></tr></thead>
            <tbody class="divide-y">
                <?php while ($row = $attempts_result->fetch_assoc()):
                    $row_acc = ($row['attempted_questions'] > 0) ? round(($row['correct_answers'] / $row['attempted_questions']) * 100, 1) : 0;
                    $url = "exam.php?set_no=" . urlencode($row['set_no'] . '|' . $row['subject_id']);
                ?>
                <tr class="hover:bg-gray-50 transition">
                    <td class="p-4 font-semibold text-gray-700"><?= htmlspecialchars($row['subject_name']) ?></td>
                    <td class="p-4 text-xs">Set <?= $row['set_no'] ?></td>
                    <td class="p-4 text-center font-bold"><?= $row['score'] ?> / <?= $row['total_marks'] ?></td>
                    <td class="p-4 text-center"><span class="<?= ($row_acc >= 70) ? 'text-emerald-600' : 'text-rose-600' ?> font-bold text-xs"><?= $row_acc ?>%</span></td>
                    <td class="p-4 text-[11px] text-gray-400 text-center"><?= $row['start_time'] ?></td>
                    <td class="p-4 text-center flex justify-center items-center gap-3">
                        <a href="<?= $url ?>" class="text-blue-600 hover:underline text-xs font-bold">🔄 Reattempt</a>
                        <a href="report.php?attempt_id=<?= (int)$row['attempt_id'] ?>" target="_blank" class="text-indigo-600 hover:underline text-xs font-bold">📄 Report</a>
                        <a href="response_sheet.php?attempt_id=<?= (int)$row['attempt_id'] ?>&pdf=1" class="text-rose-600 hover:underline text-xs font-bold">🧾 Response PDF</a>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="attempt_id" value="<?= $row['attempt_id'] ?>">
                            <button type="submit" name="export_responses" class="text-emerald-600 hover:underline text-xs font-bold">📥 Download</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function toggleModal() {
        const modal = document.getElementById('studyModal');
        modal.classList.toggle('opacity-0');
        modal.classList.toggle('pointer-events-none');
        document.body.classList.toggle('modal-active');
    }

    // Charts
    const ctxAcc = document.getElementById('accuracyChart');
    if(ctxAcc) {
        new Chart(ctxAcc, {
            type: 'doughnut',
            data: {
                labels: ['Correct', 'Wrong'],
                datasets: [{
                    data: [<?= $total_correct ?>, <?= $total_wrong ?>],
                    backgroundColor: ['#10b981', '#f43f5e'],
                    borderWidth: 0, borderRadius: 4, spacing: 2
                }]
            },
            options: { cutout: '80%', responsive: true, plugins: { legend: { display: false } } }
        });
    }

    const ctxBar = document.getElementById('subjectBarChart');
    if(ctxBar) {
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{ label: 'Accuracy (%)', data: <?= json_encode($chart_accuracy_data) ?>, backgroundColor: '#3b82f6', borderRadius: 4, barThickness: 25 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + "%" } }, x: { grid: { display: false } } }
            }
        });
    }
</script>
</body>
</html>