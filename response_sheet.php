<?php
// Response sheet for one attempt (the older "Download" layout from the dashboard): every question
// with its image and options, and a box with type, status, chosen option, correct option and marks.
// response_sheet.php?attempt_id=N shows it; &pdf=1 downloads it as a PDF (dompdf).
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
    exit('Response sheet not found.');
}
$meta = $data['meta'];
$as_pdf = !empty($_GET['pdf']);

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$num = fn($n) => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
$img_url = function (string $url): string {
    if ($url === '') return '';
    if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $m) || preg_match('#/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return "https://drive.google.com/thumbnail?id={$m[1]}&sz=s1000";
    }
    return $url;
};
$status_text = ['correct' => 'Answered', 'incorrect' => 'Answered', 'partial' => 'Answered', 'unanswered' => 'Not Answered', 'marked' => 'Marked For Review'];

// Watermark with the candidate's registration id (or name)
$watermark_text = $meta['REG_ID'] ?: $meta['name'];
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><text x="50%" y="50%" transform="rotate(-30 200 150)" fill="#e2e8f0" font-family="Helvetica, Arial, sans-serif" font-size="28" font-weight="bold" text-anchor="middle">' . $e($watermark_text) . '</text></svg>';
$watermark_b64 = base64_encode($svg);

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Response Sheet - <?= $e($meta['name']) ?> - Attempt <?= (int)$meta['attempt_no'] ?></title>
<style>
    @page { margin: 12mm; }
    body { font-family: 'Helvetica Neue', Helvetica, Arial, 'DejaVu Sans', sans-serif; margin: 0; padding: 10px; font-size: 14px; color: #333;
           background-image: url('data:image/svg+xml;base64,<?= $watermark_b64 ?>'); background-repeat: repeat; }
    body.screen { max-width: 900px; margin: 0 auto; padding-top: 60px; }
    .report-header { text-align: center; margin-bottom: 25px; padding-bottom: 10px; border-bottom: 1px solid #ddd; background-color: rgba(255,255,255,0.8); }
    .report-header h1 { margin: 0 0 5px 0; font-size: 20px; color: #111; }
    .report-header p { margin: 0; font-size: 14px; color: #555; }
    .section-header { color: #888; font-weight: bold; font-size: 14px; margin-bottom: 5px; margin-top: 15px; }
    .q-box { border: 2px solid #aaa; padding: 15px; margin-bottom: 25px; }
    body.screen .q-box { page-break-inside: avoid; }
    .q-table { width: 100%; border-collapse: collapse; }
    .q-num { width: 35px; vertical-align: top; font-weight: bold; font-size: 15px; }
    .q-content { vertical-align: top; }
    .q-content img { max-width: 100%; height: auto; display: block; margin-bottom: 10px; }
    .opt-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .opt-table td { vertical-align: top; padding-bottom: 15px; }
    .opt-img { max-width: 100%; max-height: 220px; height: auto; display: block; }
    .meta-table { border: 2px solid #999; border-radius: 8px; padding: 12px; width: 320px; font-size: 13px; margin-top: 20px; margin-bottom: 5px; }
    .meta-table td { padding: 4px 5px; }
    .meta-label { text-align: right; color: #333; width: 45%; }
    .meta-value { text-align: left; font-weight: bold; padding-left: 8px; width: 55%; color: #111; }
    .toolbar { position: fixed; top: 0; left: 0; right: 0; background: #0b649d; color: #fff; padding: 10px 20px; text-align: right; z-index: 5; font-family: Arial, sans-serif; }
    .toolbar .ttl { float: left; padding-top: 6px; font-weight: bold; }
    .toolbar a, .toolbar button { background: #fff; color: #0b649d; border: 0; border-radius: 4px; padding: 7px 16px; font-weight: bold; font-size: 13px; cursor: pointer; text-decoration: none; margin-left: 8px; }
    @media print { .toolbar { display: none; } body.screen { padding-top: 0; } }
</style>
</head>
<body class="<?= $as_pdf ? 'pdf' : 'screen' ?>">
<?php if (!$as_pdf): ?>
<div class="toolbar">
    <span class="ttl">Response Sheet &middot; Attempt <?= (int)$meta['attempt_no'] ?></span>
    <a href="response_sheet.php?attempt_id=<?= (int)$attempt_id ?>&pdf=1">Download PDF</a>
    <button type="button" onclick="window.print()">Print</button>
</div>
<?php endif; ?>
<div class="report-header">
    <h1>Mock Result: <?= $e($meta['subject_name']) ?> (Set <?= (int)$meta['set_no'] ?>)</h1>
    <p><strong>Candidate:</strong> <?= $e($meta['name']) ?> | <strong>Score:</strong> <?= $e($num($meta['score'])) ?> / <?= $e($num($meta['max'])) ?> | <strong>Attempt:</strong> <?= (int)$meta['attempt_no'] ?></p>
</div>
<?php foreach ($data['questions'] as $q):
    if ($q['type'] === 'CODE') {
        echo coding_report_html($conn, ['id' => $q['id'], 'marks' => $q['marks']], $q['number'], $q['section'], $q['raw']);
        continue;
    }
    $chosen = $q['answered'] ? implode(', ', $q['selected']) : '--';
    if ($q['type'] === 'NAT') {
        $correct = $q['range'][0] == $q['range'][1] ? $num($q['range'][0]) : $num($q['range'][0]) . ' to ' . $num($q['range'][1]);
    } else {
        $correct = implode(', ', array_map('strval', (array)$q['correct']));
    }
    $marks = !$q['answered'] ? '0' : ($q['obtained'] > 0 ? '+' . $num($q['obtained']) : ($q['obtained'] < 0 ? $num($q['obtained']) : '0'));
    $color = $q['result'] === 'correct' ? '#16a34a' : ($q['answered'] ? '#dc2626' : '#111');
    $q_img = $img_url($q['image']);
?>
    <div class="section-header">Section : <?= $e($q['section']) ?></div>
    <div class="q-box">
        <table class="q-table"><tr>
            <td class="q-num">Q.<?= $q['number'] ?></td>
            <td class="q-content"><?php if ($q_img !== ''): ?><img src="<?= $e($q_img) ?>" alt="Question Image"><?php endif; ?></td>
        </tr></table>
        <?php if ($q['type'] !== 'NAT' && !empty($q['options'])): ?>
        <table class="opt-table">
            <?php foreach ($q['options'] as $i => $o):
                $letter = (string)($o['text'] ?? chr(65 + $i));
                $oimg = $img_url((string)($o['image'] ?? '')); ?>
            <tr><td style="width:90px; font-size:15px;"><?= $i === 0 ? '<span style="font-weight:bold;">Options </span>' : '' ?><?= $e($letter) ?>.</td>
                <td class="q-content"><?php if ($oimg !== ''): ?><img src="<?= $e($oimg) ?>" class="opt-img" alt="Option Image"><?php endif; ?></td></tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <table class="meta-table" align="right">
            <tr><td class="meta-label">Question Type :</td><td class="meta-value"><?= $e($q['type']) ?></td></tr>
            <tr><td class="meta-label">Question ID :</td><td class="meta-value"><?= $q['id'] ?></td></tr>
            <tr><td class="meta-label">Status :</td><td class="meta-value"><?= $e($status_text[$q['result']]) ?></td></tr>
            <tr><td class="meta-label">Chosen Option :</td><td class="meta-value"><?= $e($chosen) ?></td></tr>
            <tr><td class="meta-label">Correct Option :</td><td class="meta-value" style="color:#16a34a;"><?= $e($correct) ?></td></tr>
            <tr><td class="meta-label">Marks :</td><td class="meta-value" style="color:<?= $color ?>;"><?= $e($marks) ?></td></tr>
        </table><div style="clear: both;"></div>
    </div>
<?php endforeach; ?>
<center style="margin-top:20px; color:#aaa; font-size:12px; background-color: rgba(255,255,255,0.8); padding: 5px;">Generated by GATE Portal</center>
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
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $canvas = $dompdf->getCanvas();
    $canvas->page_text($canvas->get_width() - 70, $canvas->get_height() - 22, "Page {PAGE_NUM}", null, 8, [0.4, 0.4, 0.4]);
    $file = 'Response_Sheet_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $meta['name']) . '_Attempt_' . (int)$meta['attempt_no'] . '.pdf';
    $dompdf->stream($file, ['Attachment' => true]);
    exit();
}
echo $html;
