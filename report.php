<?php
// Performance report for one attempt, laid out like the TCS iON NQT report:
// cover, performance categories, where you stand, section-wise analysis, impact of incorrect
// responses, time management, response change pattern and question-wise details.
// report.php?attempt_id=N shows it in the browser; &pdf=1 downloads it as a PDF (dompdf).
// The layout uses tables only so dompdf renders it the same as the browser.
session_start();
include 'config.php';
require_once __DIR__ . '/modules/sections.php';
require_once __DIR__ . '/modules/coding.php';
require_once __DIR__ . '/modules/assessment.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
ensure_sections_schema($conn);
ensure_coding_schema($conn);
ensure_assessment_schema($conn);

$attempt_id = (int)($_GET['attempt_id'] ?? 0);
$data = assessment_report_data($conn, $attempt_id, (int)$_SESSION['user_id']);
if (!$data) {
    http_response_code(404);
    exit('Report not found.');
}
$meta = $data['meta'];
$sections = $data['sections'];
$as_pdf = !empty($_GET['pdf']);

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$f2 = fn($n) => number_format((float)$n, 2, '.', '');
$num = fn($n) => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
$svg = fn($markup, $w, $h) => "<img src='data:image/svg+xml;base64," . base64_encode($markup) . "' width='$w' height='$h' alt=''>";

function report_image_url(string $url): string
{
    if ($url === '') return '';
    if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $m) || preg_match('#/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return "https://drive.google.com/thumbnail?id={$m[1]}&sz=s1000";
    }
    return $url;
}

// Flat 2D pie with "Label, value" captions and leader lines
function report_pie(array $slices, int $w = 520, int $h = 260): string
{
    $total = array_sum(array_column($slices, 'value'));
    $cx = $w / 2; $cy = $h / 2; $r = min($w, $h) / 2 - 40;
    $out = "<svg xmlns='http://www.w3.org/2000/svg' width='$w' height='$h' viewBox='0 0 $w $h' font-family='Arial' font-size='12'>";
    if ($total <= 0) {
        return $out . "<circle cx='$cx' cy='$cy' r='$r' fill='#e5e7eb'/><text x='$cx' y='" . ($cy + 4) . "' text-anchor='middle' fill='#555'>No data</text></svg>";
    }
    $a0 = -M_PI / 2;
    $captions = '';
    foreach ($slices as $s) {
        if ($s['value'] <= 0) continue;
        $frac = $s['value'] / $total;
        $a1 = $a0 + $frac * 2 * M_PI;
        if ($frac >= 0.9999) {
            $out .= "<circle cx='$cx' cy='$cy' r='$r' fill='{$s['color']}'/>";
        } else {
            $x0 = $cx + $r * cos($a0); $y0 = $cy + $r * sin($a0);
            $x1 = $cx + $r * cos($a1); $y1 = $cy + $r * sin($a1);
            $large = $frac > 0.5 ? 1 : 0;
            $out .= sprintf("<path d='M%.2f %.2f L%.2f %.2f A%.2f %.2f 0 %d 1 %.2f %.2f Z' fill='%s' stroke='#fff' stroke-width='1'/>",
                $cx, $cy, $x0, $y0, $r, $r, $large, $x1, $y1, $s['color']);
        }
        $am = ($a0 + $a1) / 2;
        $lx0 = $cx + ($r - 2) * cos($am); $ly0 = $cy + ($r - 2) * sin($am);
        $lx1 = $cx + ($r + 14) * cos($am); $ly1 = $cy + ($r + 14) * sin($am);
        $right = cos($am) >= 0;
        $lx2 = $lx1 + ($right ? 10 : -10);
        $label = htmlspecialchars($s['label'] . ', ' . $s['text'], ENT_QUOTES);
        $captions .= sprintf("<path d='M%.1f %.1f L%.1f %.1f L%.1f %.1f' stroke='#666' fill='none' stroke-width='0.8'/>", $lx0, $ly0, $lx1, $ly1, $lx2, $ly1);
        $captions .= sprintf("<text x='%.1f' y='%.1f' text-anchor='%s' fill='#333' font-weight='bold'>%s</text>", $lx2 + ($right ? 3 : -3), $ly1 + 4, $right ? 'start' : 'end', $label);
        $a0 = $a1;
    }
    return $out . $captions . "</svg>";
}

// Small round icons used in the question details and the legend
function report_icon(string $kind): string
{
    $c = "<svg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 20 20'>";
    switch ($kind) {
        case 'correct':    $c .= "<circle cx='10' cy='10' r='9' fill='#666'/><path d='M5.5 10.5l3 3 6-6.5' stroke='#fff' stroke-width='2.2' fill='none'/>"; break;
        case 'incorrect':  $c .= "<circle cx='10' cy='10' r='9' fill='#666'/><path d='M6.5 6.5l7 7M13.5 6.5l-7 7' stroke='#fff' stroke-width='2.2'/>"; break;
        case 'unanswered': $c .= "<circle cx='10' cy='10' r='9' fill='#666'/><path d='M10 5v6' stroke='#fff' stroke-width='2.4'/><circle cx='10' cy='14.5' r='1.4' fill='#fff'/>"; break;
        case 'partial':    $c .= "<circle cx='10' cy='10' r='8' fill='none' stroke='#666' stroke-width='1.6' stroke-dasharray='3 2'/><path d='M6 10.5l3 3 5-6' stroke='#666' stroke-width='2' fill='none'/>"; break;
        case 'marked':     $c .= "<path d='M10 1.5l2.6 5.6 6 .7-4.5 4.1 1.2 6-5.3-3-5.3 3 1.2-6L1.4 7.8l6-.7z' fill='#222'/>"; break;
        case 'tick':       $c .= "<path d='M4 10.5l4 4 8-9' stroke='#555' stroke-width='2.4' fill='none'/>"; break;
        case 'hand':       $c .= "<path d='M2 9h8.5a1.5 1.5 0 0 1 0 3H9l-.2.1H12a1.4 1.4 0 0 1 0 2.8h-1a1.3 1.3 0 0 1 0 2.6H6c-2.5 0-4-1.5-4-4z' fill='#555'/><path d='M2 9l4-4.5c.8-.9 2 .1 1.4 1.1L6 8.6' fill='#555'/>"; break;
        case 'answered':   $c .= "<rect x='2' y='2' width='16' height='13' rx='2' fill='#555'/><path d='M7 15l3 4 3-4' fill='#555'/><text x='10' y='12.5' font-family='Arial' font-size='10' font-weight='bold' fill='#fff' text-anchor='middle'>A</text>"; break;
        case 'evaluated':  $c .= "<path d='M6 2h8v2l-2 1v5l3 3H5l3-3V5L6 4z M9.5 13h1v6h-1z' fill='#333'/>"; break;
        case 'notevaluated': $c .= "<path d='M3 2h1.5v17H3z M6 3h11l-2.5 4L17 11H6z' fill='#444'/>"; break;
    }
    return $c . "</svg>";
}

function report_cover_art(): string
{
    return "<svg xmlns='http://www.w3.org/2000/svg' width='460' height='560' viewBox='0 0 460 560'>
      <path d='M20 60 L80 120' stroke='#2b5a8a' stroke-width='3'/><path d='M420 30 L380 110' stroke='#2b5a8a' stroke-width='3'/>
      <g transform='translate(40 170) skewY(18)'>
        <rect x='0' y='0' width='300' height='230' rx='18' fill='#2b2b2b'/>
        <rect x='14' y='14' width='272' height='202' rx='6' fill='#cfeaf3'/>
      </g>
      <rect x='90' y='230' width='26' height='120' fill='#e05a4f'/><rect x='130' y='170' width='30' height='150' fill='#6cb2e0'/>
      <rect x='172' y='110' width='34' height='210' fill='#4caf50'/><rect x='214' y='150' width='30' height='180' fill='#f2d22e'/>
      <rect x='256' y='70' width='36' height='270' fill='#d9443b'/>
      <polyline points='96,330 130,250 150,300 175,240 200,310' fill='none' stroke='#f39c12' stroke-width='9'/>
      <circle cx='190' cy='380' r='42' fill='#f2d22e'/><path d='M190 380 L190 338 A42 42 0 0 1 228 362 Z' fill='#d9443b'/>
      <path d='M190 380 L228 362 A42 42 0 0 1 222 408 Z' fill='#4caf50'/>
      <rect x='330' y='150' width='60' height='16' rx='8' fill='#1e73b8'/><rect x='330' y='280' width='60' height='16' rx='8' fill='#1e73b8'/>
      <path d='M338 166 Q360 220 338 280 L382 280 Q360 220 382 166 Z' fill='#e9f4fb'/><path d='M346 250 L374 250 L380 278 L340 278 Z' fill='#f2c12e'/>
      <circle cx='345' cy='420' r='16' fill='#f2c12e'/><circle cx='352' cy='398' r='16' fill='#f2c12e'/><circle cx='342' cy='376' r='16' fill='#f2c12e'/>
      <rect x='40' y='470' width='90' height='70' rx='8' fill='#d9443b'/><rect x='50' y='478' width='70' height='18' fill='#cfeaf3'/>
      <rect x='160' y='440' width='110' height='90' fill='#fff' transform='rotate(-12 215 485)'/>
      <path d='M180 450 l20 -20 20 20 -20 20z' fill='#3a8fd8'/><path d='M200 470 l20 -20 20 20 -20 20z' fill='#e05a4f'/>
      <circle cx='330' cy='500' r='26' fill='none' stroke='#e9e9e9' stroke-width='9'/><path d='M312 518 L280 550' stroke='#d9443b' stroke-width='12'/>
      <path d='M20 380 L90 460' stroke='#f2c12e' stroke-width='10'/><path d='M30 360 L110 440' stroke='#d9443b' stroke-width='10'/>
    </svg>";
}

$cat = PERF_CATEGORIES[$meta['category']];
$score = (float)$meta['score'];
$max = (float)$meta['max'];
$time_total = array_sum(array_column($sections, 'time'));
$time_known = in_array(true, array_column($sections, 'time_known'), true);
$sec_label = fn($s) => $s['name'];
$perc_cell = fn($p) => "<span" . ($p < 60 ? " style='color:#e53935'" : '') . ">" . number_format($p, 2) . "%</span>";
$assessment_date = $meta['start_time'] ? (new DateTime($meta['start_time']))->format('d-m-Y H:i:s') . ' (GMT+05:30)' : '--';

$comment = [
    'correct'    => 'You are on the right preparation track on this topic.',
    'incorrect'  => 'You have most probably committed a numerical or conceptual mistake or you would have guessed the answer.',
    'partial'    => 'Your program passed only some of the test cases. Check the edge cases and the constraints.',
    'unanswered' => 'You need to work more on this topic.',
    'marked'     => 'You marked this question for review but did not answer it. Practise more questions on this topic.',
];
$status_text = ['correct' => 'Correct', 'incorrect' => 'Incorrect', 'partial' => 'Partially Correct', 'unanswered' => 'Not Attempted', 'marked' => 'Marked for Review'];
$advice = [
    'E' => 'Keep up the good work and keep practising to maintain your speed and accuracy.',
    'H' => 'You are doing well. Revise the topics where you lost marks to move to the top category.',
    'M' => 'Please avoid misconceptions and try to increase the speed of solving.',
    'L' => 'Revise the basic concepts and practise more questions before your next attempt.',
];
$strongest = $sections ? array_reduce($sections, fn($best, $s) => ($best === null || $s['percent'] > $best['percent']) ? $s : $best) : null;

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Report - <?= $e($meta['name']) ?> - Attempt <?= (int)$meta['attempt_no'] ?></title>
<?php if (!$as_pdf): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,400;0,600;0,700;1,400;1,700&display=swap">
<?php endif; ?>
<style>
    @page { size: A4 landscape; margin: 14mm 12mm 14mm 12mm; }
    body { font-family: 'Open Sans', 'DejaVu Sans', Arial, sans-serif; color: #222; font-size: 12px; margin: 0; }
    body.screen { background: #e9ebee; }
    .page { background: #fff; }
    body.screen .page { width: 1100px; margin: 18px auto; padding: 26px 34px; box-shadow: 0 1px 6px rgba(0,0,0,.15); }
    .break { page-break-before: always; }
    h2 { color: #2f4a8a; font-weight: 400; font-size: 28px; text-align: center; margin: 22px 0 10px; }
    h3 { color: #2f3f8f; font-weight: 400; font-size: 15px; margin: 18px 0 8px; }
    p { margin: 4px 0 8px; line-height: 1.5; }
    table.grid { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
    table.grid th { background: #6cc3e0; color: #fff; font-size: 10px; font-weight: 700; text-transform: uppercase; text-align: left; padding: 9px 10px; border: 1px solid #fff; font-family: Arial, sans-serif; }
    table.grid td { border: 1px solid #ddd; padding: 12px 10px; font-size: 13px; }
    table.grid td.n, table.grid th.n { text-align: right; }
    table.grid tr.total td { font-weight: 700; }
    .note { color: #888; font-size: 11px; }
    .note i { color: #222; font-weight: 700; }
    ol.rec { margin: 4px 0 8px 18px; padding: 0; line-height: 1.6; }
    ol.rec div { padding-left: 22px; }
    /* cover */
    .cover td { vertical-align: top; }
    .cover-left { background: #6b95e8; width: 46%; text-align: center; padding-top: 10px; }
    .cover-right { background: #0b649d; color: #fff; padding: 50px 30px 20px 50px; }
    .cover-right .nm { font-size: 18px; font-weight: 600; line-height: 1.6; }
    .cover-right .sm { font-size: 13px; color: #dbe8f3; line-height: 1.7; }
    .tile { background: #3f7aa7; border-radius: 6px; }
    .tile td { color: #fff; text-align: center; padding: 10px 14px; }
    .tile .big { font-size: 26px; font-weight: 600; }
    .tile .lb { font-size: 12px; color: #e7f0f7; }
    .tile .sep { border-left: 1px solid #a9c6dc; }
    /* question details */
    .legend { width: 100%; border: 1px solid #ddd; border-collapse: collapse; margin-top: 4px; }
    .legend td { padding: 4px 8px; font-size: 12px; }
    .legend img { vertical-align: middle; }
    .qhead { background: #5fb0f0; color: #fff; font-size: 20px; padding: 12px 10px; margin-top: 18px; }
    .q { border: 1px solid #ddd; padding: 14px 20px; margin-top: 6px; }
    body.screen .q { page-break-inside: avoid; }   /* dompdf puts every question on its own page with this */
    .q .title { font-size: 15px; }
    .q .title img { vertical-align: middle; }
    .q .meta { margin: 10px 0 8px; font-size: 12px; }
    .q .meta span { padding: 0 10px; border-left: 1px solid #999; }
    .q .meta span:first-child { padding-left: 0; border-left: 0; }
    .q .qimg { max-width: 640px; max-height: 360px; margin: 8px 0 0 30px; }
    .opts { border-collapse: collapse; margin: 4px 0 8px; }
    .opts td { border-bottom: 1px solid #ddd; padding: 3px 4px; font-size: 14px; vertical-align: middle; }
    .opts td.mk { width: 44px; }
    .opts img.oimg { max-height: 120px; max-width: 420px; }
    .stats { font-size: 11px; line-height: 1.8; }
    .stats span { padding: 0 8px; border-left: 1px solid #999; white-space: nowrap; }
    .stats span.first { padding-left: 0; border-left: 0; }
    pre.code { background: #1e1e1e; color: #d4d4d4; padding: 10px; white-space: pre-wrap; font-size: 11px; font-family: Consolas, 'DejaVu Sans Mono', monospace; }
    .toolbar { position: sticky; top: 0; background: #0b649d; color: #fff; padding: 10px 20px; text-align: right; z-index: 5; }
    .toolbar a, .toolbar button { background: #fff; color: #0b649d; border: 0; border-radius: 4px; padding: 7px 16px; font-weight: 700; font-size: 13px; cursor: pointer; text-decoration: none; margin-left: 8px; font-family: inherit; }
    .toolbar .ttl { float: left; padding-top: 6px; font-weight: 600; }
    @media print { .toolbar { display: none; } body.screen { background: #fff; } body.screen .page { width: auto; margin: 0; padding: 0; box-shadow: none; } }
</style>
</head>
<body class="<?= $as_pdf ? 'pdf' : 'screen' ?>">
<?php if (!$as_pdf): ?>
<div class="toolbar">
    <span class="ttl"><?= $e($meta['title']) ?> &middot; Attempt <?= (int)$meta['attempt_no'] ?></span>
    <a href="report.php?attempt_id=<?= (int)$attempt_id ?>&pdf=1">Download PDF</a>
    <button type="button" onclick="window.print()">Print</button>
</div>
<?php endif; ?>

<!-- Cover -->
<div class="page">
<table class="cover" style="width:100%; border-collapse:collapse;">
<tr>
    <td class="cover-left"><?= $svg(report_cover_art(), 360, 438) ?></td>
    <td class="cover-right">
        <div class="nm"><?= $e(strtoupper($meta['name'])) ?></div>
        <div class="nm">(<?= $e($meta['email']) ?>_<?= (int)$meta['attempt_id'] ?>_<?= (int)$meta['attempt_no'] ?>)</div>
        <div class="sm"><?= $e($meta['title']) ?></div>
        <div class="sm">Assessment Date : <?= $e($assessment_date) ?></div>
        <div class="sm"><span style="color:#6fe0c8">Performance Level :</span> <b style="color:#fff"><?= $e($cat['name']) ?></b></div>
        <table style="margin-top:26px; border-collapse:separate; border-spacing:0 0;">
            <tr>
                <td style="padding:0 10px 10px 0;">
                    <table class="tile"><tr>
                        <td><div class="big"><?= $f2($score) ?></div><div class="lb">Your Total<br>Score</div></td>
                        <td class="sep"><div class="big"><?= $f2($max) ?></div><div class="lb">Assessment<br>Score</div></td>
                    </tr></table>
                </td>
                <td style="padding:0 0 10px 0;">
                    <table class="tile"><tr>
                        <td><div class="big"><?= $meta['pass_marks'] === null ? '--' : $f2($meta['pass_marks']) ?></div><div class="lb">Cut-Off marks<br>(Pass Marks)</div></td>
                    </tr></table>
                </td>
            </tr>
            <tr>
                <td style="padding:0 10px 0 0;">
                    <table class="tile"><tr>
                        <td><div class="big"><?= $f2($meta['percent']) ?></div><div class="lb">Your<br>Percentage</div></td>
                        <td class="sep"><div class="big"><?= $e($meta['category']) ?></div><div class="lb">Performance<br>Category</div></td>
                    </tr></table>
                </td>
                <td></td>
            </tr>
        </table>
        <div style="border-top:1px dashed #6f9cc0; margin:24px 0 14px;"></div>
        <div style="font-size:20px; line-height:1.3;">This report helps you to achieve your targets as per below stated objectives:</div>
        <div class="sm" style="padding-left:50px; margin-top:10px; font-size:14px;">Improve your conceptual understanding<br>Address specific areas of improvement personalized to you</div>
    </td>
</tr>
</table>
</div>

<!-- Performance categories -->
<div class="page break">
    <h2>Performance Categories</h2>
    <p>Based on the performance of the students, we have framed the following categories to place you in accordance with your performance</p>
    <h3>Performance Category Definitions</h3>
    <table style="border-collapse:collapse;">
        <?php foreach (PERF_CATEGORIES as $c): ?>
        <tr><td style="padding:2px 6px 0 0; vertical-align:top;"><?= $svg("<svg xmlns='http://www.w3.org/2000/svg' width='22' height='22' viewBox='0 0 22 22'><circle cx='11' cy='11' r='9.5' fill='none' stroke='{$c['color']}' stroke-width='2'/><circle cx='11' cy='11' r='6' fill='{$c['color']}'/><path d='M11 11l3-3' stroke='#fff' stroke-width='2'/></svg>", 22, 22) ?></td>
            <td style="padding:2px 0 6px;"><span style="color:<?= $c['color'] ?>; font-size:16px;"><?= $e($c['name']) ?></span><br><?= $e($c['text']) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <h3>Performance Criteria</h3>
    <table class="grid">
        <tr><th style="width:50%">Performance Category</th><th>Range</th></tr>
        <?php foreach (PERF_CATEGORIES as $c): ?><tr><td><?= $e($c['name']) ?></td><td><?= $e($c['range']) ?></td></tr><?php endforeach; ?>
    </table>
    <h3>Performance Category based on student marks</h3>
    <table class="grid">
        <tr><th>Section (Group)</th><th>Excellent</th><th>High</th><th>Moderate</th><th>Low</th></tr>
        <?php
        $band = fn($m) => [$f2($m * .91) . ' and above', $f2($m * .81) . ' to ' . $f2($m * .90), $f2($m * .61) . ' to ' . $f2($m * .80), 'Below ' . $f2($m * .60)];
        foreach ($sections as $s): $b = $band($s['max']); ?>
            <tr><td><?= $e($sec_label($s)) ?></td><td><?= $b[0] ?></td><td><?= $b[1] ?></td><td><?= $b[2] ?></td><td><?= $b[3] ?></td></tr>
        <?php endforeach; $b = $band($max); ?>
        <tr class="total"><td>Overall Score</td><td><?= $b[0] ?></td><td><?= $b[1] ?></td><td><?= $b[2] ?></td><td><?= $b[3] ?></td></tr>
    </table>

    <h3>Where do you stand?</h3>
    <table class="grid">
        <tr><th style="width:32%">Section (Group)</th><th class="n" style="width:26%">Score</th><th>Performance Category</th></tr>
        <?php foreach ($sections as $s): ?>
            <tr><td><?= $e($sec_label($s)) ?></td><td class="n"><?= $f2($s['score']) ?> / <?= $f2($s['max']) ?></td><td><?= $s['category'] ?></td></tr>
        <?php endforeach; ?>
        <tr class="total"><td>Overall Score</td><td class="n"><?= $f2($score) ?> / <?= $f2($max) ?></td><td><?= $meta['category'] ?></td></tr>
    </table>

    <h2>Recommendations and Suggestions</h2>
    <ol class="rec">
        <li>Based on your overall scores:<div>Your overall score falls in the <b><?= $meta['category'] ?></b> category. <?= $e($advice[$meta['category']]) ?></div></li>
        <?php if ($strongest): ?>
        <li>Based on your section-wise performance:<div>You seem to be strong in <b><?= $e($strongest['name']) ?></b>. So it is suggested that you attempt <b><?= $e($strongest['name']) ?></b> section first</div></li>
        <?php endif; ?>
        <li>Some general suggestions to optimize your score:<div>The best performers plan and allocate equal time to each section.</div></li>
    </ol>
</div>

<!-- Overall performance analysis -->
<div class="page break">
    <h2>Overall Performance Analysis</h2>
    <p>The below table shows section-wise analysis of marks scored by you, time spent by you, your percentage, your accuracy and number of correct, incorrect, unanswered and marked for review questions.</p>
    <table class="grid">
        <tr><th>Section (Group)</th><th class="n">Marks Scored by You</th><th class="n">Time Spent by You (in mins)</th><th class="n">Your Section Percentage</th>
            <th class="n">Your Section Accuracy</th><th class="n">Total Questions</th><th class="n">Max No of Questions - to Attempt</th><th class="n">Questions Attempted</th>
            <th class="n">Correct</th><th class="n">Incorrect</th><th class="n">Unanswered</th><th class="n">Marked for Review</th></tr>
        <?php $tot = ['q' => 0, 'att' => 0, 'c' => 0, 'i' => 0, 'u' => 0, 'm' => 0];
        foreach ($sections as $s): $n = count($s['questions']);
            $tot['q'] += $n; $tot['att'] += $s['attempted']; $tot['c'] += $s['correct']; $tot['i'] += $s['incorrect']; $tot['u'] += $s['unanswered']; $tot['m'] += $s['marked']; ?>
            <tr><td><?= $e($sec_label($s)) ?></td><td class="n"><?= $f2($s['score']) ?></td><td class="n"><?= $s['time_known'] ? assessment_mmss($s['time']) : '--' ?></td>
                <td class="n"><?= $perc_cell($s['percent']) ?></td><td class="n"><?= $perc_cell($s['accuracy']) ?></td><td class="n"><?= $n ?></td><td class="n"><?= $n ?></td>
                <td class="n"><?= $s['attempted'] ?></td><td class="n"><?= $s['correct'] ?></td><td class="n"><?= $s['incorrect'] ?></td><td class="n"><?= $s['unanswered'] ?></td><td class="n"><?= $s['marked'] ?></td></tr>
        <?php endforeach;
        $acc_all = $tot['att'] > 0 ? $tot['c'] / $tot['att'] * 100 : 0; ?>
        <tr class="total"><td>Total</td><td class="n"><?= $f2($score) ?></td><td class="n"><?= $time_known ? assessment_mmss($time_total) : assessment_mmss((int)$meta['time_taken_seconds']) ?></td>
            <td class="n"><?= $perc_cell($meta['percent']) ?></td><td class="n"><?= $perc_cell($acc_all) ?></td><td class="n"><?= $tot['q'] ?></td><td class="n"><?= $tot['q'] ?></td>
            <td class="n"><?= $tot['att'] ?></td><td class="n"><?= $tot['c'] ?></td><td class="n"><?= $tot['i'] ?></td><td class="n"><?= $tot['u'] ?></td><td class="n"><?= $tot['m'] ?></td></tr>
    </table>
    <div class="note">Note: <i>The percentage (%) and accuracy below the prescribed values (60 %) are shown in red color</i></div>

    <p style="margin-top:16px;">Below pie-chart shows section-wise percentage of marks scored</p>
    <h3 style="font-size:18px; padding-left:150px;">Section-wise marks</h3>
    <?php $palette = ['#1f78c1', '#f39c12', '#2fb344', '#8e44ad', '#e74c3c', '#16a085', '#7f8c8d'];
    $slices = [];
    foreach ($sections as $i => $s) $slices[] = ['label' => $s['name'], 'value' => max(0, $s['score']), 'text' => $num(max(0, $s['score'])), 'color' => $palette[$i % count($palette)]];
    echo $svg(report_pie($slices, 620, 280), 620, 280); ?>
</div>

<div class="page break">
    <h2>Impact of Incorrect Responses</h2>
    <p>Below table provides the marks lost due to incorrect responses.</p>
    <table class="grid">
        <tr><th>Section(Group)</th><th class="n">Number of Incorrect Responses</th><th class="n">Marks Lost Due to Incorrect Responses</th><th class="n">Total Score if Incorrect Responses Were Not Marked</th></tr>
        <?php $lost_all = 0; $inc_all = 0;
        foreach ($sections as $s): $lost_all += $s['neg_lost']; $inc_all += $s['incorrect']; ?>
            <tr><td><?= $e($sec_label($s)) ?></td><td class="n"><?= $s['incorrect'] ?></td><td class="n"><?= $num($s['neg_lost']) ?></td><td class="n"><?= $num($s['score'] + $s['neg_lost']) ?></td></tr>
        <?php endforeach; ?>
        <tr class="total"><td>Overall</td><td class="n"><?= $inc_all ?></td><td class="n"><?= $num($lost_all) ?></td><td class="n"><?= $f2($score + $lost_all) ?></td></tr>
    </table>
    <p style="margin-top:16px;">In order to attempt more accurately, consider the following suggestions while attempting the questions:<br>
        1. If you are not able to solve a question correctly or have doubts in your approach towards the solution, skip it for later.<br>
        2. Quickly revise the steps for avoiding calculation or casual mistakes.<br>
        3. Avoid guesswork.</p>

    <h2>Time Management</h2>
    <p>Below table shows the time you spent in each section.</p>
    <table class="grid">
        <tr><th style="width:44%">Section (Group)</th><th class="n">Time Spent by You (in mins)</th></tr>
        <?php foreach ($sections as $s): ?>
            <tr><td><?= $e($sec_label($s)) ?></td><td class="n"><?= $s['time_known'] ? assessment_mmss($s['time']) : '--' ?></td></tr>
        <?php endforeach; ?>
        <tr class="total"><td>Total time spent</td><td class="n"><?= assessment_mmss((int)$meta['time_taken_seconds']) ?></td></tr>
    </table>
    <h2>Recommendations</h2>
    <ol class="rec">
        <li>It is essential for each aspirant to plan and schedule time for each section diligently. This is important to score well in each section and ultimately meet the cut-off.</li>
        <li>This will also help you in attempting all the questions in each section and hence not missing the opportunity to score more.</li>
    </ol>
</div>

<div class="page break">
    <h2>Response Change Pattern</h2>
    <p>Below table provides the number of times you have changed your responses while answering the test and also the nature of those response changes.</p>
    <table class="grid">
        <tr><th>Section(Group)</th><?php foreach (ASSESSMENT_CHANGE_TYPES as $label): ?><th class="n"><?= $e($label) ?></th><?php endforeach; ?></tr>
        <?php $ch_all = array_fill_keys(array_keys(ASSESSMENT_CHANGE_TYPES), 0);
        foreach ($sections as $s): ?>
            <tr><td><?= $e($sec_label($s)) ?></td><?php foreach (ASSESSMENT_CHANGE_TYPES as $k => $label): $ch_all[$k] += $s['changes'][$k]; ?><td class="n"><?= $s['changes'][$k] ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <tr class="total"><td>Overall</td><?php foreach ($ch_all as $v): ?><td class="n"><?= $v ?></td><?php endforeach; ?></tr>
    </table>
    <p style="margin-top:14px;">It is suggested that guesswork should be avoided for any type of response changes. It has been observed that more often than not, guesswork leads to an incorrect response thereby inviting negative marks which in turn has an adverse effect on the overall rank.<br>
        You must use your knowledge, observation and elimination skills to arrive at the correct answer.</p>
    <h2>Interpretation and Suggestions</h2>
    <ol class="rec">
        <li>Incorrect to incorrect response change:<div>You may need to work more on the concept level, in order to gain confidence.</div></li>
        <li>Incorrect to correct response change:<div>At the first glance you were not very sure about the solution.<br>You must spend at least 1 minute per question and if you are not able to reach to the solution, you must revisit the question to enhance your score.<br>Perform this response change only when you are confident or have spotted a mistake in the solution of your first response.</div></li>
        <li>Correct to incorrect response change:<div>You are not sure of the solution and have either applied a wrong concept or made a calculation mistake.<br>You need to practice more questions on the same concept.</div></li>
        <li>Correct to unanswered response change:<div>You are not sure of the solution<br>You need to practice more questions on the same concept.<br>Perform this response change only when you are not confident of your solution.<br>You must try to spend at least 1 min before leaving it unanswered.</div></li>
        <li>Incorrect to unanswered response change:<div>Your judgment of avoiding negative marks is right.<br>You must try to spend at least 1 min before leaving it unanswered.</div></li>
    </ol>
</div>

<?php foreach ($sections as $s): ?>
<div class="page break">
    <h2>Overview: <?= $e($s['name']) ?></h2>
    <p>The below table provides your marks in <?= $e($s['name']) ?> along with your percentage, accuracy and the time you spent.</p>
    <table class="grid">
        <tr><th class="n">Marks Scored by You</th><th class="n">Your Section Percentage</th><th class="n">Your Section Accuracy</th><th class="n">Time Spent by You (in mins)</th></tr>
        <tr><td class="n"><?= $f2($s['score']) ?> / <?= $f2($s['max']) ?></td><td class="n"><?= $perc_cell($s['percent']) ?></td><td class="n"><?= $perc_cell($s['accuracy']) ?></td><td class="n"><?= $s['time_known'] ? assessment_mmss($s['time']) : '--' ?></td></tr>
    </table>
    <div class="note">Note: <i>The percentage (%) and accuracy below the prescribed values (60%) are shown in red color</i></div>

    <h3 style="font-size:18px; padding-left:150px; margin-top:26px;">Question wise Analysis</h3>
    <?= $svg(report_pie([
        ['label' => 'Correct', 'value' => $s['correct'], 'text' => $s['correct'], 'color' => '#7ee07e'],
        ['label' => 'Incorrect', 'value' => $s['incorrect'], 'text' => $s['incorrect'], 'color' => '#ff7f7f'],
        ['label' => 'Unanswered', 'value' => $s['unanswered'], 'text' => $s['unanswered'], 'color' => '#9e9e9e'],
        ['label' => 'Marked for Review', 'value' => $s['marked'], 'text' => $s['marked'], 'color' => '#9b30b0'],
    ], 620, 260), 620, 260) ?>
    <table style="margin-left:60px; background:#f2f2f2; border-collapse:collapse;"><tr>
        <?php foreach (['Correct' => '#2fbf2f', 'Incorrect' => '#e53935', 'Unanswered' => '#888', 'Marked for Review' => '#8e24aa'] as $lbl => $col): ?>
            <td style="padding:4px 8px;"><?= $svg("<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12'><circle cx='6' cy='6' r='6' fill='$col'/></svg>", 12, 12) ?> <b style="color:#444"><?= $lbl ?></b></td>
        <?php endforeach; ?>
    </tr></table>

    <h2>Performance Analysis: <?= $e($s['name']) ?></h2>
    <ol class="rec">
        <li>The below table analyzes your performance at question level</li>
        <li>It highlights conceptually strong and improvement areas within the section and areas that require reinforcement of concepts.</li>
        <li>The accuracy of the response to each question and time spent are correlated and interpreted in terms of expert advice on preparedness level.</li>
    </ol>
    <h2>Question wise details</h2>

    <table class="legend"><tr>
        <td><?= $svg(report_icon('notevaluated'), 16, 16) ?> = Not Evaluated</td><td><?= $svg(report_icon('evaluated'), 16, 16) ?> = Evaluated</td>
        <td><?= $svg(report_icon('correct'), 16, 16) ?> = Correct</td><td><?= $svg(report_icon('incorrect'), 16, 16) ?> = Incorrect</td><td><?= $svg(report_icon('unanswered'), 16, 16) ?> = Not Attempted</td>
    </tr><tr>
        <td><?= $svg(report_icon('marked'), 16, 16) ?> = Marked for Review</td><td><?= $svg(report_icon('answered'), 16, 16) ?> = Answered</td>
        <td><?= $svg(report_icon('tick'), 16, 16) ?> = Correct Option</td><td><?= $svg(report_icon('hand'), 16, 16) ?> = Your Option</td><td><?= $svg(report_icon('partial'), 16, 16) ?> = Partially Correct</td>
    </tr></table>
    <div class="qhead">Question Details</div>

    <?php foreach ($s['questions'] as $q):
        $icon = ['correct' => 'correct', 'incorrect' => 'incorrect', 'partial' => 'partial', 'marked' => 'marked'][$q['result']] ?? 'unanswered'; ?>
    <div class="q">
        <div class="title"><?= $svg(report_icon($icon), 22, 22) ?> <b>Q<?= $q['number'] ?>.</b>
            <?php if ($q['type'] === 'CODE'): ?> <?= $e($q['code']['title']) ?> <span style="color:#666">(Coding)</span><?php endif; ?></div>
        <?php if ($q['type'] === 'CODE'): ?>
            <?php if ($q['code']['statement'] !== ''): ?><div style="white-space:pre-wrap; margin:6px 0 0 30px;"><?= $e($q['code']['statement']) ?></div><?php endif; ?>
        <?php elseif ($q['image'] !== ''): ?>
            <div><img class="qimg" src="<?= $e(report_image_url($q['image'])) ?>" alt="Question <?= $q['number'] ?>"></div>
        <?php endif; ?>

        <div class="meta"><span>Status : <b><?= $e($status_text[$q['result']]) ?></b></span><span>Marks Obtained : <b><?= $num($q['obtained']) ?></b></span><span>Total Question Marks : <b><?= $num($q['marks']) ?></b></span><span>Question Type : <b><?= $q['type'] === 'CODE' ? 'Coding' : $e($q['type']) ?></b></span></div>

        <?php if ($q['type'] === 'MCQ' || $q['type'] === 'MSQ'): ?>
            <div style="font-size:14px;">Options :</div>
            <table class="opts">
            <?php foreach ($q['options'] as $i => $o):
                $val = (string)($o['text'] ?? chr(65 + $i));
                $mine = in_array($val, $q['selected'], true);
                $right = in_array($val, array_map('strval', (array)$q['correct']), true);
                $img = report_image_url((string)($o['image'] ?? '')); ?>
                <tr><td class="mk"><?= $mine ? $svg(report_icon('hand'), 14, 14) : '' ?> <?= $right ? $svg(report_icon('tick'), 14, 14) : '' ?></td>
                    <td><?= $i + 1 ?>.</td>
                    <td><?php if ($img !== ''): ?><img class="oimg" src="<?= $e($img) ?>" alt="Option <?= $e($val) ?>"><?php else: ?><?= $e($val) ?><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </table>
        <?php elseif ($q['type'] === 'NAT'): ?>
            <div style="font-size:13px; margin-bottom:8px;">Your Answer : <b><?= $q['answered'] ? $e($q['selected'][0]) : '--' ?></b>
                <span style="padding-left:20px;">Correct Answer : <b><?= $q['range'][0] == $q['range'][1] ? $num($q['range'][0]) : $num($q['range'][0]) . ' to ' . $num($q['range'][1]) ?></b></span></div>
        <?php elseif ($q['type'] === 'CODE'): ?>
            <div style="font-size:13px; margin-bottom:6px;">
                <?php if ($q['answered']): ?>Language : <b><?= $e(CODING_LANGUAGES[$q['code']['lang']] ?? $q['code']['lang']) ?></b>
                    <span style="padding-left:20px;">Test Cases Passed : <b><?= $q['code']['passed'] ?> / <?= $q['code']['total'] ?></b><?= $q['code']['compileError'] ? ' (compilation error)' : '' ?></span>
                <?php else: ?>Code was not submitted.<?php endif; ?>
            </div>
            <?php if ($q['answered'] && $q['code']['source'] !== ''): ?><pre class="code"><?= $e($q['code']['source']) ?></pre><?php endif; ?>
        <?php endif; ?>

        <div class="stats">
            <span class="first">Timespent (in sec): <b><?= $q['time'] === null ? '--' : $q['time'] ?></b></span>
            <?php foreach (ASSESSMENT_CHANGE_TYPES as $k => $label): ?><span><?= $e($label) ?>: <b><?= $q['changes'][$k] ?></b></span>
            <?php endforeach; ?><br><span class="first">Comments: <b><?= $e($comment[$q['result']]) ?></b></span>
        </div>
    </div>
    <?php endforeach; ?>

    <h2 style="margin-top:40px;">Your Response Change Pattern: <?= $e($s['name']) ?></h2>
    <p>The below table provides the number of times you have changed your responses to the <?= $e($s['name']) ?> questions and also the nature of those response changes.</p>
    <table class="grid">
        <tr><?php foreach (ASSESSMENT_CHANGE_TYPES as $label): ?><th class="n"><?= $e($label) ?></th><?php endforeach; ?></tr>
        <tr><?php foreach (ASSESSMENT_CHANGE_TYPES as $k => $label): ?><td class="n"><?= $s['changes'][$k] ?></td><?php endforeach; ?></tr>
    </table>
</div>
<?php endforeach; ?>
</body>
</html>
<?php
$html = ob_get_clean();

if ($as_pdf) {
    require_once __DIR__ . '/dompdf/autoload.inc.php';
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);          // question images are on Google Drive
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    // Page numbers bottom right, like the NQT report
    $canvas = $dompdf->getCanvas();
    $canvas->page_text($canvas->get_width() - 70, $canvas->get_height() - 22, "Page {PAGE_NUM}", null, 8, [0.2, 0.2, 0.2]);
    $file = preg_replace('/[^A-Za-z0-9_-]+/', '_', $meta['name']) . '_Attempt_' . (int)$meta['attempt_no'] . '_Report.pdf';
    $dompdf->stream($file, ['Attachment' => true]);
    exit();
}
echo $html;
