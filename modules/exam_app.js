// exam_app.js
// Dedicated module for Exam Logic and Proctoring

const AUTOSAVE_KEY = 'gate_mock_autosave_v1';
const AUTOSAVE_INTERVAL_MS = 7000; 

const questions = window.EXAM_CONFIG.questions;
const totalQuestions = window.EXAM_CONFIG.totalQuestions;
const passages = window.EXAM_CONFIG.passages || {};

// Sections in exam order: questions arrive grouped by section, so each section is one
// contiguous run of question indexes { name, start, end }.
const sections = [];
(questions || []).forEach((q, i) => {
    const name = q.section || 'Questions';
    const last = sections[sections.length - 1];
    if (last && last.name === name) last.end = i;
    else sections.push({ name, start: i, end: i });
});
const sectionOf = (index) => sections.find(s => index >= s.start && index <= s.end) || sections[0];

const escapeHtml = (str) => String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

let testStartTimeString = null; 
const getMysqlTimestamp = () => {
    const now = new Date();
    return now.getFullYear() + '-' +
        String(now.getMonth() + 1).padStart(2, '0') + '-' +
        String(now.getDate()).padStart(2, '0') + ' ' +
        String(now.getHours()).padStart(2, '0') + ':' +
        String(now.getMinutes()).padStart(2, '0') + ':' +
        String(now.getSeconds()).padStart(2, '0');
};

let currentQuestionIndex = 0;
let testState = [];
let questionStartTime = null;
let timeSpentPerQuestion = []; 
let testStartTime = null;      
let testEndTime = null;

let timerSeconds = window.EXAM_CONFIG.durationSeconds;
let timerInterval = null;
let isTestRunning = false;
let autosaveInterval = null;

// Ensure NAT buttons work globally
window.updateNat = (val) => {
    const input = document.getElementById('nat-answer');
    if (!input) return;
    if (val === 'clear') input.value = '';
    else if (val === 'back') input.value = input.value.slice(0, -1);
    else input.value += val;
};

const getSafeImageUrl = (url) => {
    if (!url) return '';
    let fileId = null;
    const idMatch = url.match(/id=([a-zA-Z0-9_-]+)/);
    const fileMatch = url.match(/\/d\/([a-zA-Z0-9_-]+)\/view/);
    if (idMatch && idMatch[1]) { fileId = idMatch[1]; } 
    else if (fileMatch && fileMatch[1]) { fileId = fileMatch[1]; }
    if (fileId) { return `https://drive.google.com/thumbnail?id=${fileId}&sz=s1600`; }
    return url; 
};

const formatTime = (totalSeconds) => {
    const h = String(Math.floor(totalSeconds / 3600)).padStart(2, '0');
    const m = String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, '0');
    const s = String(totalSeconds % 60).padStart(2, '0');
    return `${h}:${m}:${s}`;
};

const startTimer = () => {
    if (timerInterval) clearInterval(timerInterval);
    isTestRunning = true;
    timerInterval = setInterval(() => {
        if (timerSeconds > 0) {
            timerSeconds--;
            document.getElementById('timer-display').textContent = formatTime(timerSeconds);
        } else {
            clearInterval(timerInterval);
            handleTestSubmit();
        }
    }, 1000);
};

const initializeTest = (restore = null) => {
    if (!questions || questions.length === 0) return;
    if (restore) {
        currentQuestionIndex = restore.currentQuestionIndex || 0;
        testState = restore.testState || questions.map(() => ({ answer: null, status: 'not_visited', attempted: false }));
        timerSeconds = (typeof restore.timerSeconds === 'number') ? restore.timerSeconds : window.EXAM_CONFIG.durationSeconds;
        timeSpentPerQuestion = Array.isArray(restore.timeSpentPerQuestion) && restore.timeSpentPerQuestion.length === questions.length
            ? restore.timeSpentPerQuestion : questions.map(() => 0);
        questionStartTime = Date.now();
    } else {
        testState = questions.map(() => ({ answer: null, status: 'not_visited', attempted: false }));
        timeSpentPerQuestion = questions.map(() => 0);
        questionStartTime = Date.now();
        currentQuestionIndex = 0;
        timerSeconds = window.EXAM_CONFIG.durationSeconds;
    }
    renderQuestion();
    renderPalette();
    startTimer();
    document.getElementById('question-count-total').textContent = totalQuestions;
};

const showTest = () => {
    const mainApp = document.getElementById('main-app');
    if (mainApp) mainApp.classList.remove('hidden');
    const saved = localStorage.getItem(AUTOSAVE_KEY);
    if (saved) {
        try {
            const parsed = JSON.parse(saved);
            initializeTest(parsed);
        } catch (e) { initializeTest(); }
    } else { initializeTest(); }
    startAutosave();
};

const renderSectionTabs = () => {
    const tabsEl = document.getElementById('section-tabs');
    if (!tabsEl) return;
    const current = sectionOf(currentQuestionIndex);
    tabsEl.innerHTML = '';
    sections.forEach(sec => {
        const tab = document.createElement('div');
        const active = sec === current;
        tab.className = 'px-4 py-2 text-sm font-bold rounded-t-md border cursor-pointer ' +
            (active ? 'bg-[#287baf] text-white border-[#287baf]' : 'bg-white text-[#287baf] border-gray-300 hover:bg-blue-50');
        tab.textContent = sec.name;
        tab.onclick = () => { if (!active) navigateToQuestion(sec.start); };
        tabsEl.appendChild(tab);
    });
};

// Palette and counts cover the current section only (TCS iON / GATE behaviour)
const renderPalette = () => {
    const paletteEl = document.getElementById('question-palette');
    paletteEl.innerHTML = '';
    const statusCounts = { 'not_visited': 0, 'not_answered': 0, 'marked_for_review': 0, 'answered': 0 };
    const sec = sectionOf(currentQuestionIndex);
    const secNameEl = document.getElementById('palette-section-name');
    if (secNameEl) secNameEl.textContent = sec ? sec.name : '';
    renderSectionTabs();

    questions.forEach((q, index) => {
        if (sec && (index < sec.start || index > sec.end)) return;
        const state = testState[index];
        const status = state.status;
        if (status === 'answered' || status === 'answered_marked_for_review') statusCounts['answered']++;
        else if (status === 'marked_for_review') statusCounts['marked_for_review']++;
        else if (status === 'not_answered') statusCounts['not_answered']++;
        else statusCounts['not_visited']++;

        const span = document.createElement('span');
        span.setAttribute('data-num', index + 1);
        let classList = ['shortcut'];
        if (index === currentQuestionIndex) classList.push('active-ring');
        if (status === 'answered') classList.push('answered', 'up');
        else if (status === 'not_answered') classList.push('unanswered', 'down');
        else if (status === 'marked_for_review') classList.push('review');
        else if (status === 'answered_marked_for_review') classList.push('review', 'answered-marked-dot');
        else classList.push('unvisited', 'up');
        span.className = classList.join(' ');
        span.onclick = () => navigateToQuestion(index);
        paletteEl.appendChild(span);
    });

    document.getElementById('count-answered').textContent = statusCounts.answered;
    document.getElementById('count-marked').textContent = statusCounts.marked_for_review;
    document.getElementById('count-not-answered').textContent = statusCounts.not_answered;
    document.getElementById('count-not-visited').textContent = statusCounts.not_visited;
};

const renderQuestion = () => {
    const q = questions[currentQuestionIndex];
    const state = testState[currentQuestionIndex];
    const optionsContainer = document.getElementById('options-container');
    const marks = parseFloat(q.marks) || 0;
    let negativeMarks = (q.type === 'MCQ') ? (marks / 3).toFixed(2) : "0.00";

    if (document.getElementById('question-number')) document.getElementById('question-number').textContent = currentQuestionIndex + 1;
    if (document.getElementById('question-type-display')) document.getElementById('question-type-display').textContent = q.type === 'CODE' ? 'Coding' : q.type;
    if (document.getElementById('marks-display')) document.getElementById('marks-display').textContent = marks.toFixed(1);
    if (document.getElementById('negative-display')) document.getElementById('negative-display').textContent = negativeMarks;

    if (window.ExamCoding) window.ExamCoding.leave();
    if (q.type === 'CODE' && window.ExamCoding) {
        // Coding question: statement on the left, code editor on the right
        window.ExamCoding.render(q, state, currentQuestionIndex);
        const reviewBtnC = document.getElementById('mark-review-btn');
        if (reviewBtnC) reviewBtnC.textContent = state.status.includes('marked') ? 'Marked for Review' : 'Mark for Review & Next';
        return;
    }

    // Passage questions: passage on the left, question on the right
    const passage = q.passageId ? passages[q.passageId] : null;
    const passagePane = document.getElementById('passage-pane');
    if (passagePane) {
        passagePane.style.display = passage ? '' : 'none';
        if (passage) {
            const content = document.getElementById('passage-content');
            const html = (passage.image ? `<img src="${getSafeImageUrl(passage.image)}" alt="Passage" class="max-w-full h-auto object-contain mb-4">` : '') +
                         (passage.text ? `<div style="white-space: pre-wrap;">${escapeHtml(passage.text)}</div>` : '');
            if (content.dataset.passageId !== String(q.passageId)) {
                content.innerHTML = html;
                content.dataset.passageId = q.passageId;
                passagePane.scrollTop = 0;
            }
        }
    }

    const qTextEl = document.getElementById('question-text');
    // 🔥 FIXED: Added min-width and object-contain to the main question image to prevent it from being unreadably small
    if (qTextEl) qTextEl.innerHTML = `<img src="${getSafeImageUrl(q.imageText)}" alt="Question" class="min-w-[300px] max-w-full h-auto object-contain">`;

    optionsContainer.innerHTML = '';
    
    const antiBrowserAutocompleteName = `q_${currentQuestionIndex}_${Date.now()}`;

    if (q.type === 'NAT') {
        let currentValue = '';
        if (state.answer && state.answer !== null && state.answer[0] !== null && state.answer[0] !== 'null') {
            currentValue = Array.isArray(state.answer) ? state.answer[0] : state.answer;
        }
        optionsContainer.innerHTML = `
            <div class="mt-4 flex flex-col gap-4">
                <div class="flex items-center gap-2">
                    <label class="text-lg text-black font-medium">The output is-</label>
                    <input type="text" id="nat-answer" name="${antiBrowserAutocompleteName}" value="${currentValue}" autocomplete="off" readonly inputmode="none"
                           class="p-1 border-b-2 border-black text-lg focus:outline-none w-48 bg-transparent text-center font-bold relative z-20">
                </div>
                <div class="nat-keyboard relative z-20 mt-2">
                    <div class="nat-row"><div class="nat-btn nat-btn-wide" onclick="updateNat('back')">Backspace</div></div>
                    <div class="nat-row"><div class="nat-btn" onclick="updateNat('7')">7</div><div class="nat-btn" onclick="updateNat('8')">8</div><div class="nat-btn" onclick="updateNat('9')">9</div></div>
                    <div class="nat-row"><div class="nat-btn" onclick="updateNat('4')">4</div><div class="nat-btn" onclick="updateNat('5')">5</div><div class="nat-btn" onclick="updateNat('6')">6</div></div>
                    <div class="nat-row"><div class="nat-btn" onclick="updateNat('1')">1</div><div class="nat-btn" onclick="updateNat('2')">2</div><div class="nat-btn" onclick="updateNat('3')">3</div></div>
                    <div class="nat-row"><div class="nat-btn" onclick="updateNat('0')">0</div><div class="nat-btn" onclick="updateNat('.')">.</div><div class="nat-btn" onclick="updateNat('-')">-</div></div>
                    <div class="nat-row"><div class="nat-btn nat-btn-wide" onclick="updateNat('clear')">Clear All</div></div>
                </div>
            </div>`;
    } else {
        const inputType = (q.type === 'MCQ') ? 'radio' : 'checkbox';
        const currentAnswer = state.answer || [];
        optionsContainer.className = "flex flex-col gap-6 mt-8";
        
        q.options.forEach((option, index) => {
            const isChecked = currentAnswer.includes(option.text);
            const optionId = `option-${currentQuestionIndex}-${index}`;
            
            // 🔥 FIXED: Added smart bounding box classes for option images (min-w-[150px] max-w-[80%] max-h-[250px] object-contain)
            optionsContainer.insertAdjacentHTML('beforeend', `
                <div class="flex items-center gap-4 w-full relative z-20">
                    <input type="${inputType}" id="${optionId}" name="${antiBrowserAutocompleteName}" value="${option.text}" autocomplete="off" class="w-4 h-4 cursor-pointer mt-0.5" ${isChecked ? 'checked' : ''}>
                    <label for="${optionId}" class="flex items-center gap-4 cursor-pointer">
                        ${getSafeImageUrl(option.image) ? `<img src="${getSafeImageUrl(option.image)}" class="min-w-[150px] max-w-[80%] max-h-[250px] h-auto w-auto object-contain">` : `<span class="text-xl text-black tracking-wide">${option.text}</span>`}
                    </label>
                </div>`);
        });
    }
    const reviewBtn = document.getElementById('mark-review-btn');
    if (reviewBtn) reviewBtn.textContent = state.status.includes('marked') ? 'Marked for Review' : 'Mark for Review & Next';
};

const navigateToQuestion = (index) => {
    if (index >= 0 && index < totalQuestions) {
        if (testState[currentQuestionIndex].status === 'not_visited') {
            testState[currentQuestionIndex].status = 'not_answered';
        }

        const now = Date.now();
        if (questionStartTime !== null) { timeSpentPerQuestion[currentQuestionIndex] += Math.floor((now - questionStartTime) / 1000); }
        
        currentQuestionIndex = index;
        questionStartTime = Date.now();
        
        if (testState[currentQuestionIndex].status === 'not_visited') {
            testState[currentQuestionIndex].status = 'not_answered';
        }
        
        renderQuestion();
        renderPalette();
    }
};

const getSelectedAnswer = () => {
    const q = questions[currentQuestionIndex];
    if (q.type === 'CODE') return testState[currentQuestionIndex].answer || null;   // set by Submit Code
    if (q.type === 'NAT') {
        const natInput = document.getElementById('nat-answer');
        const value = natInput ? natInput.value.trim() : '';
        return value !== '' ? [value] : null;
    } else {
        const selectedOptions = Array.from(document.querySelectorAll(`#options-container input:checked`)).map(input => input.value);
        return selectedOptions.length > 0 ? selectedOptions : null;
    }
};

const updateStatus = (answer, statusKey) => {
    const currentState = testState[currentQuestionIndex];
    currentState.answer = answer;
    if (answer !== null) {
        currentState.status = statusKey === 'marked_for_review' ? 'answered_marked_for_review' : 'answered';
        currentState.attempted = true; 
    } else {
        currentState.status = statusKey === 'marked_for_review' ? 'marked_for_review' : 'not_answered';
        currentState.attempted = false; 
    }
};

const saveCurrentAnswersToState = () => {
    if (!questions || questions.length === 0) return;
    const answer = getSelectedAnswer();
    const isActuallyEmpty = (answer === null || (Array.isArray(answer) && answer[0] === ""));
    if (!isActuallyEmpty) {
        testState[currentQuestionIndex].answer = answer;
        testState[currentQuestionIndex].attempted = true;
        if (!testState[currentQuestionIndex].status.includes('marked')) testState[currentQuestionIndex].status = 'answered';
        else if (testState[currentQuestionIndex].status === 'marked_for_review') testState[currentQuestionIndex].status = 'answered_marked_for_review';
    } else {
        testState[currentQuestionIndex].answer = null;
        testState[currentQuestionIndex].attempted = false;
        if (testState[currentQuestionIndex].status === 'answered_marked_for_review') testState[currentQuestionIndex].status = 'marked_for_review';
        else if (testState[currentQuestionIndex].status === 'answered') testState[currentQuestionIndex].status = 'not_answered';
    }
};

const handleSaveAndNext = () => {
    saveCurrentAnswersToState(); 
    trackAnswerChange(currentQuestionIndex);
    updateStatus(testState[currentQuestionIndex].answer, 'answered');
    if (currentQuestionIndex < totalQuestions - 1) navigateToQuestion(currentQuestionIndex + 1);
    else renderPalette();
};

const handleMarkForReview = () => {
    saveCurrentAnswersToState(); 
    trackAnswerChange(currentQuestionIndex);
    if (testState[currentQuestionIndex].answer !== null) updateStatus(testState[currentQuestionIndex].answer, 'marked_for_review');
    else testState[currentQuestionIndex].status = 'marked_for_review';
    if (currentQuestionIndex < totalQuestions - 1) navigateToQuestion(currentQuestionIndex + 1);
    else renderPalette();
};

const handleClearResponse = () => {
    testState[currentQuestionIndex].answer = null;
    testState[currentQuestionIndex].attempted = false; 
    const q = questions[currentQuestionIndex];
    if (q.type === 'CODE') { /* the submission is cleared; the code in the editor stays */ }
    else if (q.type === 'NAT' && document.getElementById('nat-answer')) document.getElementById('nat-answer').value = '';
    else document.querySelectorAll(`#options-container input`).forEach(input => input.checked = false);
    
    if (testState[currentQuestionIndex].status.includes('marked_for_review')) testState[currentQuestionIndex].status = 'marked_for_review';
    else testState[currentQuestionIndex].status = 'not_answered';
    trackAnswerChange(currentQuestionIndex);
    
    renderQuestion();
    renderPalette();
};

// Called by exam_coding.js after "Submit Code": the submission counts as the answer
window.onCodingSubmitted = (index) => {
    const st = testState[index];
    st.attempted = true;
    st.status = st.status.includes('marked') ? 'answered_marked_for_review' : 'answered';
    if (index === currentQuestionIndex) renderPalette();
    performAutosave();
};

// True when an MCQ / MSQ / NAT answer is correct (same rule as the final scoring)
const isAnswerCorrect = (q, answer) => {
    if (answer === null || answer === undefined) return false;
    const userAnswers = Array.isArray(answer) ? answer.map(String) : [String(answer)];
    if (q.type === 'NAT') {
        const userNum = parseFloat(userAnswers[0]);
        return !isNaN(userNum) && userNum >= q.range[0] && userNum <= q.range[1];
    }
    return q.correctAnswer.length === userAnswers.length && q.correctAnswer.every(val => userAnswers.includes(String(val)));
};

// Response change pattern for the report: every time a saved answer changes, remember whether it is
// now Correct, Incorrect or Unanswered. Transitions after the first answer are counted on submit.
const trackAnswerChange = (index) => {
    const q = questions[index];
    const st = testState[index];
    if (!q || q.type === 'CODE') return;
    const ans = st.answer;
    const key = ans === null || ans === undefined ? '' : JSON.stringify(Array.isArray(ans) ? ans.map(String).sort() : [String(ans)]);
    const hist = st.hist || (st.hist = []);
    const last = hist[hist.length - 1];
    if (last ? last.k === key : key === '') return;
    hist.push({ k: key, c: key === '' ? 'U' : (isAnswerCorrect(q, ans) ? 'C' : 'I') });
};

const countAnswerChanges = (st) => {
    const counts = {};
    const hist = st.hist || [];
    for (let i = 1; i < hist.length; i++) {
        const t = hist[i - 1].c + hist[i].c;
        if (t !== 'CC' && t !== 'UU') counts[t] = (counts[t] || 0) + 1;
    }
    return counts;
};

const showSubmitModal = () => {
    saveCurrentAnswersToState();
    trackAnswerChange(currentQuestionIndex);

    let counts = { answered: 0, not_answered: 0, not_visited: 0, marked_for_review: 0, answered_marked: 0 };

    testState.forEach(state => {
        if (state.status === 'answered') counts.answered++;
        else if (state.status === 'not_answered') counts.not_answered++;
        else if (state.status === 'marked_for_review') counts.marked_for_review++;
        else if (state.status === 'answered_marked_for_review') counts.answered_marked++;
        else counts.not_visited++;
    });

    document.getElementById('modal-answered').setAttribute('data-num', counts.answered);
    document.getElementById('modal-not-answered').setAttribute('data-num', counts.not_answered);
    document.getElementById('modal-not-visited').setAttribute('data-num', counts.not_visited);
    document.getElementById('modal-marked').setAttribute('data-num', counts.marked_for_review);
    document.getElementById('modal-answered-marked').setAttribute('data-num', counts.answered_marked);

    document.getElementById('submit-modal-overlay').classList.remove('hidden');
};

const handleTestSubmit = () => {
    if (!isTestRunning) return;
    isTestRunning = false;
    if (timerInterval) clearInterval(timerInterval);
    stopAutosave();
    if (window.FaceProctor) window.FaceProctor.stop();
    const now = Date.now();
    if (questionStartTime !== null) { timeSpentPerQuestion[currentQuestionIndex] += Math.floor((now - questionStartTime) / 1000); }
    
    let score = 0;
    let attemptedCount = 0;
    const totalMarks = questions.reduce((sum, q) => sum + q.marks, 0);

    const detailedDetails = questions.map((q, index) => {
        const state = testState[index];
        state.isCorrect = 'unattempted'; 
        if (state.answer !== null) {
            attemptedCount++;
            const userAnswers = Array.isArray(state.answer) ? state.answer.map(String) : [String(state.answer)];
            let isCorrect = false;
            if (q.type === 'CODE') {
                // Marks in proportion to the test cases the last submission passed; no negative marks
                const a = state.answer || {};
                const earned = a.total ? q.marks * (a.passed || 0) / a.total : 0;
                score += earned;
                state.isCorrect = (a.total && a.passed === a.total) ? 'correct' : 'wrong';
                state.codeMarks = earned;
                if (state.answer) state.answer.timeSpentSec = timeSpentPerQuestion[index] || 0;
                return { question_id: q.id, selected_answer: JSON.stringify(state.answer), is_correct: state.isCorrect === 'correct' ? 1 : 0 };
            }
            if (q.type === 'NAT') {
                const userNum = parseFloat(userAnswers[0]);
                if (!isNaN(userNum) && userNum >= q.range[0] && userNum <= q.range[1]) isCorrect = true;
            } else { 
                isCorrect = q.correctAnswer.length === userAnswers.length && q.correctAnswer.every(val => userAnswers.includes(String(val)));
            }
            if (isCorrect) { score += q.marks; state.isCorrect = 'correct'; } 
            else { state.isCorrect = 'wrong'; if (q.type === 'MCQ') score -= (q.marks / 3); }
        }
        return { question_id: q.id, selected_answer: JSON.stringify(state.answer), is_correct: state.isCorrect === 'correct' ? 1 : 0 };
    });

    testEndTime = Date.now();
    if (!testStartTime) { testStartTime = Date.now(); testStartTimeString = getMysqlTimestamp(); }
    const totalTimeSpentSec = Math.floor((testEndTime - testStartTime) / 1000);

    // Extra per-question data for the report: order, time spent, final status, response changes
    detailedDetails.forEach((d, index) => {
        d.q_order = index + 1;
        d.time_spent = timeSpentPerQuestion[index] || 0;
        d.q_status = testState[index].status;
        d.changes = countAnswerChanges(testState[index]);
    });

    submitPayload = {
        action: 'submit', subject_id: window.EXAM_CONFIG.subjectId, set_no: window.EXAM_CONFIG.setNo,
        score: score.toFixed(2), total_marks: totalMarks.toFixed(2), attempted: attemptedCount,
        correct: questions.filter((q, i) => testState[i].isCorrect === 'correct').length,
        wrong: questions.filter((q, i) => testState[i].isCorrect === 'wrong').length,
        start_time: testStartTimeString, test_duration_minutes: window.EXAM_CONFIG.durationMinutes,
        time_taken_seconds: totalTimeSpentSec, details: detailedDetails
    };
    SubmitFlow.hideConfirm();
    sendSubmission();
};

// ==========================================
// SUBMIT FLOW (TCS iON NQT style)
// Submit -> summary -> "about to be submitted" OK / Cancel -> saved -> Info popup ->
// "Exit Assessment" page -> the assessment page with My Attempts and the report.
// ==========================================
let submitPayload = null;

const sendSubmission = () => {
    SubmitFlow.showSaving();
    fetch('save_progress.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(submitPayload)
    })
    .then(r => r.json())
    .then(res => {
        if (!res || !res.success) throw new Error((res && (res.message || res.error)) || 'Not saved');
        localStorage.removeItem(AUTOSAVE_KEY);
        SubmitFlow.showInfo();
    })
    .catch(err => {
        console.error('Submit failed', err);
        SubmitFlow.showError();
    });
};

const SubmitFlow = {
    el: (id) => document.getElementById(id),
    show(id) { const e = this.el(id); if (e) e.classList.remove('hidden'); },
    hide(id) { const e = this.el(id); if (e) e.classList.add('hidden'); },
    showConfirm() { this.show('nqt-confirm'); },
    hideConfirm() { this.hide('nqt-confirm'); },
    showSaving() {
        const main = this.el('main-app');
        if (main) main.classList.add('hidden');
        this.show('nqt-blank');
        this.hide('nqt-info');
        this.show('nqt-saving');
    },
    showInfo() {
        this.hide('nqt-saving');
        this.el('nqt-info-text').textContent = 'Dear Candidate, All the responses provided by you are saved in the system and the assessment has been submitted successfully.';
        this.el('nqt-info-ok').textContent = 'OK';
        this.el('nqt-info-ok').onclick = () => this.showDone();
        this.el('nqt-info-close').onclick = () => this.showDone();
        this.show('nqt-info');
    },
    showError() {
        this.hide('nqt-saving');
        this.el('nqt-info-text').textContent = 'Dear Candidate, your responses could not be sent to the server. Please check your internet connection and click on \'Retry\'. Do not close this window.';
        this.el('nqt-info-ok').textContent = 'Retry';
        this.el('nqt-info-ok').onclick = () => sendSubmission();
        this.el('nqt-info-close').onclick = () => sendSubmission();
        this.show('nqt-info');
    },
    showDone() {
        this.hide('nqt-info');
        this.hide('nqt-blank');
        this.show('nqt-done');
    },
    exit() {
        const url = 'assessment.php?set_no=' + encodeURIComponent(window.EXAM_CONFIG.setNo + '|' + window.EXAM_CONFIG.subjectId);
        try { if (document.fullscreenElement) document.exitFullscreen(); } catch (e) {}
        // The exam runs in its own window opened by instructions.php: show the attempts page
        // in that original window and close this one, like "close this window" on TCS iON.
        let usedOpener = false;
        try {
            if (window.opener && !window.opener.closed) {
                window.opener.location.href = url;
                usedOpener = true;
            }
        } catch (e) {}
        if (usedOpener) {
            window.close();
            setTimeout(() => { window.location.href = url; }, 400);   // if the browser refuses to close it
        } else {
            window.location.href = url;
        }
    }
};

const performAutosave = (isFinal=false) => {
    const payload = { currentQuestionIndex, timerSeconds, testState, timeSpentPerQuestion };
    try { localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(payload)); } catch (e) {}
    if (navigator.onLine) {
        fetch('save_progress.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'autosave', payload })
        }).catch(()=>{});
    }
};

const startAutosave = () => {
    autosaveInterval = setInterval(() => performAutosave(), AUTOSAVE_INTERVAL_MS);
    window.addEventListener('beforeunload', () => performAutosave(true));
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden' && isTestRunning) performAutosave(true); });
};

const stopAutosave = () => { if (autosaveInterval) clearInterval(autosaveInterval); };

// ==========================================
// EVENT LISTENERS & ANTI-CHEAT
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('focusin', (e) => { if (e.target.id === 'nat-answer') { e.target.readOnly = true; } });

    const triggerViolation = (reason) => {
        if (isTestRunning) {
            performAutosave(true); 
            window.location.replace("ErrorPage.php"); 
        }
    };

    window.addEventListener('blur', () => triggerViolation("Window lost focus."));
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden' && isTestRunning) triggerViolation("Tab hidden."); });
    document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement && isTestRunning) triggerViolation("Exited full screen."); });

    ['keydown', 'keyup', 'keypress'].forEach(function(eventType) {
        window.addEventListener(eventType, function(e) {
            if (isTestRunning) {
                if (e.key === 'F5' || (e.ctrlKey && (e.key === 'r' || e.key === 'R'))) triggerViolation("Page refresh."); 
                else if (e.key === 'Escape') triggerViolation("Escape key pressed."); 
                else if (e.altKey || e.metaKey) triggerViolation("Special key pressed.");
                // Typing is allowed only in the coding editor and its custom-input box
                else if (e.target && e.target.classList && e.target.classList.contains('code-input')
                         && !(e.ctrlKey && !'acvxyz'.includes(String(e.key).toLowerCase()))) return;
            }
            e.preventDefault(); e.stopPropagation(); return false;
        }, true); 
    });

    document.addEventListener('contextmenu', (e) => { if (isTestRunning) e.preventDefault(); });

    if (window.EXAM_CONFIG.isSetSelected) {
        const beginExam = () => {
            testStartTime = Date.now();
            testStartTimeString = getMysqlTimestamp();
            showTest();
        };
        if (window.FaceProctor) {
            // Face verification must pass before the timer starts
            window.FaceProctor.start({
                subjectId: window.EXAM_CONFIG.subjectId,
                setNo: window.EXAM_CONFIG.setNo,
                onViolation: (reason) => triggerViolation(reason)
            }).then(beginExam);
        } else {
            beginExam();
        }
    }

    if (document.getElementById('clear-btn')) document.getElementById('clear-btn').onclick = handleClearResponse;
    if (document.getElementById('save-next-btn')) document.getElementById('save-next-btn').onclick = handleSaveAndNext;
    if (document.getElementById('mark-review-btn')) document.getElementById('mark-review-btn').onclick = handleMarkForReview;
    
    if (document.getElementById('submit-btn-footer')) {
        document.getElementById('submit-btn-footer').onclick = showSubmitModal;
    }
    if (document.getElementById('cancel-submit-btn')) {
        document.getElementById('cancel-submit-btn').onclick = () => {
            document.getElementById('submit-modal-overlay').classList.add('hidden');
        };
    }
    if (document.getElementById('confirm-submit-btn')) {
        document.getElementById('confirm-submit-btn').onclick = () => {
            document.getElementById('submit-modal-overlay').classList.add('hidden');
            SubmitFlow.showConfirm();
        };
    }
    if (document.getElementById('nqt-confirm-ok')) document.getElementById('nqt-confirm-ok').onclick = () => handleTestSubmit();
    if (document.getElementById('nqt-confirm-cancel')) document.getElementById('nqt-confirm-cancel').onclick = () => SubmitFlow.hideConfirm();
    if (document.getElementById('nqt-exit-btn')) document.getElementById('nqt-exit-btn').onclick = () => SubmitFlow.exit();
});