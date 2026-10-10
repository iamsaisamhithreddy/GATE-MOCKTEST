<?php

// 1. CONFIGURATION, CONNECTION, AND SESSION
include 'config.php';
session_start(); 

if (!isset($_SESSION['user_id'])) {
    header("Location: exam_login.php");
    exit(); 
}

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database Connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/modules/sections.php';
require_once __DIR__ . '/modules/coding.php';
require_once __DIR__ . '/modules/assessment.php';
ensure_sections_schema($conn);
ensure_coding_schema($conn);
ensure_assessment_schema($conn);
$coding_filter = coding_filter_sql($conn);   // hides coding questions unless the admin switched them on

// Creating User session
$user_id = $_SESSION['user_id'];
$user_info_query = $conn->query("SELECT name, REG_ID FROM users WHERE user_id='$user_id'");
$user_info = $user_info_query->fetch_assoc();
$display_name = $user_info['name'] ?? 'Student';
$display_regid = $user_info['REG_ID'] ?? $display_name;

// Fetch image directly from session (Processed at login time!)
$display_image = $_SESSION['display_image'] ?? 'user.png';

$selected = $_GET['set_no'] ?? null;

$selected_set = null;
$selected_subject = null;
$subject_name = 'Exam';
$selected_set_int = 0;

if ($selected && strpos($selected, '|') !== false) {
    [$selected_set, $selected_subject] = array_map('intval', explode('|', $selected));
    $selected_set_int = (int)$selected_set;
}

// Initializing questions
$questions_json = '[]';
$passages_json = '{}';
$total_questions = 0;
$available_sets = [];
$questions = []; 
$duration_minutes = null;
$test_duration_seconds = null;

if ($selected_set) {
    // Check if the selected set is active and get its details
    $time_stmt = $conn->prepare("
        SELECT s.duration_minutes, s.start_time, s.attempt_till, subj.subject_name,
               COALESCE(s.set_name, CONCAT('Set ', s.set_no)) AS set_label
        FROM set_time s
        JOIN subjects subj ON s.subject_id = subj.subject_id
        WHERE s.set_no = ?
          AND s.subject_id = ?
          AND s.start_time IS NOT NULL
          AND s.attempt_till IS NOT NULL
          AND NOW() BETWEEN s.start_time AND s.attempt_till
        LIMIT 1
    ");

    if ($time_stmt === false) {
        $conn->close();
        die("Error preparing time query: " . $conn->error);
    }

    $time_stmt->bind_param("ii", $selected_set_int, $selected_subject);
    $time_stmt->execute();
    $time_stmt->bind_result($db_duration_minutes, $db_start_time, $db_attempt_till, $db_subject_name, $db_set_label);

    if ($time_stmt->fetch() && (int)$db_duration_minutes > 0) {
        $duration_minutes = (int)$db_duration_minutes;
        $test_duration_seconds = $duration_minutes * 60;
        $set_label = $db_set_label ?: ('Set ' . $selected_set_int);
        $subject_name = $db_subject_name ?: 'Unknown Subject';
    } else {
        $time_stmt->close();
        
        $sql_sync = $conn->prepare("SELECT start_time, (UNIX_TIMESTAMP(start_time) - UNIX_TIMESTAMP(NOW())) as seconds_diff FROM set_time WHERE set_no = ? AND subject_id = ? LIMIT 1");
        $sql_sync->bind_param("ii", $selected_set_int, $selected_subject);
        $sql_sync->execute();
        $sql_sync->bind_result($raw_start_time, $seconds_remaining);
        $sql_sync->fetch();
        $sql_sync->close();
        $conn->close();

        $is_future = ($seconds_remaining > 0);
        $display_date = ($raw_start_time) ? (new DateTime($raw_start_time))->modify('+5 hours 30 minutes')->format('d M Y, h:i A') . " IST" : "Not Scheduled";
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset='utf-8'>
            <title>Set Not Active</title>
            <script src="<?= CDN_TAILWIND ?>"></script>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
                body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
                .main-card { background: white; border-radius: 24px; box-shadow: 0 20px 50px rgba(0,0,0,0.08); width: 100%; max-width: 450px; padding: 40px; }
                .timer-line { font-variant-numeric: tabular-nums; letter-spacing: -0.02em; }
            </style>
        </head>
        <body class='flex items-center justify-center min-h-screen p-6'>
            <div class='main-card text-center border border-slate-100'>
                <div class='mb-6'>
                    <h1 class='text-2xl font-bold text-slate-900'>Set <?= $selected_set_int ?> Access Restricted</h1>
                    <p class='text-slate-500 text-sm mt-1'>This session has not started yet.</p>
                </div>
                <div class='py-4 border-y border-slate-100 my-8'>
                    <p class='text-[10px] uppercase font-bold tracking-[0.2em] text-slate-400 mb-1'>Official Schedule</p>
                    <p class='text-slate-800 font-semibold'><?= $display_date ?></p>
                </div>
                <?php if ($is_future): ?>
                    <div class='mb-8'>
                        <div id='countdown' class='timer-line text-3xl font-extrabold text-blue-600 flex justify-center items-baseline gap-1'>
                            <span id='days'>00</span><span class='text-sm font-bold text-slate-400 mr-2'>d</span>
                            <span id='hours'>00</span><span class='text-sm font-bold text-slate-400 mr-2'>h</span>
                            <span id='minutes'>00</span><span class='text-sm font-bold text-slate-400 mr-2'>m</span>
                            <span id='seconds'>00</span><span class='text-sm font-bold text-slate-400'>s</span>
                        </div>
                    </div>
                    <script>
                        let totalSecs = <?= (int)$seconds_remaining ?>;
                        function update() {
                            if (totalSecs <= 0) { window.location.reload(); return; }
                            document.getElementById('days').innerText = Math.floor(totalSecs / 86400).toString().padStart(2, '0');
                            document.getElementById('hours').innerText = Math.floor((totalSecs % 86400) / 3600).toString().padStart(2, '0');
                            document.getElementById('minutes').innerText = Math.floor((totalSecs % 3600) / 60).toString().padStart(2, '0');
                            document.getElementById('seconds').innerText = Math.floor(totalSecs % 60).toString().padStart(2, '0');
                            totalSecs--;
                        }
                        setInterval(update, 1000); update();
                    </script>
                <?php else: ?>
                    <p class='text-red-500 text-sm font-medium mb-8 bg-red-50 py-2 rounded-lg'>Set is currently unavailable or expired.</p>
                <?php endif; ?>
                <a href='exam.php' class='flex items-center justify-center w-full py-4 bg-slate-900 hover:bg-black text-white font-bold rounded-xl transition-all shadow-lg hover:shadow-xl active:scale-[0.98]'>Return to Dashboard</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    $time_stmt->close();

    // Attempt limit (set per test in tpdf.php; 0 = unlimited)
    $limit_info = assessment_set_info($conn, $selected_set_int, (int)$selected_subject);
    $max_attempts = $limit_info ? (int)$limit_info['max_attempts'] : 0;
    if ($max_attempts > 0 && assessment_attempt_count($conn, (int)$user_id, $selected_set_int, (int)$selected_subject) >= $max_attempts) {
        $conn->close();
        $back = 'assessment.php?set_no=' . urlencode($selected_set_int . '|' . (int)$selected_subject);
        ?>
        <!DOCTYPE html>
        <html lang="en"><head><meta charset="utf-8"><title>No Attempts Left</title></head>
        <body style="font-family:Arial,sans-serif;background:#f5f6fa;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;">
            <div style="background:#fff;padding:40px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.1);text-align:center;max-width:460px;">
                <h2 style="margin-top:0;">No attempts left</h2>
                <p>You have used all <?= $max_attempts ?> attempts of this assessment.</p>
                <a href="<?= htmlspecialchars($back) ?>" style="display:inline-block;margin-top:10px;padding:10px 24px;background:#1b3c9c;color:#fff;border-radius:22px;text-decoration:none;">View My Attempts</a>
            </div>
        </body></html>
        <?php
        exit;
    }

    // Grouped by section (in the admin's section order), passage questions kept together
    // at the end of their section, random order otherwise
    $sql = "SELECT q.id, q.q_type, q.q_text_url, q.options_json, q.correct_answer_json, q.explanation, q.marks, q.range_min, q.range_max,
                   sec.section_name, q.passage_id, p.passage_text, p.passage_image_url
            FROM questions q
            LEFT JOIN sections sec ON sec.section_id = q.section_id
            LEFT JOIN passages p ON p.passage_id = q.passage_id
            WHERE q.set_no = ? AND q.subject_id = ?$coding_filter
            ORDER BY COALESCE(sec.sort_order, 2147483647), q.section_id, (p.passage_id IS NOT NULL), p.passage_id, RAND()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $selected_set_int, $selected_subject);
    $stmt->execute();
    $stmt->bind_result($id, $q_type, $q_text_url, $options_json, $correct_answer_json, $explanation, $marks, $range_min, $range_max,
                       $section_name, $passage_id, $passage_text, $passage_image_url);

    $passages = [];
    while ($stmt->fetch()) {
        if ($passage_id) {
            $passages[(int)$passage_id] = ['text' => (string)$passage_text, 'image' => (string)$passage_image_url];
        }
        $questions[] = [
            'id'            => (int)$id,
            'section'       => $section_name ?: $subject_name,
            'passageId'     => $passage_id ? (int)$passage_id : null,
            'type'          => $q_type,
            'imageText'     => $q_text_url,
            'options'       => json_decode($options_json, true) ?? [],
            'correctAnswer' => $q_type === 'NAT' ? [(float)$range_min, (float)$range_max] : (json_decode($correct_answer_json, true) ?? []),
            'marks'         => (float)$marks,
            'explanation'   => $explanation,
            'range'         => $q_type === 'NAT' ? [(float)$range_min, (float)$range_max] : null,
        ];
    }
    $stmt->close();

    // Coding questions: statement, languages, starter code and test cases
    $code_ids = array_column(array_filter($questions, fn($q) => $q['type'] === 'CODE'), 'id');
    $problems = fetch_coding_problems($conn, $code_ids);
    foreach ($questions as $i => $q) {
        if ($q['type'] !== 'CODE') continue;
        if (!isset($problems[$q['id']])) { unset($questions[$i]); continue; }
        $questions[$i]['coding'] = coding_exam_payload($problems[$q['id']]);
        $questions[$i]['options'] = [];
        $questions[$i]['correctAnswer'] = [];
    }
    $questions = array_values($questions);
    $conn->close();

    $questions_json = json_encode($questions, JSON_INVALID_UTF8_IGNORE);
    $passages_json = json_encode((object)$passages, JSON_INVALID_UTF8_IGNORE);
    $total_questions = count($questions);
    
    if ($total_questions === 0) die("Error: No questions found.");

} else {
    $sql_sets = "SELECT DISTINCT q.set_no, s.subject_id, subj.subject_name, s.duration_minutes
                 FROM questions q
                 INNER JOIN set_time s ON q.set_no = s.set_no AND q.subject_id = s.subject_id
                 INNER JOIN subjects subj ON s.subject_id = subj.subject_id
                 WHERE s.start_time IS NOT NULL AND s.attempt_till IS NOT NULL
                   AND NOW() BETWEEN s.start_time AND s.attempt_till$coding_filter
                 ORDER BY subj.subject_name, q.set_no ASC";
    $result_sets = $conn->query($sql_sets);
    if ($result_sets && $result_sets->num_rows > 0) {
        while ($row_set = $result_sets->fetch_assoc()) {
            $available_sets[] = [
                'set_no'       => $row_set['set_no'],
                'subject_id'   => $row_set['subject_id'],
                'subject_name' => $row_set['subject_name'],
            ];
        }
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?= htmlspecialchars(EXAM_NAME) ?> MOCK TESTS</title>
    <script src="<?= CDN_TAILWIND ?>"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <script src="<?= CDN_JQUERY ?>"></script>
    <script src="<?= CDN_JQUERY_UI ?>"></script>
    <link rel="stylesheet" href="modules/exam_style.css">
    <link rel="stylesheet" href="modules/calculator.css">
    <link rel="stylesheet" href="modules/exam_popups.css?v=4">
    <link rel="stylesheet" href="modules/exam_coding.css?v=1">
    <link rel="stylesheet" href="modules/exam_submit.css?v=1">

    <style>
        .watermark-container::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: url("data:image/svg+xml,%3Csvg width='350' height='200' xmlns='http://www.w3.org/2000/svg'%3E%3Ctext x='50%25' y='50%25' font-size='24' fill='rgba(0,0,0,0.04)' font-family='Arial' font-weight='bold' text-anchor='middle' transform='rotate(-35, 175, 100)'%3E<?= urlencode($subject_name) ?> <?= urlencode($display_regid) ?>%3C/text%3E%3C/svg%3E");
            background-repeat: repeat;
            z-index: 1;
            pointer-events: none;
        }
        .modal-icon.shortcut { width: 60px; height: 60px; font-size: 24px; border: none; }
        .modal-icon.shortcut::before { line-height: 60px; }
        .modal-icon.answered-marked-dot::after { width: 16px; height: 16px; bottom: 2px; right: 2px; border-width: 2px; }
    </style>
    
    <script>
        window.EXAM_CONFIG = {
            questions:       <?php echo $questions_json; ?>,
            passages:        <?php echo $passages_json; ?>,
            totalQuestions:  <?php echo $total_questions; ?>,
            durationSeconds: <?php echo (int)$test_duration_seconds; ?>,
            durationMinutes: <?php echo (int)$duration_minutes; ?>,
            subjectId:       <?php echo (int)$selected_subject; ?>,
            setNo:           <?php echo (int)$selected_set_int; ?>,
            isSetSelected:   <?php echo $selected_set ? 'true' : 'false'; ?>,
            sectionName:     <?php echo json_encode($subject_name); ?>
        };
    </script>
</head>
<body class="min-h-screen">

    <?php if ($selected_set): ?>

        <div id="main-app" class="flex flex-col h-screen fixed inset-0 hidden">

            <!-- ═══════════════════════════════════════════
                 HEADER — GATE banner with logos on sides
            ════════════════════════════════════════════ -->
            <header class="border-b border-gray-300 shadow-sm z-10 flex-shrink-0">

                <!-- Top strip (as on the real GATE exam page): exam logo | exam name + organising institute | institute logo -->
                <div class="flex items-center justify-between bg-white px-4" style="height:64px;">
                    <img src="<?= EXAM_LOGO_URL ?>" alt="<?= htmlspecialchars(EXAM_NAME) ?> Logo" style="height:48px; width:auto; max-width:120px; object-fit:contain;">
                    <div class="text-center leading-tight px-4 whitespace-nowrap">
                        <div style="font-size:19px; font-weight:700; color:#3b2f7d; letter-spacing:0.3px;"><?= htmlspecialchars(strtoupper(EXAM_FULL_NAME)) ?></div>
                        <div style="font-size:12px; font-weight:700; color:#c2410c;">Organizing Institute : <?= htmlspecialchars(strtoupper(ORGANIZING_INSTITUTE)) ?></div>
                    </div>
                    <img src="<?= INSTITUTE_LOGO_URL ?>" alt="<?= htmlspecialchars(INSTITUTE_SHORT_NAME . ' Logo') ?>" style="height:48px; width:auto; max-width:120px; object-fit:contain;">
                </div>

                <!-- Sub-header: subject name + calculator -->
                <div class="bg-[#424242] text-[#FFFF00] px-4 py-1 flex justify-between items-center text-sm font-semibold">
                    <div class="flex items-center gap-2">
                        <span><?= htmlspecialchars($subject_name) ?> Mock</span>
                    </div>
                    <div class="flex items-center gap-6 text-white font-normal">
                        <div id="xp-instr-link" class="xp-link">
                            <span class="xp-ico info">i</span>
                            <span class="text-xs font-semibold">Instructions</span>
                        </div>
                        <div id="xp-paper-link" class="xp-link">
                            <span class="xp-ico paper">description</span>
                            <span class="text-xs font-semibold">Question Paper</span>
                        </div>
                        <div id="calculatorBtn" class="flex items-center gap-1 cursor-pointer hover:text-blue-200 transition-colors">
                            <i class="material-icons text-sm">calculate</i>
                            <span class="text-xs">Scientific Calculator</span>
                        </div>
                    </div>
                </div>

            </header>
            <!-- /HEADER -->

            <!-- ═══════════════════════════════════════════
                 SECTIONS BAR + TIMER + CANDIDATE INFO
            ════════════════════════════════════════════ -->
            <div class="bg-[#F3F3F3] border-b border-gray-300 flex items-stretch h-[86px] flex-shrink-0">

                <!-- Section tabs -->
                <div class="flex-grow flex flex-col justify-end">
                    <div class="px-4 py-1 text-[10px] font-bold text-gray-600 uppercase tracking-widest">Sections</div>
                    <!-- One tab per section (filled by modules/exam_app.js) -->
                    <div id="section-tabs" class="flex items-center px-4 gap-1"></div>
                </div>

                <!-- Timer + candidate -->
                <div class="flex items-center border-l border-gray-300 bg-white">

                    <!-- Timer -->
                    <div class="px-6 text-right border-r border-gray-300 h-full flex flex-col justify-center min-w-[150px]">
                        <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Time Left:</span>
                        <span id="timer-display" class="text-[22px] font-mono font-extrabold text-gray-800 tracking-tighter leading-none">00:00:00</span>
                    </div>

                    <!-- Candidate photo + name -->
                    <div class="flex items-center gap-3 px-4 h-full bg-white">
                        <!-- Live proctoring camera (filled by modules/face_proctor.js) -->
                        <div id="fp-cam-slot" class="h-[64px] w-[86px] bg-black overflow-hidden shrink-0 shadow-sm"></div>
                        <div class="border border-gray-400 bg-white flex items-center justify-center h-[64px] w-[54px] overflow-hidden p-[2px] shadow-sm shrink-0">
                            <img src="<?= htmlspecialchars($display_image) ?>"
                                 onerror="this.onerror=null; this.src='user.png';"
                                 alt="Candidate Photo"
                                 class="w-full h-full object-cover">
                        </div>
                        <div class="flex flex-col justify-center">
                            <span class="text-[10px] text-gray-500 font-bold uppercase leading-none mb-1">Candidate Name:</span>
                            <span class="text-[15px] font-bold text-[#1F4E79] uppercase leading-tight"><?= htmlspecialchars($display_name) ?></span>
                            <span class="text-[15px] font-bold text-[#1F4E79] uppercase leading-tight"><?= htmlspecialchars($display_regid) ?></span>
                        </div>
                    </div>

                </div>
            </div>
            <!-- /SECTIONS BAR -->

            <!-- ═══════════════════════════════════════════
                 MAIN CONTENT — question + palette
            ════════════════════════════════════════════ -->
            <main class="flex-grow p-4 w-full overflow-y-auto">
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-4 gap-4 h-full">

                    <!-- Question panel (3/4 width) -->
                    <div class="lg:col-span-3 flex flex-col gap-2">

                        <!-- Question meta bar -->
                        <div class="bg-white border border-gray-300 flex justify-between items-center px-4 py-2 text-[13px] shadow-sm">
                            <div class="font-bold text-black">
                                Question Type: <span id="question-type-display" class="font-normal text-black">MCQ</span>
                            </div>
                            <div class="text-gray-700 flex items-center">
                                Marks for correct answer: &nbsp;
                                <span id="marks-display" class="text-green-700 font-bold">0</span>
                                <span class="mx-2 text-gray-400">|</span>
                                Negative Marks: &nbsp;
                                <span id="negative-display" class="text-red-700 font-bold">0</span>
                                <span id="question-count-total" class="hidden"><?php echo $total_questions; ?></span>
                            </div>
                        </div>

                        <!-- Question body -->
                        <div class="bg-white border border-gray-300 shadow-sm min-h-[450px] flex flex-col">
                            <div class="border-b border-gray-200 px-4 py-2">
                                <h2 class="text-[15px] font-bold text-black">
                                    Question No. <span id="question-number">1</span>
                                </h2>
                            </div>
                            <div class="flex flex-grow min-h-0">
                                <!-- Passage pane: shown on the left for passage (comprehension) questions -->
                                <div id="passage-pane" style="display:none" class="w-1/2 border-r-2 border-gray-300 p-6 overflow-y-auto watermark-container">
                                    <div class="relative z-10">
                                        <p class="passage-note font-bold text-black mb-3"><span class="text-red-600">NOTE:</span> After selecting your response to the sub question, you must click <span class="text-red-600">'SAVE &amp; NEXT'</span> to move to the next sub question.</p>
                                        <p class="passage-note font-bold text-black mb-3">Read the passage given below and answer the questions that follow:</p>
                                        <div id="passage-content" class="text-[15px] text-gray-900 leading-relaxed"></div>
                                    </div>
                                </div>
                                <div id="question-pane" class="p-6 overflow-y-auto flex-grow watermark-container">
                                    <div id="question-text" class="text-lg text-gray-900 leading-relaxed mb-6 relative z-10"></div>
                                    <div id="options-container" class="space-y-2 relative z-10"></div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <!-- /Question panel -->

                    <!-- Palette (1/4 width) -->
                    <div class="lg:col-span-1 space-y-4 lg:sticky lg:top-20 max-h-[90vh]">
                        <div class="space-y-4 overflow-y-auto pr-2 max-h-full" style="max-height: calc(100vh - 120px);">
                            <div class="bg-white p-4 rounded-xl shadow-lg border-t-4 border-yellow-500">
                                <h3 class="choose-title text-gray-800 border-b pb-2">Question Palette</h3>
                                <div id="palette-section-name" class="bg-[#287baf] text-white text-sm font-bold px-3 py-1 mb-2 rounded"></div>
                                <div id="question-palette" class="questionMatrix"></div>
                                <div class="mt-4 border-t pt-3 space-y-2 text-sm">
                                    <div class="flex justify-between items-center">
                                        <span class="w-6 h-6 shortcut answered up inline-block mr-2" data-num=""></span>
                                        Answered (<span id="count-answered">0</span>)
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="w-6 h-6 shortcut review inline-block mr-2" data-num=""></span>
                                        Marked for Review (<span id="count-marked">0</span>)
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="w-6 h-6 shortcut unanswered down inline-block mr-2" data-num=""></span>
                                        Not Answered (<span id="count-not-answered">0</span>)
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="w-6 h-6 shortcut unvisited up inline-block mr-2" data-num=""></span>
                                        Not Visited (<span id="count-not-visited">0</span>)
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- /Palette -->

                </div>

                <!-- Scientific Calculator popup -->
                <div id="calculatorPopup">
                    <div id="keyPad_Header">
                        <span>Scientific Calculator</span>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span id="closeCalc" style="cursor:pointer; font-size: 20px;">&times;</span>
                        </div>
                    </div>
                    <div class="calc-display-container">
                        <input type="text" id="calc_history" class="keyPad_TextBox1" readonly>
                        <div style="display: flex; align-items: center; background: #fff;">
                            <input type="text" id="calc_main" class="keyPad_TextBox" value="0" readonly>
                            <span id="mem_sym" class="mem-indicator">M</span>
                        </div>
                    </div>
                    <div class="left_sec">
                        <div class="calc_row">
                            <a href="#" class="op-binary">mod</a>
                            <div class="deg-rad-toggle">
                                <input type="radio" name="mode" value="deg" checked>Deg
                                <input type="radio" name="mode" value="rad">Rad
                            </div>
                            <a href="#" class="mem-func">MC</a>
                            <a href="#" class="mem-func">MR</a>
                            <a href="#" class="mem-func">MS</a>
                            <a href="#" class="mem-func">M+</a>
                            <a href="#" class="mem-func">M-</a>
                        </div>
                        <div class="calc_row">
                            <a href="#" class="op-unary">sinh</a><a href="#" class="op-unary">cosh</a><a href="#" class="op-unary">tanh</a>
                            <a href="#" class="op-binary">Exp</a><a href="#">(</a><a href="#">)</a>
                            <a href="#" id="btn_back">←</a><a href="#" id="btn_clear">C</a>
                            <a href="#" id="btn_inv">+/-</a><a href="#" class="op-unary">√</a>
                        </div>
                        <div class="calc_row">
                            <a href="#" class="op-unary">sinh⁻¹</a><a href="#" class="op-unary">cosh⁻¹</a><a href="#" class="op-unary">tanh⁻¹</a>
                            <a href="#" class="op-unary">log₂x</a><a href="#" class="op-unary">ln</a><a href="#" class="op-unary">log</a>
                            <a href="#" class="num">7</a><a href="#" class="num">8</a><a href="#" class="num">9</a><a href="#" class="op-binary">/</a>
                        </div>
                        <div class="calc_row">
                            <a href="#" class="const">π</a><a href="#" class="const">e</a><a href="#" class="op-unary">n!</a>
                            <a href="#" class="op-binary">logᵧx</a><a href="#" class="op-unary">eˣ</a><a href="#" class="op-unary">10ˣ</a>
                            <a href="#" class="num">4</a><a href="#" class="num">5</a><a href="#" class="num">6</a><a href="#" class="op-binary">*</a>
                        </div>
                        <div class="calc_row">
                            <a href="#" class="op-unary">sin</a><a href="#" class="op-unary">cos</a><a href="#" class="op-unary">tan</a>
                            <a href="#" class="op-binary">xʸ</a><a href="#" class="op-unary">x³</a><a href="#" class="op-unary">x²</a>
                            <a href="#" class="num">1</a><a href="#" class="num">2</a><a href="#" class="num">3</a><a href="#" class="op-binary">-</a>
                        </div>
                        <div class="calc_row">
                            <a href="#" class="op-unary">sin⁻¹</a><a href="#" class="op-unary">cos⁻¹</a><a href="#" class="op-unary">tan⁻¹</a>
                            <a href="#" class="op-binary">ʸ√x</a><a href="#" class="op-unary">³√</a><a href="#" class="op-unary">|x|</a>
                            <a href="#" class="num" style="flex:2">0</a><a href="#" class="num">.</a>
                            <a href="#" class="op-binary">+</a><a href="#" id="btn_enter">=</a>
                        </div>
                    </div>
                </div>

            </main>
            <!-- /MAIN -->

            <!-- ═══════════════════════════════════════════
                 FOOTER — navigation buttons
            ════════════════════════════════════════════ -->
            <footer class="bg-gray-100 p-3 shadow-lg sticky bottom-0 z-10 border-t border-gray-300 flex-shrink-0">
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-4 gap-4 h-full items-center">

                    <div class="lg:col-span-3 flex justify-between pr-4 lg:border-r border-gray-300">
                        <div class="flex space-x-2">
                            <button id="mark-review-btn" class="btn-classic-white">Mark for Review &amp; Next</button>
                            <button id="clear-btn" class="btn-classic-white">Clear Response</button>
                        </div>
                        <div>
                            <button id="save-next-btn" class="btn-classic-blue">Save &amp; Next</button>
                        </div>
                    </div>

                    <div class="lg:col-span-1 flex justify-center pl-2">
                        <button id="submit-btn-footer" class="w-[90%] py-1.5 bg-[#66b8d9] hover:bg-[#55a0c0] border border-[#55a0c0] text-white text-sm rounded shadow-sm transition-colors">Submit</button>
                    </div>

                </div>
            </footer>
            <!-- /FOOTER -->

        </div>
        <!-- /main-app -->

        <!-- ═══════════════════════════════════════════
             SUBMIT FLOW (TCS iON NQT style, modules/exam_submit.css + SubmitFlow in exam_app.js)
        ════════════════════════════════════════════ -->
        <div id="nqt-confirm" class="nqt-screen hidden">
            <div class="nqt-topbar"><?= htmlspecialchars($subject_name . ' - ' . ($set_label ?? ('Set ' . $selected_set_int))) ?> Online Assessment</div>
            <div class="nqt-rule"></div>
            <div class="nqt-confirm-body">
                <p>Dear Candidate, Thank you. Please note that, your Assessment is about to be submitted. Click on 'OK' to proceed further.</p>
                <button type="button" id="nqt-confirm-ok" class="nqt-btn nqt-btn-ok">OK</button><button type="button" id="nqt-confirm-cancel" class="nqt-btn nqt-btn-cancel">Cancel</button>
            </div>
        </div>
        <div id="nqt-blank" class="nqt-screen hidden">
            <div id="nqt-saving" class="nqt-saving hidden"><div class="nqt-spinner"></div>Submitting your responses, please wait...</div>
        </div>
        <div id="nqt-info" class="nqt-backdrop hidden">
            <div class="nqt-info" role="dialog" aria-labelledby="nqt-info-text">
                <div class="nqt-info-title"><span>Info</span><span id="nqt-info-close">Close &#10005;</span></div>
                <div class="nqt-info-body"><div class="nqt-info-icon">i</div><div id="nqt-info-text"></div></div>
                <div class="nqt-info-foot"><button type="button" id="nqt-info-ok" class="nqt-btn-green">OK</button></div>
            </div>
        </div>
        <div id="nqt-done" class="nqt-screen hidden">
            <div class="nqt-done-box">
                Dear Learner,<br>You have now successfully submitted the assessment. Click on "Exit Assessment" to close this window.
                <div><button type="button" id="nqt-exit-btn" class="nqt-btn nqt-btn-ok">Exit Assessment</button></div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             INSTRUCTIONS + QUESTION PAPER POPUPS (modules/exam_popups.js)
        ════════════════════════════════════════════ -->
        <div id="xp-instr" class="xp-overlay hidden">
            <div class="xp-modal">
                <div class="xp-titlebar"><span>Instructions</span><span class="xp-close">Close &#10006;</span></div>
                <div class="xp-note">Note that the timer is ticking. Kindly close this window to attend to the questions.</div>
                <div class="xp-body xp-instr">
                    <?php include __DIR__ . '/modules/general_instructions.php'; ?>
                </div>
            </div>
        </div>

        <div id="xp-paper" class="xp-overlay hidden">
            <div class="xp-modal">
                <div class="xp-titlebar"><span>Question Paper</span><span class="xp-close">Close &#10006;</span></div>
                <div class="xp-note">Note that the timer is ticking while you read this question paper. Close this page to return to answering the questions.</div>
                <div class="xp-body" id="xp-paper-body"></div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             SUBMIT CONFIRMATION MODAL
        ════════════════════════════════════════════ -->
        <div id="submit-modal-overlay" class="fixed inset-0 bg-black bg-opacity-40 z-50 hidden flex justify-center items-center">
            <div class="bg-white rounded shadow-2xl w-[600px] max-w-[95%]">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-3xl font-bold text-[#445b73]">Submit quiz</h2>
                </div>
                <div class="p-8 grid grid-cols-2 gap-y-8 gap-x-4">
                    <div class="flex items-center gap-4">
                        <div class="modal-icon shortcut answered up" id="modal-answered" data-num="0"></div>
                        <span class="text-gray-500 text-base">Answered</span>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="modal-icon shortcut unanswered down" id="modal-not-answered" data-num="0"></div>
                        <span class="text-gray-500 text-base">Not Answered</span>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="modal-icon shortcut unvisited" id="modal-not-visited" data-num="0"></div>
                        <span class="text-gray-500 text-base">Not Visited</span>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="modal-icon shortcut review" id="modal-marked" data-num="0"></div>
                        <span class="text-gray-500 text-base">Marked for Review</span>
                    </div>
                    <div class="flex items-center gap-4 col-span-2 md:col-span-1">
                        <div class="modal-icon shortcut review answered-marked-dot" id="modal-answered-marked" data-num="0"></div>
                        <span class="text-gray-500 text-base leading-tight">Answered &amp; Marked for Review<br>(will be evaluated)</span>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button id="cancel-submit-btn" class="px-6 py-2 border border-gray-300 bg-white text-gray-700 text-xl hover:bg-gray-50 transition shadow-sm">Cancel</button>
                    <button id="confirm-submit-btn" class="px-6 py-2 bg-[#172b4d] text-white text-xl font-semibold hover:bg-[#0f1d33] transition shadow-sm">Submit</button>
                </div>
            </div>
        </div>

    <?php else: ?>

        <!-- ═══════════════════════════════════════════
             SET SELECTOR (when no set chosen yet)
        ════════════════════════════════════════════ -->
        <div id="set-selector-screen" class="p-8 max-w-2xl mx-auto bg-white shadow-xl rounded-xl mt-20">
            <h1 class="text-3xl font-extrabold text-blue-700 mb-6 border-b-2 pb-2">Select Mock Test Set</h1>
            <?php if (empty($available_sets)): ?>
                <div class="p-4 bg-red-100 border-l-4 border-red-500 text-red-700">
                    <h2 class="font-bold">No Test Sets Found</h2>
                    <p class="text-sm mt-1">There are no active test sets available at this time. Please check back later.</p>
                </div>
            <?php else: ?>
                <form action="instructions.php" method="GET" class="space-y-4">
                    <div>
                        <label for="set-no-select" class="block text-lg font-medium text-gray-700 mb-3">
                            Please choose a test set to begin:
                        </label>
                        <select id="set-no-select" name="set_no" class="block w-full p-3 border border-gray-300 rounded-lg text-lg bg-white">
                            <?php foreach ($available_sets as $set): ?>
                                <option value="<?= $set['set_no'] ?>|<?= $set['subject_id'] ?>">
                                    <?= htmlspecialchars($set['subject_name']) ?> – Set <?= $set['set_no'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="mt-6 w-full px-8 py-3 bg-green-600 text-white text-lg font-semibold rounded-lg shadow-lg hover:bg-green-700">
                        Proceed
                    </button>
                </form>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <?php if ($selected_set): ?>
    <!-- Face-cam proctoring: start photo capture + multi-face / same-person monitoring -->
    <script>
        window.FACE_CONFIG = {
            logUrl: 'face_log.php',
            matchThreshold: 0.55,  // lower = stricter match
            maxWarnings: 5         // 0 = only log, never terminate
        };
    </script>
    <script src="<?= CDN_FACE_API ?>"></script>
    <script>window.FACE_API_MODEL_URL = <?= json_encode(FACE_API_MODEL_URL) ?>;</script>
    <script src="modules/face_proctor.js?v=3"></script>
    <?php endif; ?>
    <script src="modules/calculator.js"></script>
    <?php if (strpos($questions_json, '"type":"CODE"') !== false): ?>
    <script>window.CODE_COMPILERS_URL = <?= json_encode(defined('CODE_COMPILERS_URL') ? CODE_COMPILERS_URL : 'vendor/compilers') ?>;</script>
    <script src="modules/coderun/runner.js?v=1"></script>
    <script src="modules/exam_coding.js?v=2"></script>
    <?php endif; ?>
    <script src="modules/exam_app.js?v=9"></script>
    <script src="modules/exam_popups.js?v=4"></script>

</body>
</html>