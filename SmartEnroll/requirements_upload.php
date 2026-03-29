<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$students = [];
$error = '';
$successMessage = $_SESSION['requirements_success'] ?? '';
unset($_SESSION['requirements_success']);
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$totalStudents = 0;
$filteredStudents = 0;
$totalPages = 1;

function format_name(array $row): string
{
    $m = trim((string)($row['learner_mname'] ?? ''));
    $mi = $m !== '' ? strtoupper(mb_substr($m, 0, 1)) . '.' : '';
    $full = trim(
        ($row['learner_lname'] ?? '') . ', ' .
        ($row['learner_fname'] ?? '') . ' ' . $mi
    );

    return trim(preg_replace('/\s+/', ' ', $full), ' ,');
}

try {
    $conn = new mysqli('127.0.0.1', 'root', '', 'smartenroll');
    $conn->set_charset('utf8mb4');

    $searchSql = '';
    $searchTypes = '';
    $searchValues = [];
    if ($search !== '') {
        $searchSql = "WHERE student_id LIKE ? OR learner_lname LIKE ? OR learner_fname LIKE ? OR learner_mname LIKE ? OR grade_level LIKE ? OR school_year LIKE ?";
        $likeSearch = '%' . $search . '%';
        $searchTypes = 'ssssss';
        $searchValues = [$likeSearch, $likeSearch, $likeSearch, $likeSearch, $likeSearch, $likeSearch];
    }

    $totalCountResult = $conn->query("SELECT COUNT(*) AS total FROM enrollments");
    $totalStudents = (int)(($totalCountResult->fetch_assoc()['total'] ?? 0));

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM enrollments
         $searchSql"
    );
    if ($searchValues) {
        $countStmt->bind_param($searchTypes, ...$searchValues);
    }
    $countStmt->execute();
    $filteredStudents = (int)(($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
    $countStmt->close();

    $totalPages = max(1, (int)ceil($filteredStudents / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $studentStmt = $conn->prepare(
        "SELECT id, student_id, learner_lname, learner_fname, learner_mname, grade_level, school_year
         FROM enrollments
         $searchSql
         ORDER BY learner_lname ASC, learner_fname ASC, id DESC
         LIMIT ? OFFSET ?"
    );
    $queryTypes = $searchTypes . 'ii';
    $queryValues = array_merge($searchValues, [$perPage, $offset]);
    $studentStmt->bind_param($queryTypes, ...$queryValues);
    $studentStmt->execute();
    $result = $studentStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $studentStmt->close();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMARTENROLL | Upload Requirements</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/requirements_upload.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="dashboard-page dashboard-white-page">
<main class="dashboard-main requirements-main">
    <section class="requirements-hero">
        <div class="requirements-hero-top">
            <a href="dashboard.php" class="dashboard-link back-left"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="requirements-hero-copy">
                <span class="eyebrow eyebrow-gold">Requirements Desk</span>
                <h1>Upload Requirements</h1>
                <p>Review student records, open one learner, and complete the document checklist in one place.</p>
            </div>
        </div>
        <div class="requirements-hero-stats">
            <div class="hero-stat">
                <span>Total Students</span>
                <strong><?php echo $totalStudents; ?></strong>
            </div>
            <div class="hero-stat">
                <span>Required Files</span>
                <strong>3 Documents</strong>
            </div>
            <div class="hero-stat">
                <span>Submission Status</span>
                <strong>File Monitoring</strong>
            </div>
        </div>
    </section>

    <?php if ($error): ?>
        <div class="requirements-alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="requirements-alert success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <section class="requirements-directory">
        <div class="requirements-directory-head">
            <div>
                <span class="eyebrow eyebrow-blue">Student Directory</span>
                <h2>Choose A Student</h2>
                <p>Open a student record to upload the 2x2 picture, birth certificate, and medical certificate.</p>
            </div>
            <form method="get" action="requirements_upload.php" class="requirements-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input id="requirementsSearch" name="q" type="text" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search student, ID, grade, or school year">
            </form>
        </div>

        <?php if (!$error && empty($students)): ?>
            <div class="requirements-empty">No students found.</div>
        <?php else: ?>
            <div class="requirements-directory-table" id="requirementsStudentList">
                <?php foreach ($students as $student): ?>
                    <?php $fullName = format_name($student) ?: 'N/A'; ?>
                    <a
                        class="requirements-student-row"
                        href="requirements_upload_details.php?student_id=<?php echo urlencode((string)$student['student_id']); ?>"
                    >
                        <span class="student-row-id"><?php echo htmlspecialchars((string)($student['student_id'] ?? '')); ?></span>
                        <strong class="student-row-name"><?php echo htmlspecialchars($fullName); ?></strong>
                        <span class="student-row-grade"><?php echo htmlspecialchars((string)($student['grade_level'] ?? 'N/A')); ?></span>
                        <span class="student-row-year"><?php echo htmlspecialchars((string)($student['school_year'] ?? 'N/A')); ?></span>
                        <span class="requirements-open-btn">View Record</span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$error && $filteredStudents > 0): ?>
                <div class="requirements-pagination">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);
                    $prevQuery = http_build_query(array_filter(['q' => $search, 'page' => $prevPage], static fn($value) => $value !== '' && $value !== null));
                    $nextQuery = http_build_query(array_filter(['q' => $search, 'page' => $nextPage], static fn($value) => $value !== '' && $value !== null));
                    ?>
                    <a class="requirements-page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : 'requirements_upload.php?' . $prevQuery; ?>">Prev</a>
                    <span class="requirements-page-status">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    <a class="requirements-page-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo $page >= $totalPages ? '#' : 'requirements_upload.php?' . $nextQuery; ?>">Next</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<script src="js/requirements_upload.js"></script>
</body>
</html>
